<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;

class RemoveListener
{
    private $documentManager;
    private $clientService;
    private $deleteArchiveMediaPackage;
    private $deletionWorkflowName;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        ClientService $clientService,
        LoggerInterface $logger,
        bool $deleteArchiveMediaPackage = false,
        string $deletionWorkflowName = 'delete-archive'
    ) {
        $this->documentManager = $documentManager;
        $this->clientService = $clientService;
        $this->logger = $logger;
        $this->deleteArchiveMediaPackage = $deleteArchiveMediaPackage;
        $this->deletionWorkflowName = $deletionWorkflowName;
    }

    public function onMultimediaObjectDelete(MultimediaObjectEvent $event): void
    {
        if (!$this->deleteArchiveMediaPackage) {
            return;
        }

        try {
            $multimediaObject = $event->getMultimediaObject();
            $mediaPackageId = $multimediaObject->getProperty('opencast');
            if (!$mediaPackageId) {
                return;
            }

            $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->findBy([
                'properties.opencast' => $mediaPackageId,
            ]);

            if (0 === count($multimediaObjects)) {
                $opencastVersion = $this->clientService->getOpencastVersion();
                if (version_compare($opencastVersion, '9.0.0', '<')) {
                    $output = $this->clientService->applyWorkflowToMediaPackages([$mediaPackageId]);
                    if (!$output) {
                        throw new \Exception('Error on deleting Opencast media package "'
                                         .$mediaPackageId.'" from archive '
                                         .'using workflow name "'
                                         .$this->deletionWorkflowName.'"');
                    }
                } else {
                    $this->clientService->removeEvent($mediaPackageId);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
        }
    }
}
