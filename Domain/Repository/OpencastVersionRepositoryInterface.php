<?php

namespace Pumukit\OpencastBundle\Domain\Repository;

interface OpencastVersionRepositoryInterface
{
    public function getCurrentVersion(): string;
}