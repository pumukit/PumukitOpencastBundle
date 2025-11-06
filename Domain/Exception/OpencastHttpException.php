<?php

namespace Pumukit\OpencastBundle\Domain\Exception;

class OpencastHttpException extends \RuntimeException
{
    public static function fromResponse(string $url, int $statusCode, ?string $error = null): self
    {
        return new self(
            sprintf(
                'HTTP error %d when accessing %s%s',
                $statusCode,
                $url,
                $error ? ': ' . $error : ''
            ),
            $statusCode
        );
    }

    public static function requestFailed(string $url, \Throwable $previous): self
    {
        return new self(
            sprintf('Request to %s failed: %s', $url, $previous->getMessage()),
            0,
            $previous
        );
    }
}
