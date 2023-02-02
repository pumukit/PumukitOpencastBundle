<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/api/opencast")
 */
class ImportController extends AbstractController
{
    private $opencastImportService;
    private $documentManager;

    public function __construct(DocumentManager $documentManager, OpencastImportService $opencastImportService)
    {
        $this->documentManager = $documentManager;
        $this->opencastImportService = $opencastImportService;
    }

    /**
     * @Route("/import_event", name="pumukit_opencast_import_event")
     */
    public function eventAction(Request $request): Response
    {
        $mediaPackage = json_decode($request->request->get('mediapackage'), true);
        if (!isset($mediaPackage['mediapackage']['id'])) {
            $this->get('logger')->warning('No mediapackage ID, ERROR 400 returned');

            return new Response('No mediapackage ID', Response::HTTP_BAD_REQUEST);
        }

        $this->opencastImportService->importRecordingFromMediaPackage($mediaPackage['mediapackage'], false, $this->getUser());

        return new Response('Success', Response::HTTP_OK);
    }

    /**
     * @Route("/sync_tracks/{id}", name="pumukit_opencast_import_sync_tracks")
     */
    public function syncTracksAction(MultimediaObject $multimediaObject): Response
    {
        $this->opencastImportService->syncTracks($multimediaObject);

        $this->documentManager->persist($multimediaObject);
        $this->documentManager->flush();

        return new Response('Success '.$multimediaObject->getTitle(), Response::HTTP_OK);
    }
}
