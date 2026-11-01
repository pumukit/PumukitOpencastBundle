<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Api;

final readonly class OpencastUser
{
    public function __construct(
        public string $username,
        public string $name,
        public string $email,
        public array $roles,
    ) {}
}
