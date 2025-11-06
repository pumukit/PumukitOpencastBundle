<?php

namespace Pumukit\OpencastBundle\Infrastructure\Api;

use Pumukit\OpencastBundle\Domain\Exception\OpencastConnectionException;
use Pumukit\OpencastBundle\Domain\Repository\OpencastConnectionRepositoryInterface;
use Pumukit\OpencastBundle\Infrastructure\Http\OpencastHttpClient;
use Pumukit\OpencastBundle\Shared\Config\OpencastAPIEndpoints;

final class OpencastConnectionApiRepository implements OpencastConnectionRepositoryInterface
{
    public function __construct(
        private OpencastHttpClient $httpClient,
        private OpencastAPIEndpoints $endpoints
    ) {}

    public function check(): bool
    {
        try {
            $this->httpClient->request('GET', $this->endpoints->health());

            return true;
        } catch (\Throwable $e) {
            throw OpencastConnectionException::unreachable($this->endpoints->health(), $e);
        }
    }
}
