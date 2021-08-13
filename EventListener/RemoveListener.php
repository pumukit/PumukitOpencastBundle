<?php

namespace Pumukit\OpencastBundle\EventListener;

use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;

class RemoveListener
{
    private $clientService;
    private $deleteArchiveMediaPackage;
    private $deletionWorkflowName;
    private $logger;

    public function __construct(ClientService $clientService, LoggerInterface $logger, bool $deleteArchiveMediaPackage = false, string $deletionWorkflowName = 'delete-archive')
    {
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
            if ($mediaPackageId = $multimediaObject->getProperty('opencast')) {
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
