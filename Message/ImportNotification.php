<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Message;

final readonly class ImportNotification
{
    public function __construct(public string $multimediaObjectId) {}
}
