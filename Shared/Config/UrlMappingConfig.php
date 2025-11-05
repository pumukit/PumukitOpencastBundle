<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Shared\Config;

final readonly class UrlMappingConfig
{
    public function __construct(
        private string $url,
        private string $path,
    ) {}

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
