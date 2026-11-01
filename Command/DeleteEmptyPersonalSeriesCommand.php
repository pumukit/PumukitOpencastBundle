<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use Pumukit\OpencastBundle\Services\ClientServiceFactory;
use Pumukit\OpencastBundle\Services\SeriesImportService;
use Pumukit\SchemaBundle\Document\Series;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'pumukit:opencast:delete:empty:personal:series', description: 'Delete from an Opencast server the empty personal series and the empty "Nuevo" series of Galicaster')]
class DeleteEmptyPersonalSeriesCommand extends AbstractRemoteOpencastCommand
{
    public const TITLES = ['Videos of ', 'Nuevo'];

    public function __construct(
        ClientServiceFactory $clientServiceFactory,
        private readonly DocumentManager $documentManager,
        private readonly string $locale = 'es',
        int $secondsToSleep = 1,
    ) {
        parent::__construct($clientServiceFactory, $secondsToSleep);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(<<<'EOT'
            Deletes from the given Opencast server the series whose PuMuKIT title contains "Videos of " (personal series)
            or "Nuevo" (series created by Galicaster) and that have no multimedia objects. The PuMuKIT series are kept.

              <info>php bin/console pumukit:opencast:delete:empty:personal:series --host=https://opencast.example.org -u user -p password --force</info>
            EOT);
    }

    protected function process(): int
    {
        $criteria = [
            'properties.opencast' => ['$exists' => true, '$ne' => SeriesImportService::DEFAULT_SERIES],
            '$or' => array_map(fn (string $title) => ['title.'.$this->locale => new Regex(preg_quote($title, '/'), 'i')], self::TITLES),
        ];
        if ($this->id) {
            $criteria['_id'] = new ObjectId($this->id);
        }
        $repository = $this->documentManager->getRepository(Series::class);
        $allSeries = array_filter($repository->findBy($criteria), static fn (Series $series) => 0 === $repository->countMultimediaObjects($series));
        $this->io->section(sprintf('%d empty series', count($allSeries)));

        $errors = 0;
        foreach ($allSeries as $series) {
            $opencastId = (string) $series->getProperty('opencast');
            $this->pause();

            try {
                if (null === $this->remote->getSeries($opencastId)) {
                    $this->io->text(sprintf(' %s - %s: not in Opencast', $series->getId(), $opencastId));

                    continue;
                }
                if ($this->force) {
                    $this->remote->deleteSeries($opencastId);
                }
                $this->io->text(sprintf(' %s - %s (%s): %s', $series->getId(), $opencastId, $series->getTitle($this->locale), $this->force ? 'deleted' : 'will be deleted'));
            } catch (\Throwable $exception) {
                ++$errors;
                $this->io->text(sprintf(' <error>%s - %s: %s</error>', $series->getId(), $opencastId, $exception->getMessage()));
            }
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
