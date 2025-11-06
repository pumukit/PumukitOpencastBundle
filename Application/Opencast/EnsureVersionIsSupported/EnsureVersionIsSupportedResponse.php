<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Application\Opencast\EnsureVersionIsSupported;

final readonly class EnsureVersionIsSupportedResponse
{
    public function __construct(
        public bool $isSupported,
        public string $currentVersion,
        public ?string $message = null
    ) {}
}
