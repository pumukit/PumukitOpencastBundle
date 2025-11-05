<?php

namespace Pumukit\OpencastBundle\Application\Opencast\EnsureVersionIsSupported;

final class EnsureVersionIsSupportedResponse
{
    public function __construct(
        public readonly bool $isSupported,
        public readonly string $currentVersion,
        public readonly ?string $message = null
    ) {}
}