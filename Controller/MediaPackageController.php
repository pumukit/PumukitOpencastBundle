<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Pumukit\CoreBundle\Services\PaginationService;
use Pumukit\OpencastBundle\Exception\OpencastConnectionException;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\Exception\UnsupportedOpencastVersionException;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/opencast/mediapackage')]
#[IsGranted('ROLE_ACCESS_IMPORTER')]
#[WithMonologChannel('opencast')]
class MediaPackageController extends AbstractController
{
    public const LIMIT = 10;
    private const SESSION_QUERY = 'admin/opencast/query';

    public function __construct(
        private readonly ClientService $clientService,
        private readonly OpencastImportService $opencastImportService,
        private readonly DocumentManager $documentManager,
        private readonly PaginationService $paginationService,
        private readonly LoggerInterface $logger,
        private readonly bool $opencastShowImporterTab = true,
    ) {}

    #[Route('', name: 'pumukitopencast', methods: ['GET'])]
    public function indexAction(Request $request): Response
    {
        $this->checkImporter();

        $query = $this->getQuery($request);
        $page = max(1, $request->query->getInt('page', 1));

        try {
            $version = $this->clientService->checkSupported();
            $result = $this->clientService->getMediaPackages($query, self::LIMIT, ($page - 1) * self::LIMIT);
        } catch (UnsupportedOpencastVersionException $exception) {
            return $this->error('unsupported', $exception->getMessage(), $exception->version);
        } catch (OpencastConnectionException $exception) {
            return $this->error('connection', $exception->getMessage());
        } catch (OpencastException $exception) {
            return $this->error('error', $exception->getMessage());
        }

        $imported = [];
        if ($result->mediaPackages) {
            $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->findBy(['properties.opencast' => ['$in' => $result->ids()]]);
            foreach ($multimediaObjects as $multimediaObject) {
                $imported[$multimediaObject->getProperty('opencast')] = $multimediaObject->getId();
            }
        }

        return $this->render('@PumukitOpencast/MediaPackage/index.html.twig', [
            'mediaPackages' => $this->paginationService->createFixedAdapter($result->total, $result->mediaPackages, $page, self::LIMIT),
            'imported' => $imported,
            'player' => $this->clientService->getPlayerUrl(),
            'query' => $query,
            'version' => $version,
        ]);
    }

    #[Route('/{id}/import', name: 'pumukitopencast_import', methods: ['POST'])]
    public function importAction(Request $request, string $id): RedirectResponse
    {
        $this->checkImporter();
        if (!$this->isCsrfTokenValid('opencast_import', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $user = $this->getUser();

        try {
            $multimediaObject = $this->opencastImportService->importRecording($id, $request->request->getBoolean('invert'), $user instanceof User ? $user : null);
            $this->addFlash('success', 'Opencast recording imported');
            $this->logger->info('Opencast recording imported from the importer', ['mediapackage' => $id, 'multimedia_object' => $multimediaObject->getId(), 'user' => $user?->getUserIdentifier()]);
        } catch (\Exception $exception) {
            $this->addFlash('error', $exception->getMessage());
            $this->logger->error('Opencast recording not imported from the importer', ['mediapackage' => $id, 'error' => $exception->getMessage()]);
        }

        return $this->redirectToRoute('pumukitopencast', ['page' => $request->request->getInt('page', 1)]);
    }

    private function getQuery(Request $request): string
    {
        $session = $request->getSession();
        if ($request->query->has('reset')) {
            $session->remove(self::SESSION_QUERY);
        } elseif ($request->query->has('q')) {
            $session->set(self::SESSION_QUERY, trim((string) $request->query->get('q')));
        }

        return (string) $session->get(self::SESSION_QUERY, '');
    }

    private function error(string $type, string $message, ?string $version = null): Response
    {
        return $this->render('@PumukitOpencast/MediaPackage/error.html.twig', [
            'type' => $type,
            'message' => $message,
            'version' => $version,
            'host' => $this->clientService->getUrl(),
        ], new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
    }

    private function checkImporter(): void
    {
        if (!$this->opencastShowImporterTab || !$this->clientService->isEnabled()) {
            throw $this->createNotFoundException('The Opencast importer is not enabled');
        }
    }
}
