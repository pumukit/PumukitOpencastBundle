<?php

namespace Pumukit\OpencastBundle\Infrastructure\Api;

use Pumukit\OpencastBundle\Application\Opencast\EnsureVersionIsSupported\EnsureVersionIsSupportedResponse;
use Pumukit\OpencastBundle\Domain\Repository\OpencastVersionRepositoryInterface;
use Pumukit\OpencastBundle\Shared\Config\OpencastConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpencastVersionApiRepository implements OpencastVersionRepositoryInterface
{
    public function __construct(
        private OpencastConfig $config,
        private HttpClientInterface $httpClient
    ) {}

    public function getCurrentVersion(): string
    {
        $url = rtrim($this->config->getHost(), '/') . '/info/components.json';

        try {
            $response = $this->httpClient->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf('Opencast endpoint returned status code %d', $response->getStatusCode()));
            }

            $data = json_decode($response->getContent(), true);

            if (!isset($data['rest'][0]['version'])) {
                throw new \RuntimeException('Cannot recognize ["rest"][0]["version"] in /info/components.json');
            }

            return $data['rest'][0]['version'];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot connect to Opencast endpoint: ' . $e->getMessage(), 0, $e);
        }
    }
}