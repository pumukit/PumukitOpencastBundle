<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Pumukit\OpencastBundle\MediaPackage\MediaPackage;
use Pumukit\SchemaBundle\Document\EmbeddedSegment;

final class SegmentFactory
{
    public static function fromMediaPackage(MediaPackage $mediaPackage): array
    {
        return array_map(self::create(...), $mediaPackage->segments());
    }

    private static function create(array $segment): EmbeddedSegment
    {
        $embeddedSegment = new EmbeddedSegment();
        $embeddedSegment->setIndex($segment['index']);
        $embeddedSegment->setTime($segment['time']);
        $embeddedSegment->setDuration($segment['duration']);
        $embeddedSegment->setRelevance(0);
        $embeddedSegment->setHit(false);
        $embeddedSegment->setText('');
        $embeddedSegment->setPreview($segment['preview']);

        return $embeddedSegment;
    }
}
