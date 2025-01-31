<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Security\RoleHierarchy;
use Symfony\Component\HttpFoundation\Response;

class ClientService
{
    public const HTTP_CONNECT_TIMEOUT = 1;
    public const HTTP_TIMEOUT = 10;

    private $host;
    private $username;
    private $password;
    private $player;
    private $scheduler;
    private $dashboard;
    private $adminUrl;
    private $deleteArchiveMediaPackage;
    private $deletionWorkflowName;
    private $manageOpencastUsers;
    private $insecure;
    private $logger;
    private $roleHierarchy;

    public function __construct(
        string $host = '',
        string $username = '',
        string $password = '',
        string $player = '/engage/ui/watch.html',
        string $scheduler = '/admin/index.html#/recordings',
        string $dashboard = '/dashboard/index.html',
        bool $deleteArchiveMediaPackage = false,
        string $deletionWorkflowName = 'delete-archive',
        bool $manageOpencastUsers = false,
        bool $insecure = false,
        ?string $adminUrl = null,
        ?LoggerInterface $logger = null,
        ?RoleHierarchy $roleHierarchy = null
    ) {
        $this->logger = $logger;

        if (!function_exists('curl_init')) {
            $this->logger->error(self::class.'['.__FUNCTION__.'](line '.__LINE__
                                    .') The function "curl_init" does not exist. '
                                    .'Curl is required to execute remote commands.');

            throw new \RuntimeException('Curl is required to execute remote commands.');
        }

        $this->host = ('/' === substr($host, -1)) ? substr($host, 0, -1) : $host;
        $this->username = $username;
        $this->password = $password;
        $this->player = $player;
        $this->scheduler = $scheduler;
        $this->dashboard = $dashboard;
        $this->deleteArchiveMediaPackage = $deleteArchiveMediaPackage;
        $this->deletionWorkflowName = $deletionWorkflowName;
        $this->manageOpencastUsers = $manageOpencastUsers;
        $this->insecure = $insecure;
        $this->adminUrl = $adminUrl;
        $this->roleHierarchy = $roleHierarchy;
    }

    public function getUrl(): string
    {
        return $this->host;
    }

    public function getPlayerUrl(): string
    {
        return (0 === strpos($this->player, '/')) ? $this->host.$this->player : $this->player;
    }

    public function getAdminUrl(): ?string
    {
        if (null !== $this->adminUrl) {
            return $this->adminUrl;
        }

        $output = $this->request('/info/components.json');
        $decode = $this->decodeJson($output['var']);

        if (isset($decode['admin'])
            && filter_var($decode['admin'], FILTER_VALIDATE_URL)) {
            $this->adminUrl = $decode['admin'];
        }

        return $this->adminUrl;
    }

    public function getSchedulerUrl(): string
    {
        return (0 === strpos($this->scheduler, '/')) ? $this->getAdminUrl().$this->scheduler : $this->scheduler;
    }

    public function getDashboardUrl(): string
    {
        return (0 === strpos($this->dashboard, '/')) ? $this->getAdminUrl().$this->dashboard : $this->dashboard;
    }

    public function getMediaPackages(string $query, int $limit, int $offset)
    {
        $output = $this->request('/search/episode.json?'.($query ? 'q='.urlencode($query).'&' : '').'limit='.$limit.'&offset='.$offset);

        if (200 !== $output['status']) {
            return false;
        }
        $decode = $this->decodeJson($output['var']);

        $return = [0, []];

        if (0 === (int) $decode['search-results']['total']) {
            return $return;
        }

        $return[0] = $decode['search-results']['total'];
        if (isset($decode['search-results']['result'][0])) {
            foreach ($decode['search-results']['result'] as $media) {
                $return[1][] = $media['mediapackage'];
            }
        } else {
            $return[1][] = $decode['search-results']['result']['mediapackage'];
        }

        return $return;
    }

    public function getMediaPackage(string $id)
    {
        $output = $this->request('/search/episode.json?id='.$id);

        if (200 !== $output['status']) {
            return null;
        }
        $decode = $this->decodeJson($output['var']);

        if (0 === (int) $decode['search-results']['total']) {
            return null;
        }
        if (isset($decode['search-results']['result'][0])) {
            return $decode['search-results']['result'][0]['mediapackage'];
        }

        return $decode['search-results']['result']['mediapackage'];
    }

    public function getFullMediapackage(string $id)
    {
        $output = $this->request('/search/episode.json?id='.$id);

        if (200 !== $output['status']) {
            return false;
        }
        $decode = $this->decodeJson($output['var']);

        if (0 === (int) $decode['search-results']['total']) {
            return false;
        }
        if (isset($decode['search-results']['result'][0])) {
            return $decode['search-results']['result'][0];
        }

        return $decode['search-results']['result'];
    }

