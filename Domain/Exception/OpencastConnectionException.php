<?php

namespace Pumukit\OpencastBundle\Domain\Exception;

class OpencastConnectionException extends \RuntimeException
{
    public static function unreachable(string $endpoint, \Throwable $previous): self
    {
        return new self(
            sprintf('Unable to connect to Opencast endpoint: %s', $endpoint),
            0,
            $previous
        );
    }
}
