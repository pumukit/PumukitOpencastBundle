<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\EventListener;

use Pumukit\OpencastBundle\Event\ImportEvent;
use Pumukit\OpencastBundle\Message\ImportNotification;
use Symfony\Component\Messenger\MessageBusInterface;

class ImportNotificationListener
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly array $notificationConfig = [],
    ) {}

    public function onImport(ImportEvent $event): void
    {
        if (!($this->notificationConfig['enabled'] ?? false)) {
            return;
        }

        $this->bus->dispatch(new ImportNotification((string) $event->getMultimediaObject()->getId()));
    }
}
