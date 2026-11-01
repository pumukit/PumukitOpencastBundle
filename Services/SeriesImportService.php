<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\SeriesEventDispatcherService;

class SeriesImportService
{
    public const DEFAULT_SERIES = 'default';
    public const DEFAULT_SERIES_TITLE = 'MediaPackages without series';

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly FactoryService $factoryService,
        private readonly ClientService $clientService,
        private readonly SeriesEventDispatcherService $seriesDispatcher,
        private readonly array $otherLocales = [],
    ) {}

    public function importSeries(MediaPackage $mediaPackage, ?User $loggedInUser = null): Series
    {
        $repository = $this->documentManager->getRepository(Series::class);

        $seriesId = $mediaPackage->seriesId();
        if (null !== $seriesId) {
            return $repository->findOneBy(['properties.opencast' => $seriesId])
                ?? $this->createSeries($mediaPackage->seriesTitle() ?? $seriesId, 'opencast', $seriesId, $loggedInUser);
        }

        $spatial = $this->clientService->getSpatialField($mediaPackage);
        if (null !== $spatial) {
            return $repository->findOneBy(['properties.opencastspatial' => $spatial])
                ?? $this->createSeries($spatial, 'opencastspatial', $spatial, $loggedInUser);
        }

        return $repository->findOneBy(['properties.opencast' => self::DEFAULT_SERIES])
            ?? $this->createSeries(self::DEFAULT_SERIES_TITLE, 'opencast', self::DEFAULT_SERIES, $loggedInUser);
    }

    private function createSeries(string $title, string $property, string $value, ?User $loggedInUser): Series
    {
        $series = $this->factoryService->doCreateCollection(Series::TYPE_SERIES, $loggedInUser);
        $series->setPublicDate(new \DateTime());
        $series->setTitle($title);
        foreach ($this->otherLocales as $locale) {
            $series->setTitle($title, $locale);
        }
        $series->setProperty($property, $value);

        $this->documentManager->flush();
        $this->seriesDispatcher->dispatchCreate($series);

        return $series;
    }
}
