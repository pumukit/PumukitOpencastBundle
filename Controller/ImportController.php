<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Controller;

use Doctrine\Bundle\MongoDBBundle\Attribute\MapDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/opencast')]
#[WithMonologChannel('opencast')]
class ImportController extends AbstractController
{
    public function __construct(
        private readonly OpencastImportService $opencastImportService,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/import_event', name: 'pumukit_opencast_import_event', methods: ['POST'])]
    public function eventAction(Request $request): Response
    {
        try {
            $data = json_decode((string) $request->request->get('mediapackage'), true, 512, JSON_THROW_ON_ERROR);
            $mediaPackage = MediaPackage::fromArray(is_array($data['mediapackage'] ?? null) ? $data['mediapackage'] : []);
        } catch (\JsonException|OpencastException $exception) {
            $this->logger->warning('Opencast import event without a valid mediapackage', ['error' => $exception->getMessage()]);

            return new Response('No valid mediapackage', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();

        try {
            $multimediaObject = $this->opencastImportService->importRecordingFromMediaPackage($mediaPackage, false, $user instanceof User ? $user : null);
        } catch (\Exception $exception) {
            $this->logger->error('Opencast import event failed', ['mediapackage' => $mediaPackage->id(), 'error' => $exception->getMessage()]);

            return new Response('Import failed: '.$exception->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('Success '.$multimediaObject->getId(), Response::HTTP_OK);
    }

    #[Route('/sync_tracks/{id}', name: 'pumukit_opencast_import_sync_tracks', methods: ['GET', 'POST'])]
    public function syncTracksAction(#[MapDocument(id: 'id')] MultimediaObject $multimediaObject): Response
    {
        $this->opencastImportService->syncTracks($multimediaObject);
        $this->documentManager->flush();

        return new Response('Success '.$multimediaObject->getId(), Response::HTTP_OK);
    }
}