    public function getMasterMediaPackage(string $id): ?array
    {
        $version = $this->getOpencastVersion();

        if ($version >= '3.0.0') {
            return $this->getMediaPackageFromAssets($id);
        }

        if ($version >= '2.0.0') {
            return $this->getMediaPackageFromArchive($id);
        }

        if ($version >= '1.4.0' && $version < '1.7.0') {
            return $this->getMediaPackageFromArchive($id);
        }

        if (0 === strpos($version, '1.2')) {
            return $this->getMediaPackageFromWorkflow($id);
        }

        throw new \Exception('There is no case for this version of Opencast ('.$version.')');
    }

    public function getMediaPackageFromWorkflow(string $id): ?array
    {
        $output = $this->request('/workflow/instances.json?state=SUCCEEDED&mp='.$id, [], 'GET', true);
        if (Response::HTTP_OK === (int) $output['status']) {
            $decode = $this->decodeJson($output['var']);

            if (isset($decode['workflows']['workflow']['mediapackage'])) {
                return $decode['workflows']['workflow']['mediapackage'];
            }

            if (isset($decode['workflows']['workflow'][0]['mediapackage'])) {
                return $decode['workflows']['workflow'][0]['mediapackage'];
            }
        }

        return null;
    }

    public function getMediaPackageFromAssets(string $id): ?array
    {
        $output = $this->request('/assets/episode/'.$id, [], 'GET', true);
        if (Response::HTTP_OK === (int) $output['status']) {
            return $this->decodeXML($output);
        }

        return null;
    }

    public function getMediaPackageFromArchive(string $id)
    {
        // NOTE: BC for OC 1.4 to 1.6
        $output = $this->request('/episode/episode.json?id='.$id, [], 'GET', true);
        // NOTE: When the above url returns 404, THIS ALWAYS FAILS!! Since it's a GET request, the request() function throws an exception, and the lines below are never executed
        // In other words, we gotta do a try {} catch if we wanted to support OC 2.x
        if (Response::HTTP_OK !== $output['status']) {
            // NOTE: BC for OC 2.x
            $output = $this->request('/archive/episode.json?id='.$id, [], 'GET', true);
            if (Response::HTTP_OK !== $output['status']) {
                return false;
            }
        }

        $decode = $this->decodeJson($output['var']);

        if (0 === (int) $decode['search-results']['total']) {
            return false;
        }
        if ($decode['search-results']['limit'] > 1) {
            return $decode['search-results']['result'][0]['mediapackage'];
        }

        return $decode['search-results']['result']['mediapackage'];
    }

    public function applyWorkflowToMediaPackages(array $mediaPackagesIds = [], string $workflowName = ''): bool
    {
        if (!$workflowName || ($workflowName === $this->deletionWorkflowName)) {
            $workflowName = $this->deletionWorkflowName;
            if (!$this->deleteArchiveMediaPackage) {
                throw new \Exception('Not allowed to delete media packages from archive');
            }
        }

        if (!$mediaPackagesIds) {
            throw new \Exception('No media packages given.');
        }

        $request = '/admin-ng/tasks/new';
        $opencastVersion = $this->getOpencastVersion();
        $configurationParameters = [
            'retractFromEngage' => 'true',
            'retractFromAws' => 'false',
            'retractFromApi' => 'true',
            'retractPreview' => 'true',
            'retractFromOaiPmh' => 'true',
            'retractFromYouTube' => 'false',
        ];

        // SUPPORT FOR OPENCAST < 2.0
        if ($opencastVersion < '2.0.0') {
            $request = '/episode/apply/'.$workflowName;

            $mediaPackageIdsParameter = '';
            foreach ($mediaPackagesIds as $index => $id) {
                $mediaPackageIdsParameter .= $id;
                if ($index < (count($mediaPackagesIds) - 1)) {
                    $mediaPackageIdsParameter .= ',+';
                }
            }
            $parameters = [
                'mediaPackageIds' => $mediaPackageIdsParameter,
                'engage' => 'Matterhorn+Engage+Player',
            ];
        // SUPPORT FOR OPENCAST < 6.0
        } elseif ($opencastVersion < '6.0.0') {
            $parameters = [
                'metadata' => json_encode(
                    [
                        'workflow' => $workflowName,
                        'configuration' => $configurationParameters,
                        'eventIds' => $mediaPackagesIds,
                    ],
                    JSON_THROW_ON_ERROR
                ),
            ];
        // DEFAULT
        } else {
            $configurationsById = [];
            foreach ($mediaPackagesIds as $mediaPackageId) {
                $configurationsById[$mediaPackageId] = $configurationParameters;
            }
            $parameters = [
                'metadata' => json_encode(
                    [
                        'workflow' => $workflowName,
                        'configuration' => $configurationsById,
                    ],
                    JSON_THROW_ON_ERROR
                ),
            ];
        }

        $output = $this->request($request, $parameters, 'POST', true);

        if (!in_array((int) $output['status'], [
            Response::HTTP_CREATED,
            Response::HTTP_NO_CONTENT,
        ], true)) {
            $this->logger->error(self::class.'['.__FUNCTION__.'](line '.__LINE__.') Opencast error. Status != 204. - error: '.$output['error'].' - var: '.$output['var'].' - status: '.$output['status'].' - params:'.json_encode($parameters, JSON_THROW_ON_ERROR));

            return false;
        }

        return true;
    }

