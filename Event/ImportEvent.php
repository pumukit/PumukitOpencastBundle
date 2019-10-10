<?php

namespace Pumukit\OpencastBundle\Event;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\EventDispatcher\Event;

class ImportEvent extends Event
{
    /**
     * @var MultimediaObject
     */
    protected $multimediaObject;

    /**
     * @param MultimediaObject $multimediaObject
     */
    public function __construct(MultimediaObject $multimediaObject)
    {
        $this->multimediaObject = $multimediaObject;
    }

    /**
     * @return MultimediaObject
     */
    public function getMultimediaObject()
    {
        return $this->multimediaObject;
    }
}
