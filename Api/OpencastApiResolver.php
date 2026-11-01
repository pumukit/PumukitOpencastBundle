<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Api;

use Pumukit\OpencastBundle\Client\OpencastHttpClient;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\Exception\UnsupportedOpencastVersionException;

class OpencastApiResolver
{
    private array $versions = [];

    public function __construct(private readonly iterable $apis) {}

    public function getApi(OpencastHttpClient $http): OpencastApiInterface
    {
        $version = $this->getVersion($http);
        foreach ($this->apis as $api) {
            if ($api->supports($version)) {
                return $api;
            }
        }

        throw new UnsupportedOpencastVersionException($version);
    }

    public function getVersion(OpencastHttpClient $http): string
    {
        $host = $http->getHost();
        if (isset($this->versions[$host])) {
            return $this->versions[$host];
        }

        $version = null;
        $response = $http->get('/info/health');
        if ($response->isSuccessful()) {
            $version = $response->json()['releaseId'] ?? null;
        }
        if (!is_string($version) || '' === $version) {
            $components = $http->get('/info/components.json')->expect(200)->json();
            $version = $components['rest'][0]['version'] ?? null;
        }
        if (!is_string($version) || '' === $version) {
            throw new OpencastException(sprintf('Unable to get the version of the Opencast server %s', $host));
        }

        return $this->versions[$host] = $version;
    }
}
