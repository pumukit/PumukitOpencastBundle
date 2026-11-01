<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Event;

use Pumukit\OpencastBundle\MediaPackage\MediaPackage;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Contracts\EventDispatcher\Event;

final class ImportEvent extends Event
{
    public function __construct(
        private readonly MultimediaObject $multimediaObject,
        private readonly MediaPackage $mediaPackage,
    ) {}

    public function getMultimediaObject(): MultimediaObject
    {
        return $this->multimediaObject;
    }

    public function getMediaPackage(): MediaPackage
    {
        return $this->mediaPackage;
    }
}
