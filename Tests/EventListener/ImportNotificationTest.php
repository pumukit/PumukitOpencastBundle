<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\EventListener;

use Psr\Log\NullLogger;
use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\NotificationBundle\Services\SenderService;
use Pumukit\OpencastBundle\Event\ImportEvent;
use Pumukit\OpencastBundle\EventListener\ImportNotificationListener;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;
use Pumukit\OpencastBundle\Message\ImportNotification;
use Pumukit\OpencastBundle\MessageHandler\ImportNotificationHandler;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Role;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\PersonService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 *
 * @coversNothing
 */
class ImportNotificationTest extends PumukitTestCase
{
    public function testTheNotificationIsQueuedOnlyWhenEnabled(): void
    {
        $event = new ImportEvent($this->multimediaObject(), MediaPackage::fromArray(['id' => 'mp']));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        (new ImportNotificationListener($bus, ['enabled' => false]))->onImport($event);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (ImportNotification $message) => $message->multimediaObjectId === $event->getMultimediaObject()->getId()))
            ->willReturnCallback(static fn (object $message) => new Envelope($message))
        ;
        (new ImportNotificationListener($bus, ['enabled' => true]))->onImport($event);
    }

    public function testTheOwnersAreNotified(): void
    {
        $multimediaObject = $this->multimediaObject();
        $user = new User();
        $user->setUsername('owner');
        $user->setEmail('owner@example.org');
        $user->setFullname('Owner');
        $this->dm->persist($user);
        $this->dm->flush();
        $personService = self::getContainer()->get(PersonService::class);
        $personService->referencePersonIntoUser($user);
        $role = new Role();
        $role->setCod($personService->getPersonalScopeRoleCode());
        $this->dm->persist($role);
        $this->dm->flush();
        $personService->createRelationPerson($user->getPerson(), $role, $multimediaObject, true, false);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://pumukit.example.org/admin/mm/'.$multimediaObject->getId());
        $sender = $this->createMock(SenderService::class);
        $sender->expects($this->once())->method('sendEmails')->with('owner@example.org', $this->anything(), $this->anything(), $this->callback(static fn (array $parameters) => 'Owner' === $parameters['username'] && str_ends_with($parameters['url'], $multimediaObject->getId())));

        $handler = new ImportNotificationHandler($this->dm, $sender, $urlGenerator, new NullLogger(), ['enabled' => true, 'template' => 't', 'subject' => 's', 'url' => 'route']);
        $handler(new ImportNotification($multimediaObject->getId()));
    }

    private function multimediaObject(): MultimediaObject
    {
        $factory = self::getContainer()->get(FactoryService::class);

        return $factory->createMultimediaObject($factory->createSeries());
    }
}
