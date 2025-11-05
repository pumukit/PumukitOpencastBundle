<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Application\Opencast\CheckOpencastConnection;

final readonly class CheckOpencastConnectionResponse
{
    public function __construct(
        public bool    $status,
        public ?string $message = null
    ) {}
}