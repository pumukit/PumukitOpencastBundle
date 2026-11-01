<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Controller;

use Doctrine\Bundle\MongoDBBundle\Attribute\MapDocument;
use Pumukit\OpencastBundle\Form\Type\MultimediaObjectType;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\OpencastBundle\Services\OpencastService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/opencast/mm')]
#[IsGranted('ROLE_ACCESS_IMPORTER')]
class MultimediaObjectController extends AbstractController
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly MultimediaObjectService $multimediaObjectService,
        private readonly OpencastImportService $opencastImportService,
        private readonly OpencastService $opencastService,
    ) {}

    #[Route('/index/{id}', name: 'pumukit_opencast_mm_index', methods: ['GET'])]
    public function indexAction(#[MapDocument(id: 'id')] MultimediaObject $multimediaObject): Response
    {
        return $this->render('@PumukitOpencast/MultimediaObject/index.html.twig', [
            'mm' => $multimediaObject,
            'track' => $multimediaObject->getTrackWithTag('presenter/delivery') ?? $multimediaObject->getTrackWithTag('opencast'),
            'generate_sbs' => $this->opencastService->isSbsEnabled(),
            'sbs_profile' => $this->opencastService->getSbsProfile(),
            'player' => $this->clientService->getPlayerUrl(),
        ]);
    }

    #[Route('/update/{id}', name: 'pumukit_opencast_mm_update', methods: ['GET', 'POST'])]
    public function updateAction(Request $request, #[MapDocument(id: 'id')] MultimediaObject $multimediaObject): Response
    {
        $form = $this->createForm(MultimediaObjectType::class, $multimediaObject, ['action' => $this->generateUrl('pumukit_opencast_mm_update', ['id' => $multimediaObject->getId()])]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->multimediaObjectService->updateMultimediaObject($multimediaObject);

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('@PumukitOpencast/MultimediaObject/update.html.twig', [
            'form' => $form->createView(),
            'mm' => $multimediaObject,
        ]);
    }

    #[Route('/info/{id}', name: 'pumukit_opencast_mm_info', methods: ['GET'])]
    public function infoAction(#[MapDocument(id: 'id')] MultimediaObject $multimediaObject): Response
    {
        return $this->render('@PumukitOpencast/MultimediaObject/info.html.twig', [
            'mm' => $multimediaObject,
            'presenter' => $multimediaObject->getTrackWithTag('presenter/delivery'),
            'presentation' => $multimediaObject->getTrackWithTag('presentation/delivery'),
        ]);
    }

    #[Route('/generatesbs/{id}', name: 'pumukit_opencast_mm_generatesbs', methods: ['POST'])]
    public function generateSbsAction(#[MapDocument(id: 'id')] MultimediaObject $multimediaObject): Response
    {
        $this->opencastService->generateSbsTrack($multimediaObject, $this->opencastImportService->getOpencastUrls($multimediaObject->getProperty('opencast')));

        return $this->redirectToRoute('pumukitnewadmin_track_list', ['id' => $multimediaObject->getId()]);
    }
}
