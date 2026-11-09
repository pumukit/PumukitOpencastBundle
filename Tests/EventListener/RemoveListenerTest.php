<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\EventListener;

use Psr\Log\NullLogger;
use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\OpencastBundle\EventListener\RemoveListener;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;
use Pumukit\SchemaBundle\Services\FactoryService;

/**
 * @internal
 *
 * @coversNothing
 */
class RemoveListenerTest extends PumukitTestCase
{
    public function testTheEventIsDeletedWithTheLastMultimediaObjectOfTheMediaPackage(): void
    {
        $first = $this->multimediaObject('mp-1');
        $second = $this->multimediaObject('mp-1');
        $client = $this->createMock(ClientService::class);
        $client->expects($this->once())->method('removeEvent')->with('mp-1');
        $listener = new RemoveListener($this->dm, $client, new NullLogger(), true);

        $this->delete($first);
        $listener->onMultimediaObjectDelete(new MultimediaObjectEvent($first));
        $this->delete($second);
        $listener->onMultimediaObjectDelete(new MultimediaObjectEvent($second));
    }

    public function testNothingIsDeletedWhenTheOptionIsOff(): void
    {
        $multimediaObject = $this->multimediaObject('mp-2');
        $client = $this->createMock(ClientService::class);
        $client->expects($this->never())->method('removeEvent');

        $this->delete($multimediaObject);
        (new RemoveListener($this->dm, $client, new NullLogger(), false))->onMultimediaObjectDelete(new MultimediaObjectEvent($multimediaObject));
    }

    private function multimediaObject(string $mediaPackageId): MultimediaObject
    {
        $factory = self::getContainer()->get(FactoryService::class);
        $multimediaObject = $factory->createMultimediaObject($factory->createSeries());
        $multimediaObject->setProperty('opencast', $mediaPackageId);
        $this->dm->flush();

        return $multimediaObject;
    }

    private function delete(MultimediaObject $multimediaObject): void
    {
        $this->dm->remove($multimediaObject);
        $this->dm->flush();
    }
}
