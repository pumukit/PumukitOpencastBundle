<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Event;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Contracts\EventDispatcher\Event;

class ImportEvent extends Event
{
    protected $multimediaObject;

    public function __construct(MultimediaObject $multimediaObject)
    {
        $this->multimediaObject = $multimediaObject;
    }

    public function getMultimediaObject(): MultimediaObject
    {
        return $this->multimediaObject;
    }
}