    public function getWorkflowStatistics()
    {
        $request = '/workflow/statistics.json';

        $output = $this->request($request, [], 'GET', true);

        if (Response::HTTP_OK !== $output['status']) {
            return false;
        }

        return $this->decodeJson($output['var']);
    }

    public function getCountedWorkflowInstances(string $id = '', string $count = '', string $workflowName = '')
    {
        $request = '/workflow/instances.json?state=SUCCEEDED'.($workflowName ? '&workflowdefinition='.$workflowName : '').($id ? '&mp='.$id : '').($count ? '&count='.$count : '');

        $output = $this->request($request, [], 'GET', true);

        if (Response::HTTP_OK !== $output['status']) {
            return false;
        }

        return $this->decodeJson($output['var']);
    }

    public function stopWorkflow(array $workflow = []): bool
    {
        if ($this->deleteArchiveMediaPackage && isset($workflow['id'])) {
            $request = '/workflow/stop';
            $params = ['id' => $workflow['id']];
            $output = $this->request($request, $params, 'POST', true);

            return !(Response::HTTP_OK !== $output['status']);
        }

        return false;
    }

    public function createUser(User $user): bool
    {
        if ($this->manageOpencastUsers) {
            $request = '/user-utils/';
            $roles = $this->getUserRoles($user);
            $params = [
                'username' => $user->getUsername(),
                'password' => 'pumukit',
                'roles' => $roles,
            ];
            $output = $this->request($request, $params, 'POST', true);
            if (Response::HTTP_CREATED !== (int) $output['status']) {
                if (Response::HTTP_CONFLICT === (int) $output['status']) {
                    throw new \Exception('Conflict '.$output['status'].'. An user with this username "'.$user->getUsername().'" already exist.', 1);
                }

                throw new \Exception('Error '.$output['status'].' Processing Request on Creating User "'.$user->getUsername().'"', 1);
            }

            return true;
        }

        return false;
    }

    public function updateUser(User $user): bool
    {
        if ($this->manageOpencastUsers) {
            $request = '/user-utils/'.$user->getUsername().'.json';
            $roles = $this->getUserRoles($user);
            $params = [
                'username' => $user->getUsername(),
                'password' => 'pumukit',
                'roles' => $roles,
            ];
            $output = $this->request($request, $params, 'PUT', true);
            if (Response::HTTP_OK !== (int) $output['status']) {
                if (Response::HTTP_NOT_FOUND === (int) $output['status']) {
                    throw new \Exception('Error '.$output['status'].'. User with this username "'.$user->getUsername().'" not found.', 1);
                }

                throw new \Exception('Error '.$output['status'].' Processing Request on Updating User "'.$user->getUsername().'"', 1);
            }

            return true;
        }

        return false;
    }

    public function deleteUser(User $user): bool
    {
        if ($this->manageOpencastUsers) {
            $request = '/user-utils/'.$user->getUsername().'.json';
            $output = $this->request($request, '', 'DELETE', true);
            if (Response::HTTP_OK !== (int) $output['status']) {
                if (Response::HTTP_NOT_FOUND === (int) $output['status']) {
                    throw new \Exception('Error '.$output['status'].'. User with this username "'.$user->getUsername().'" not found.', 1);
                }

                throw new \Exception('Error '.$output['status'].' Processing Request on Deleting User "'.$user->getUsername().'"', 1);
            }

            return true;
        }

        return false;
    }

