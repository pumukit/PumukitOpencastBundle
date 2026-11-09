<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\EventListener;

use Pumukit\OpencastBundle\Services\SeriesSyncService;
use Pumukit\SchemaBundle\Event\SchemaEvents;
use Pumukit\SchemaBundle\Event\SeriesEvent;

class SeriesListener
{
    public function __construct(
        private readonly SeriesSyncService $seriesSyncService,
        private readonly bool $syncSeriesWithOpencast = false,
    ) {}

    public function onSeriesSync(SeriesEvent $event, string $eventName): void
    {
        if (!$this->syncSeriesWithOpencast) {
            return;
        }

        $series = $event->getSeries();
        match ($eventName) {
            SchemaEvents::SERIES_CREATE, SchemaEvents::SERIES_UPDATE => $this->seriesSyncService->updateSeries($series),
            SchemaEvents::SERIES_DELETE => $this->seriesSyncService->deleteSeries($series),
            default => null,
        };
    }
}
