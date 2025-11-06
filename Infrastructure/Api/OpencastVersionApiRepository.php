<?php

namespace Pumukit\OpencastBundle\Infrastructure\Api;

use Pumukit\OpencastBundle\Domain\Exception\InvalidOpencastResponseException;
use Pumukit\OpencastBundle\Domain\Exception\OpencastConnectionException;
use Pumukit\OpencastBundle\Domain\Repository\OpencastVersionRepositoryInterface;
use Pumukit\OpencastBundle\Infrastructure\Http\OpencastHttpClient;
use Pumukit\OpencastBundle\Shared\Config\OpencastAPIEndpoints;

final class OpencastVersionApiRepository implements OpencastVersionRepositoryInterface
{
    public function __construct(
        private OpencastHttpClient $httpClient,
        private OpencastAPIEndpoints $endpoints
    ) {}

    public function getCurrentVersion(): string
    {
        try {
            $response = $this->httpClient->request('GET', $this->endpoints->health());
        } catch (\Throwable $e) {
            throw OpencastConnectionException::unreachable($this->endpoints->health(), $e);
        }

        try {
            $data = json_decode($response['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidOpencastResponseException::invalidJson($this->endpoints->health(), $e);
        }

        if (!isset($data['releaseId'])) {
            throw InvalidOpencastResponseException::missingField('releaseId', $this->endpoints->health());
        }

        return $data['releaseId'];
    }
}