    public function updateOpencastSeries(Series $series): array
    {
        $seriesOpencastId = $series->getProperty('opencast');
        if (null === $seriesOpencastId) {
            throw new \Exception('Error trying to update an Opencast series. Error: No opencast ID', 404);
        }
        $metadata = [
            [
                'id' => 'title',
                'value' => $series->getTitle(),
            ],
            [
                'id' => 'description',
                'value' => $series->getDescription(),
            ],
        ];
        // There is an Opencast API error. The 'type' parameter should be taken from the form,
        //  but it is taken from the query. Added 'type' in both ways for good measure.
        $type = 'dublincore/series';
        $params = [
            'metadata' => json_encode($metadata),
            'type' => $type,
        ];
        $requestUrl = "/api/series/{$seriesOpencastId}/metadata";
        $requestUrl .= "?type={$type}";
        $output = $this->request($requestUrl, $params, 'PUT', true);
        if (Response::HTTP_OK !== (int) $output['status']) {
            throw new \Exception('Error trying to update an Opencast series metadata. Error '.$output['status'].':  '.$output['error'].' : '.$output['var'], $output['status']);
        }

        return $output;
    }

    public function createOpencastSeries(Series $series): array
    {
        $metadata = [
            [
                'flavor' => 'dublincore/series',
                'fields' => [
                    [
                        'id' => 'title',
                        'value' => $series->getTitle(),
                    ],
                    [
                        'id' => 'description',
                        'value' => $series->getDescription(),
                    ],
                ],
            ],
        ];
        $acl = [];
        $params = [
            'metadata' => json_encode($metadata),
            'acl' => json_encode($acl),
        ];
        $requestUrl = '/api/series';
        $output = $this->request($requestUrl, $params, 'POST', true);
        if (Response::HTTP_CREATED !== (int) $output['status']) {
            throw new \Exception('Error trying to create an Opencast series. Error '.$output['status'].':  "'.$output['error'].' : '.$output['var'], $output['status']);
        }

        return $output;
    }

    public function deleteOpencastSeries(Series $series): ?array
    {
        $seriesOpencastId = $series->getProperty('opencast');
        if (null === $seriesOpencastId) {
            return null;
        }
        $requestUrl = "/api/series/{$seriesOpencastId}";
        $output = $this->request($requestUrl, [], 'DELETE', true);
        if (Response::HTTP_NO_CONTENT !== (int) $output['status']) {
            throw new \Exception('Error trying to delete an Opencast series. Error '.$output['status'].':  "'.$output['error'].' : '.$output['var'], $output['status']);
        }

        return $output;
    }

    public function getOpencastSeries($series)
    {
        $seriesOpencastId = $series->getProperty('opencast');
        if (null === $seriesOpencastId) {
            return null;
        }
        $requestUrl = "/api/series/{$seriesOpencastId}";

        try {
            $output = $this->request($requestUrl, [], 'GET', true);
        } catch (\Exception $e) {
            return null;
        }

        return $output;
    }

    public function getSpatialField(string $url)
    {
        if (0 === strpos($url, $this->host)) {
            $path = parse_url($url, PHP_URL_PATH);
            if (!$path) {
                return null;
            }
            $response = $this->request($path);
        } else {
            if ($this->insecure) {
                $dargs = [
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ];
                $response = ['var' => file_get_contents($url, false, stream_context_create($dargs))];
            } else {
                $response = ['var' => file_get_contents($url)];
            }
        }

        $start = strrpos($response['var'], '<dcterms:spatial>');
        $end = strrpos($response['var'], '</dcterms:spatial>');

        if ((false !== $start) && (false !== $end)) {
            $start += strlen('<dcterms:spatial>');

            return substr($response['var'], $start, $end - $start);
        }

        return null;
    }

    public function removeEvent(string $id): void
    {
        $opencastVersion = $this->getOpencastVersion();
        if (version_compare($opencastVersion, '9.0.0', '<')) {
            $output = $this->request('/admin-ng/event/'.$id, [], 'DELETE', true);
            if (!$output) {
                throw new \Exception("Can't access to admin-ng/event");
            }
        } else {
            $output = $this->request('/api/events/'.$id, [], 'DELETE', true);

            if (!in_array((int) $output['status'], [
                Response::HTTP_ACCEPTED,
                Response::HTTP_NO_CONTENT,
            ], true)) {
                $this->logger->error(self::class.'['.__FUNCTION__.'](line '.__LINE__.') Opencast error. Status != 204. - error: '.$output['error'].' - var: '.$output['var'].' - status: '.$output['status']);

                throw new \Exception("Can't access to api/events");
            }
        }
    }

    public function getOpencastVersion()
    {
        $output = $this->request('/info/components.json');
        if (!$output) {
            throw new \Exception("Can't access to /info/components.json");
        }
        $decode = $this->decodeJson($output['var']);
        if (isset($decode['rest'][0]['version'])) {
            return $decode['rest'][0]['version'];
        }

        throw new \Exception("Cant't recognize ['rest'][0]['version'] from /info/components.json");
    }

