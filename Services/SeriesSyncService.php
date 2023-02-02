<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Series;

class SeriesSyncService
{
    private $dm;
    private $clientService;
    private $logger;

    public function __construct(DocumentManager $dm, ClientService $clientService, LoggerInterface $logger)
    {
        $this->dm = $dm;
        $this->clientService = $clientService;
        $this->logger = $logger;
    }

    public function createSeries(Series $series): void
    {
        // TTK-21470: Since having a series in an Opencast object is not required, but it is in PuMuKIT
        // we need THIS series to not be synced to Opencast. Ideally series would be OPTIONAL.
        if ('default' === $series->getProperty('opencast')) {
            return;
        }

        try {
            $output = $this->clientService->createOpencastSeries($series);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());

            return;
        }

        $seriesOpencastId = json_decode($output['var'], true)['identifier'];
        $series->setProperty('opencast', $seriesOpencastId);
        $this->dm->persist($series);
        $this->dm->flush();
    }

    public function updateSeries(Series $series): void
    {
        // TTK-21470: Since having a series in an Opencast object is not required, but it is in PuMuKIT
        // we need THIS series to not be synced to Opencast. Ideally series would be OPTIONAL.
        if ('default' === $series->getProperty('opencast')) {
            return;
        }

        try {
            $this->clientService->updateOpencastSeries($series);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            if (404 !== $e->getCode()) {
                return;
            }
            $this->createSeries($series);
        }
    }

    public function deleteSeries(Series $series): void
    {
        try {
            $this->clientService->deleteOpencastSeries($series);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
        }
    }
}
