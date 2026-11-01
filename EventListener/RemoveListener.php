<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\Exception\OpencastNotFoundException;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;

#[WithMonologChannel('opencast')]
class RemoveListener
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ClientService $clientService,
        private readonly LoggerInterface $logger,
        private readonly bool $deleteArchiveMediaPackage = false,
    ) {}

    public function onMultimediaObjectDelete(MultimediaObjectEvent $event): void
    {
        $mediaPackageId = $event->getMultimediaObject()->getProperty('opencast');
        if (!$this->deleteArchiveMediaPackage || !$this->clientService->isEnabled() || !$mediaPackageId) {
            return;
        }

        if (null !== $this->documentManager->getRepository(MultimediaObject::class)->findOneBy(['properties.opencast' => $mediaPackageId])) {
            return;
        }

        try {
            $this->clientService->removeEvent((string) $mediaPackageId);
            $this->logger->info('Opencast event deleted', ['mediapackage' => $mediaPackageId]);
        } catch (OpencastNotFoundException) {
            $this->logger->info('Opencast event already deleted', ['mediapackage' => $mediaPackageId]);
        } catch (OpencastException $exception) {
            $this->logger->error('Opencast event not deleted', ['mediapackage' => $mediaPackageId, 'error' => $exception->getMessage()]);
        }
    }
}
