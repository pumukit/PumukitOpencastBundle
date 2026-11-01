<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Api;

use Pumukit\OpencastBundle\MediaPackage\MediaPackage;

final readonly class SearchResult
{
    public function __construct(
        public int $total,
        public array $mediaPackages,
    ) {}

    public function ids(): array
    {
        return array_map(static fn (MediaPackage $mediaPackage) => $mediaPackage->id(), $this->mediaPackages);
    }
}