    public function getGalicasterProperties(string $id, int $mpVersion = 1): array
    {
        $url = sprintf('/assets/assets/%s/galicaster-properties/%d/galicaster.json', $id, $mpVersion);

        return $this->getGalicasterPropertiesFromUrl($url);
    }

    public function getGalicasterPropertiesFromUrl(string $url): array
    {
        $url = parse_url($url, PHP_URL_PATH);

        try {
            $output = $this->request($url, [], 'GET', true);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('Error processing request to get galicaster-properties: %s | Not setting Galicaster properties.', $e->getMessage()));

            return [];
        }
        if (!$output) {
            $this->logger->warning(sprintf('Url for galicaster properties returned an empty response: %s', $url));

            return [];
        }

        return $this->decodeJson($output['var']);
    }

    private function request(string $path, $params = [], string $method = 'GET', bool $useAdminUrl = false): array
    {
        if ($useAdminUrl) {
            $requestUrl = $this->getAdminUrl().$path;
        } else {
            $requestUrl = $this->host.$path;
        }

        $fields = is_array($params) ? http_build_query($params) : $params;

        $header = ['X-Requested-Auth: Digest',
            'X-Opencast-Matterhorn-Authorization: true', ];

        $this->logger->debug(self::class.'['.__FUNCTION__.'](line '.__LINE__
                                .') Requested URL "'.$requestUrl.'" '
                                .'with method "'.$method.'" '
                                .'and params: '.$fields);

        if (false === $request = curl_init($requestUrl)) {
            $this->logger->error(self::class.'['.__FUNCTION__.'](line '.__LINE__
                                    .') Unable to create a new curl handle with URL: '.$requestUrl.'.');

            throw new \RuntimeException('Unable to create a new curl handle with URL: '.$requestUrl.'.');
        }

        switch ($method) {
            case 'GET':
                break;

            case 'POST':
                curl_setopt($request, CURLOPT_POST, 1);
                curl_setopt($request, CURLOPT_POSTFIELDS, $fields);

                break;

            case 'PUT':
                $header[] = 'Content-Length: '.strlen($fields);
                curl_setopt($request, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($request, CURLOPT_POSTFIELDS, $fields);

                break;

            case 'DELETE':
                curl_setopt($request, CURLOPT_CUSTOMREQUEST, 'DELETE');

                break;

            default:
                throw new \Exception('Method "'.$method.'" not allowed.');
        }

        // NOTE: use - curl_setopt($request, CURLOPT_VERBOSE, true); to debug

        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($request, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($request, CURLOPT_CONNECTTIMEOUT, self::HTTP_CONNECT_TIMEOUT);
        curl_setopt($request, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT);

        if ($this->insecure) {
            curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);
        }

        if ('' !== $this->username) {
            curl_setopt($request, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($request, CURLOPT_USERPWD, $this->username.':'.$this->password);
            curl_setopt($request, CURLOPT_HTTPHEADER, $header);
        }

        $output = [];
        $output['var'] = curl_exec($request);
        $output['error'] = curl_error($request);
        $output['status'] = curl_getinfo($request, CURLINFO_HTTP_CODE);

        curl_close($request);

        if (('GET' === $method) && Response::HTTP_OK !== (int) $output['status']) {
            $this->logger->error(self::class.'['.__FUNCTION__.'](line '.__LINE__.') Error '.$output['error'].' Status '.$output['status'].' Processing Request : '.$requestUrl.'.');

            throw new \Exception(sprintf('Error "%s", Status %s, Processing Request "%s"', $output['error'], $output['status'], $requestUrl), 1);
        }

        return $output;
    }

    private function decodeJson(string $jsonString = ''): array
    {
        $decode = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decode)) {
            throw new \Exception('Opencast communication error');
        }

        return $decode;
    }

    private function decodeXML(array $xmlString = []): array
    {
        $decode = null;
        if (is_array($xmlString)) {
            $xml = simplexml_load_string($xmlString['var'], 'SimpleXMLElement', LIBXML_NOCDATA);
            $json = json_encode($xml, JSON_THROW_ON_ERROR);
            $decode = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        if (!is_array($decode)) {
            throw new \Exception('Opencast communication error');
        }

        return $decode;
    }

    private function getUserRoles(User $user): string
    {
        if ($this->roleHierarchy) {
            $roles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());
        } else {
            $roles = $user->getRoles();
        }

        return '["'.implode('","', $roles).'"]';
    }
}
