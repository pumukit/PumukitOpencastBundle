<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\Exception\OpencastNotFoundException;
use Pumukit\SchemaBundle\Document\Series;

#[WithMonologChannel('opencast')]
class SeriesSyncService
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ClientService $clientService,
        private readonly LoggerInterface $logger,
    ) {}

    public function createSeries(Series $series): void
    {
        if ($this->isDefault($series)) {
            return;
        }

        try {
            $id = $this->clientService->createSeries($this->text($series->getTitle()), $this->text($series->getDescription()));
        } catch (OpencastException $exception) {
            $this->logger->error('Opencast series not created', ['series' => $series->getId(), 'error' => $exception->getMessage()]);

            return;
        }

        $series->setProperty('opencast', $id);
        $this->documentManager->flush();
        $this->logger->info('Opencast series created', ['series' => $series->getId(), 'opencast' => $id]);
    }

    public function updateSeries(Series $series): void
    {
        if ($this->isDefault($series)) {
            return;
        }
        $id = $series->getProperty('opencast');
        if (!$id) {
            $this->createSeries($series);

            return;
        }

        try {
            $this->clientService->updateSeries((string) $id, $this->text($series->getTitle()), $this->text($series->getDescription()));
        } catch (OpencastNotFoundException) {
            $this->logger->warning('Opencast series not found, creating it again', ['series' => $series->getId(), 'opencast' => $id]);
            $this->createSeries($series);
        } catch (OpencastException $exception) {
            $this->logger->error('Opencast series not updated', ['series' => $series->getId(), 'opencast' => $id, 'error' => $exception->getMessage()]);
        }
    }

    public function deleteSeries(Series $series): void
    {
        $id = $series->getProperty('opencast');
        if (!$id || $this->isDefault($series)) {
            return;
        }

        try {
            $this->clientService->deleteSeries((string) $id);
            $this->logger->info('Opencast series deleted', ['series' => $series->getId(), 'opencast' => $id]);
        } catch (OpencastException $exception) {
            $this->logger->error('Opencast series not deleted', ['series' => $series->getId(), 'opencast' => $id, 'error' => $exception->getMessage()]);
        }
    }

    public function text(?string $text): string
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function isDefault(Series $series): bool
    {
        return SeriesImportService::DEFAULT_SERIES === $series->getProperty('opencast');
    }
}
