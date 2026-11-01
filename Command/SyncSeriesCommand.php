<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\OpencastBundle\Services\ClientServiceFactory;
use Pumukit\OpencastBundle\Services\SeriesImportService;
use Pumukit\OpencastBundle\Services\SeriesSyncService;
use Pumukit\SchemaBundle\Document\Series;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'pumukit:opencast:sync:series', description: 'Create in an Opencast server the PuMuKIT series that it does not have')]
class SyncSeriesCommand extends AbstractRemoteOpencastCommand
{
    public function __construct(
        ClientServiceFactory $clientServiceFactory,
        private readonly DocumentManager $documentManager,
        private readonly SeriesSyncService $seriesSyncService,
        int $secondsToSleep = 1,
    ) {
        parent::__construct($clientServiceFactory, $secondsToSleep);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(<<<'EOT'
            Checks that every PuMuKIT series with an Opencast id exists in the given Opencast server. With --force, the
            missing series are created in that server and the PuMuKIT series gets the new Opencast id.

              <info>php bin/console pumukit:opencast:sync:series --host=https://opencast.example.org -u user -p password --force</info>
            EOT);
    }

    protected function process(): int
    {
        $criteria = ['properties.opencast' => ['$exists' => true, '$ne' => SeriesImportService::DEFAULT_SERIES]];
        if ($this->id) {
            $criteria['_id'] = new ObjectId($this->id);
        }
        $allSeries = $this->documentManager->getRepository(Series::class)->findBy($criteria);
        $this->io->section(sprintf('%d series with an Opencast id', count($allSeries)));

        $errors = 0;
        foreach ($allSeries as $series) {
            $opencastId = (string) $series->getProperty('opencast');
            $this->pause();

            try {
                if (null !== $this->remote->getSeries($opencastId)) {
                    $this->io->text(sprintf(' %s - %s: exists', $series->getId(), $opencastId));

                    continue;
                }
                if (!$this->force) {
                    $this->io->text(sprintf(' <comment>%s - %s: missing</comment>', $series->getId(), $opencastId));

                    continue;
                }
                $newId = $this->remote->createSeries($this->seriesSyncService->text($series->getTitle()), $this->seriesSyncService->text($series->getDescription()));
                $series->setProperty('opencast', $newId);
                $this->documentManager->flush();
                $this->io->text(sprintf(' %s - %s: created as %s', $series->getId(), $opencastId, $newId));
            } catch (\Throwable $exception) {
                ++$errors;
                $this->io->text(sprintf(' <error>%s - %s: %s</error>', $series->getId(), $opencastId, $exception->getMessage()));
            }
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
