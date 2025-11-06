<?php

namespace Pumukit\OpencastBundle\Infrastructure\Api;

use Pumukit\OpencastBundle\Domain\Exception\InvalidOpencastResponseException;
use Pumukit\OpencastBundle\Domain\Exception\OpencastConnectionException;
use Pumukit\OpencastBundle\Domain\Repository\OpencastVersionRepositoryInterface;
use Pumukit\OpencastBundle\Infrastructure\Http\OpencastHttpClient;

final class OpencastVersionApiRepository implements OpencastVersionRepositoryInterface
{
    public function __construct(
        private OpencastHttpClient $httpClient
    ) {}

    public function getCurrentVersion(): string
    {
        $path = '/info/health';

        try {
            $response = $this->httpClient->request('GET', $path);
        } catch (\Throwable $e) {
            throw OpencastConnectionException::unreachable($path, $e);
        }

        try {
            $data = json_decode($response['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidOpencastResponseException::invalidJson($path, $e);
        }

        if (!isset($data['releaseId'])) {
            throw InvalidOpencastResponseException::missingField('releaseId', $path);
        }

        return $data['releaseId'];
    }
}
