<?php

namespace Pumukit\OpencastBundle\Event;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\EventDispatcher\Event;

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
