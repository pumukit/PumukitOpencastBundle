<?php

namespace Pumukit\OpencastBundle\Controller;

use Pumukit\OpencastBundle\Form\Type\MultimediaObjectType;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\OpencastBundle\Services\OpencastService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/admin/opencast/mm")
 * @Security("is_granted('ROLE_ACCESS_IMPORTER')")
 */
class MultimediaObjectController extends AbstractController
{
    private $opencastClientService;
    private $multimediaObjectService;
    private $opencastImportService;
    private $opencastService;
    private $opencastSBSGenerate;
    private $opencastSBSProfile;

    public function __construct(
        ClientService $opencastClientService,
        MultimediaObjectService $multimediaObjectService,
        OpencastImportService $opencastImportService,
        OpencastService $opencastService,
        bool $opencastSBSGenerate = false,
        string $opencastSBSProfile = ''
    )
    {
        $this->opencastClientService = $opencastClientService;
        $this->multimediaObjectService = $multimediaObjectService;
        $this->opencastImportService = $opencastImportService;
        $this->opencastService = $opencastService;
        $this->opencastSBSGenerate = $opencastSBSGenerate;
        $this->opencastSBSProfile = $opencastSBSProfile;

    }

    /**
     * @Route("/index/{id}", name="pumukit_opencast_mm_index")
     * @Template("@PumukitOpencast/MultimediaObject/index.html.twig")
     */
    public function indexAction(MultimediaObject $multimediaObject): array
    {
        return [
            'mm' => $multimediaObject,
            'generate_sbs' => $this->opencastSBSGenerate,
            'sbs_profile' => $this->opencastSBSProfile,
            'player' => $this->opencastClientService->getPlayerUrl(),
        ];
    }

    /**
     * @Route("/update/{id}", name="pumukit_opencast_mm_update")
     * @Template("@PumukitOpencast/MultimediaObject/update.html.twig")
     */
    public function updateAction(Request $request, MultimediaObject $multimediaObject)
    {
        $translator = $this->get('translator');
        $locale = $request->getLocale();
        $form = $this->createForm(MultimediaObjectType::class, $multimediaObject, ['translator' => $translator, 'locale' => $locale]);
        if ($request->isMethod('PUT') || $request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                try {
                    $multimediaObject = $this->multimediaObjectService->updateMultimediaObject($multimediaObject);
                } catch (\Exception $e) {
                    return new Response($e->getMessage(), 400);
                }

                return $this->redirect(
                    $this->generateUrl(
                        'pumukitnewadmin_track_list',
                        ['id' => $multimediaObject->getId()]
                    )
                );
            }
        }

        return [
            'form' => $form->createView(),
            'multimediaObject' => $multimediaObject,
        ];
    }

    /**
     * @Route("/info/{id}", name="pumukit_opencast_mm_info")
     * @Template("@PumukitOpencast/MultimediaObject/info.html.twig")
     */
    public function infoAction(MultimediaObject $multimediaObject): array
    {
        $presenterDeliveryUrl = '';
        $presentationDeliveryUrl = '';
        $presenterDeliveryTrack = $multimediaObject->getTrackWithTag('presenter/delivery');
        $presentationDeliveryTrack = $multimediaObject->getTrackWithTag('presentation/delivery');
        if (null !== $presenterDeliveryTrack) {
            $presenterDeliveryUrl = $presenterDeliveryTrack->getUrl();
        }
        if (null !== $presentationDeliveryTrack) {
            $presentationDeliveryUrl = $presentationDeliveryTrack->getUrl();
        }

        return [
            'presenter_delivery_url' => $presenterDeliveryUrl,
            'presentation_delivery_url' => $presentationDeliveryUrl,
        ];
    }

    /**
     * @Route("/generatesbs/{id}", name="pumukit_opencast_mm_generatesbs")
     */
    public function generateSbsAction(Request $request, MultimediaObject $multimediaObject): RedirectResponse
    {
        $opencastUrls = $this->opencastImportService->getOpencastUrls($multimediaObject->getProperty('opencast'));
        $this->opencastService->generateSbsTrack($multimediaObject, $opencastUrls);

        return $this->redirect(
            $this->generateUrl('pumukitnewadmin_track_list',
                ['id' => $multimediaObject->getId()]
            )
        );
    }
}
