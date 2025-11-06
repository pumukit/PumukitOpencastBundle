<?php

namespace Pumukit\OpencastBundle\Domain\Repository;

interface OpencastConnectionRepositoryInterface
{
    public function check(): bool;
}
