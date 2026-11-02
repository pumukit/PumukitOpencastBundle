<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Api;

use Pumukit\OpencastBundle\Client\OpencastHttpClient;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\Exception\OpencastNotFoundException;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;

class Opencast16Api implements OpencastApiInterface
{
    public const MINIMUM_VERSION = '16.0.0';
    private const EVENTS_PAGE = 100;
    private const SERIES_FLAVOR = 'dublincore/series';
    private const ENGAGE_CHANNEL = 'engage-player';

    public function supports(string $version): bool
    {
        return version_compare($version, self::MINIMUM_VERSION, '>=');
    }

    public function searchMediaPackages(OpencastHttpClient $http, string $query, int $limit, int $offset): SearchResult
    {
        $data = $http->get('/search/episode.json', ['q' => $query, 'limit' => $limit, 'offset' => $offset, 'sort' => 'modified desc'])->expect(200)->json();

        $mediaPackages = [];
        foreach (MediaPackage::nodes($data['result'] ?? null) as $result) {
            if (isset($result['mediapackage']) && is_array($result['mediapackage'])) {
                $mediaPackages[] = MediaPackage::fromArray($result['mediapackage'])->withModified($result['modified'] ?? null);
            }
        }

        return new SearchResult((int) ($data['total'] ?? count($mediaPackages)), $mediaPackages);
    }

    public function getMediaPackage(OpencastHttpClient $http, string $id): ?MediaPackage
    {
        $data = $http->get('/search/episode.json', ['id' => $id])->expect(200)->json();
        $result = MediaPackage::nodes($data['result'] ?? null)[0] ?? null;

        if (!isset($result['mediapackage']) || !is_array($result['mediapackage'])) {
            return null;
        }

        return MediaPackage::fromArray($result['mediapackage'])->withModified($result['modified'] ?? null);
    }

    public function getArchivedMediaPackage(OpencastHttpClient $http, string $id): ?MediaPackage
    {
        $response = $http->get('/assets/episode/'.rawurlencode($id), [], true);
        if (404 === $response->status) {
            return null;
        }

        return MediaPackage::fromArray($response->expect(200)->xml());
    }

    public function deleteEvent(OpencastHttpClient $http, string $id): void
    {
        $response = $http->delete('/api/events/'.rawurlencode($id), true);
        if (404 === $response->status) {
            throw new OpencastNotFoundException(sprintf('Opencast event "%s" not found', $id), 404);
        }
        $response->expect(202, 204);
    }

    public function getSeries(OpencastHttpClient $http, string $id): ?array
    {
        $response = $http->get('/api/series/'.rawurlencode($id), [], true);
        if (404 === $response->status) {
            return null;
        }

        return $response->expect(200)->json();
    }

    public function createSeries(OpencastHttpClient $http, string $title, string $description): string
    {
        $metadata = [['flavor' => self::SERIES_FLAVOR, 'fields' => $this->seriesFields($title, $description)]];
        $data = $http->post('/api/series', ['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'acl' => '[]'], true)->expect(201)->json();

        if (empty($data['identifier'])) {
            throw new OpencastException('Opencast did not return the identifier of the new series');
        }

        return (string) $data['identifier'];
    }

    public function updateSeries(OpencastHttpClient $http, string $id, string $title, string $description): void
    {
        $response = $http->put(
            '/api/series/'.rawurlencode($id).'/metadata?type='.rawurlencode(self::SERIES_FLAVOR),
            ['metadata' => json_encode($this->seriesFields($title, $description), JSON_THROW_ON_ERROR), 'type' => self::SERIES_FLAVOR],
            true,
        );
        if (404 === $response->status) {
            throw new OpencastNotFoundException(sprintf('Opencast series "%s" not found', $id), 404);
        }
        $response->expect(200);
    }

    public function deleteSeries(OpencastHttpClient $http, string $id): void
    {
        $response = $http->delete('/api/series/'.rawurlencode($id), true);
        if (404 === $response->status) {
            return;
        }
        $response->expect(204);
    }

    public function createUser(OpencastHttpClient $http, OpencastUser $user): void
    {
        $response = $http->post('/user-utils/', $this->userFields($user), true);
        if (409 === $response->status) {
            throw new OpencastException(sprintf('The Opencast user "%s" already exists', $user->username), 409);
        }
        $response->expect(201);
    }

    public function updateUser(OpencastHttpClient $http, OpencastUser $user): void
    {
        $response = $http->put('/user-utils/'.rawurlencode($user->username).'.json', $this->userFields($user), true);
        if (404 === $response->status) {
            throw new OpencastNotFoundException(sprintf('The Opencast user "%s" does not exist', $user->username), 404);
        }
        $response->expect(200, 201);
    }

    public function deleteUser(OpencastHttpClient $http, string $username): void
    {
        $response = $http->delete('/user-utils/'.rawurlencode($username).'.json', true);
        if (404 === $response->status) {
            return;
        }
        $response->expect(200);
    }

    public function getSucceededWorkflowMediaPackageIds(OpencastHttpClient $http, string $workflowDefinition): array
    {
        $ids = [];
        for ($offset = 0;; $offset += self::EVENTS_PAGE) {
            $events = $http->get('/api/events', ['limit' => self::EVENTS_PAGE, 'offset' => $offset], true)->expect(200)->json();
            foreach ($events as $event) {
                $id = $event['identifier'] ?? null;
                if (in_array(self::ENGAGE_CHANNEL, (array) ($event['publication_status'] ?? []), true)) {
                    continue;
                }
                if (is_string($id) && $this->hasSucceededWorkflow($http, $id, $workflowDefinition)) {
                    $ids[] = $id;
                }
            }
            if (count($events) < self::EVENTS_PAGE) {
                return $ids;
            }
        }
    }

    private function hasSucceededWorkflow(OpencastHttpClient $http, string $mediaPackageId, string $workflowDefinition): bool
    {
        $response = $http->get('/workflow/mediaPackage/'.rawurlencode($mediaPackageId).'/instances.json', [], true);
        if (!$response->isSuccessful()) {
            return false;
        }

        $data = $response->json();
        foreach (MediaPackage::nodes($data['workflows']['workflow'] ?? $data['workflows'] ?? $data) as $workflow) {
            $definition = $workflow['template'] ?? $workflow['definition_identifier'] ?? $workflow['workflow_definition_identifier'] ?? null;
            $state = strtoupper((string) ($workflow['state'] ?? ''));
            if ($definition === $workflowDefinition && 'SUCCEEDED' === $state) {
                return true;
            }
        }

        return false;
    }

    private function seriesFields(string $title, string $description): array
    {
        return [['id' => 'title', 'value' => $title], ['id' => 'description', 'value' => $description]];
    }

    private function userFields(OpencastUser $user): array
    {
        return [
            'username' => $user->username,
            'password' => bin2hex(random_bytes(24)),
            'name' => $user->name,
            'email' => $user->email,
            'roles' => json_encode(array_values($user->roles), JSON_THROW_ON_ERROR),
        ];
    }
}
