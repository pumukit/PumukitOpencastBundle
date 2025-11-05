<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Shared\Config;

final readonly class NotificationConfig
{
    public function __construct(
        private bool $enabled,
        private ?string $template = null,
        private ?string $url = null,
        private ?string $subject = null,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }
}
