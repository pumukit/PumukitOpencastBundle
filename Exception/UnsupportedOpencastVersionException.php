<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Exception;

class UnsupportedOpencastVersionException extends OpencastException
{
    public function __construct(public readonly string $version)
    {
        parent::__construct(sprintf('Opencast %s is not supported by any of the Opencast API services of PuMuKIT', $version));
    }
}
