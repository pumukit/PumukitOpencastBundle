<?php

namespace Pumukit\OpencastBundle\Infrastructure\Api;

use Pumukit\OpencastBundle\Domain\Repository\OpencastVersionRepositoryInterface;
use Pumukit\OpencastBundle\Infrastructure\Http\OpencastHttpClient;

final class OpencastVersionApiRepository implements OpencastVersionRepositoryInterface
{
    public function __construct(
        private OpencastHttpClient $httpClient
    ) {}

    public function getCurrentVersion(): string
    {
        try {
            $response = $this->httpClient->request('GET', '/info/health');

            $data = json_decode($response['content'], true);

            return $data['releaseId'];

        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot connect to Opencast endpoint: ' . $e->getMessage(), 0, $e);
        }
    }
}