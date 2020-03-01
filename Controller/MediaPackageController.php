<?php

namespace Pumukit\OpencastBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Regex;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Pumukit\CoreBundle\Services\PaginationService;
use Pumukit\EncoderBundle\Services\JobService;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\OpencastBundle\Services\OpencastService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @Route("/admin")
 * @Security("is_granted('ROLE_ACCESS_IMPORTER')")
 */
class MediaPackageController extends AbstractController
{
    private $opencastShowImporterTab;
    private $opencastClientService;
    private $documentManager;
    private $opencastService;
    private $opencastImportService;
    private $paginationService;

    public function __construct(
        $opencastShowImporterTab,
        ?ClientService $opencastClientService,
        DocumentManager $documentManager,
        OpencastService $opencastService,
        OpencastImportService $opencastImportService,
        PaginationService $paginationService
    )
    {
        $this->opencastShowImporterTab = $opencastShowImporterTab;
        $this->opencastClientService = $opencastClientService;
        $this->documentManager = $documentManager;
        $this->opencastService = $opencastService;
        $this->opencastImportService = $opencastImportService;
        $this->paginationService = $paginationService;
    }

    /**
     * @Route("/opencast/mediapackage", name="pumukitopencast")
     * @Template("@PumukitOpencast/MediaPackage/index.html.twig")
     */
    public function indexAction(Request $request)
    {
        if (!$this->opencastShowImporterTab) {
            throw new AccessDeniedException('Not allowed. Configure your OpencastBundle to show the Importer Tab.');
        }

        if (!$this->opencastClientService) {
            throw $this->createNotFoundException('PumukitOpencastBundle not configured.');
        }

        $repository_multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class);

        $limit = 10;
        $page = $request->get('page', 1);
        $criteria = $this->getCriteria($request);

        try {
            [$total, $mediaPackages] = $this->opencastClientService->getMediaPackages(
                isset($criteria['name']) ? $criteria['name']->regex : '',
                $limit,
                ($page - 1) * $limit
            );
        } catch (\Exception $e) {
            return new Response(
                $this->renderView(
                    '@PumukitOpencast/MediaPackage/error.html.twig', [
                        'admin_url' => $this->opencastClientService->getUrl(),
                        'message' => $e->getMessage()
                    ]),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $currentPageOpencastIds = [];

        $pics = [];
        foreach ($mediaPackages as $mediaPackage) {
            $currentPageOpencastIds[] = $mediaPackage['id'];
            $pics[$mediaPackage['id']] = $this->opencastService->getMediaPackageThumbnail($mediaPackage);
        }

        $pager = $this->paginationService->createFixedAdapter($total, $mediaPackages, $page, $limit);

        $repo = $repository_multimediaObjects->createQueryBuilder()
            ->field('properties.opencast')->exists(true)
            ->field('properties.opencast')->in($currentPageOpencastIds)
            ->getQuery()
            ->execute()
        ;

        return [
            'mediaPackages' => $pager,
            'multimediaObjects' => $repo,
            'player' => $this->opencastClientService->getPlayerUrl(),
            'pics' => $pics
        ];
    }

    /**
     * @Route("/opencast/mediapackage/{id}", name="pumukitopencast_import")
     */
    public function importAction(Request $request, string $id): RedirectResponse
    {
        if (!$this->container->getParameter('pumukit_opencast.show_importer_tab')) {
            throw new AccessDeniedException('Not allowed. Configure your OpencastBundle to show the Importer Tab.');
        }

        $this->opencastImportService->importRecording($id, $request->get('invert'), $this->getUser());

        if ($request->headers->get('referer')) {
            return $this->redirect($request->headers->get('referer'));
        }

        return $this->redirectToRoute('pumukitopencast');
    }

    public function getCriteria(Request $request): array
    {
        $criteria = $request->get('criteria', []);

        if (array_key_exists('reset', $criteria)) {
            $this->get('session')->remove('admin/opencast/criteria');
        } elseif ($criteria) {
            $this->get('session')->set('admin/opencast/criteria', $criteria);
        }
        $criteria = $this->get('session')->get('admin/opencast/criteria', []);

        $new_criteria = [];

        foreach ($criteria as $property => $value) {
            if ('' !== $value) {
                $new_criteria[$property] = new Regex($value, 'i');
            }
        }

        return $new_criteria;
    }
}
