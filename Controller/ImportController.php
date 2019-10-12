<?php

namespace Pumukit\OpencastBundle\Controller;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/api/opencast")
 */
class ImportController extends Controller
{
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

        $opencastImportService = $this->get('pumukit_opencast.import');
        $opencastImportService->importRecordingFromMediaPackage($mediaPackage['mediapackage'], $this->getUser());

        return new Response('Success', Response::HTTP_OK);
    }

    /**
     * @Route("/sync_tracks/{id}", name="pumukit_opencast_import_sync_tracks")
     */
    public function syncTracksAction(Request $request, MultimediaObject $multimediaObject): Response
    {
        $dm = $this->container->get('doctrine_mongodb.odm.document_manager');

        $opencastImportService = $this->get('pumukit_opencast.import');
        $opencastImportService->syncTracks($multimediaObject);

        $dm->persist($multimediaObject);
        $dm->flush();

        return new Response('Success '.$multimediaObject->getTitle(), Response::HTTP_OK);
    }
}
