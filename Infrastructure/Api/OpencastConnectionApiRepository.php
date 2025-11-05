<?php

namespace Pumukit\OpencastBundle\Infrastructure\Api;

use Pumukit\OpencastBundle\Domain\Repository\OpencastConnectionRepositoryInterface;
use Pumukit\OpencastBundle\Infrastructure\Http\OpencastHttpClient;

final class OpencastConnectionApiRepository implements OpencastConnectionRepositoryInterface
{
    public function __construct(
        private OpencastHttpClient $httpClient
    ) {}

    public function check(): bool
    {
        try {
            $this->httpClient->request('GET', '/info/health');

            return true;

        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot connect to Opencast endpoint: ' . $e->getMessage(), 0, $e);
        }
    }

}