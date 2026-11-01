<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Client;

final readonly class OpencastConnection
{
    public string $host;
    public ?string $adminHost;

    public function __construct(
        string $host,
        ?string $adminHost = null,
        public string $username = '',
        public string $password = '',
        public bool $insecure = false,
    ) {
        $this->host = rtrim($host, '/');
        $this->adminHost = null === $adminHost || '' === $adminHost ? null : rtrim($adminHost, '/');
    }
}
