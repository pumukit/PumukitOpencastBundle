<?php

namespace Pumukit\OpencastBundle\Domain\Exception;

class InvalidOpencastResponseException extends \RuntimeException
{
    public static function missingField(string $field, string $endpoint): self
    {
        return new self(
            sprintf('Missing expected field "%s" in response from %s', $field, $endpoint)
        );
    }

    public static function invalidJson(string $endpoint, \Throwable $previous): self
    {
        return new self(
            sprintf('Invalid JSON response from %s', $endpoint),
            0,
            $previous
        );
    }
}
