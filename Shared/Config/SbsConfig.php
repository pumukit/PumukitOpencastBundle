<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Shared\Config;

final readonly class SbsConfig
{
    public function __construct(
        private bool $generateSbs,
        private string $profile,
        private bool $useFlavour,
        private string $flavour,
    ) {}

    public function generateSbs(): bool
    {
        return $this->generateSbs;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function useFlavour(): bool
    {
        return $this->useFlavour;
    }

    public function getFlavour(): string
    {
        return $this->flavour;
    }
}
