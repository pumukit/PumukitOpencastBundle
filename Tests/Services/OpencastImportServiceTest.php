<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\Services;

use Psr\Log\NullLogger;
use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\EncoderBundle\Services\JobCreator;
use Pumukit\EncoderBundle\Services\ProfileService;
use Pumukit\InspectionBundle\Services\InspectionFfprobeService;
use Pumukit\OpencastBundle\Event\ImportEvent;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\OpencastBundle\Services\OpencastService;
use Pumukit\OpencastBundle\Services\SeriesImportService;
use Pumukit\OpencastBundle\Services\SeriesSyncService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;
use Pumukit\SchemaBundle\Services\SeriesEventDispatcherService;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\SchemaBundle\Services\TrackService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 *
 * @coversNothing
 */
class OpencastImportServiceTest extends PumukitTestCase
{
    private array $events = [];
    private array $galicaster = [];

    public function testARecordingIsImportedIntoItsOpencastSeries(): void
    {
        $multimediaObject = $this->service()->importRecordingFromMediaPackage($this->mediaPackage());

        $this->assertSame('Clase de prueba', $multimediaObject->getTitle());
        $this->assertSame('f50344d2-b74d-445a-9144-e1cc6352fef0', $multimediaObject->getProperty('opencast'));
        $this->assertSame('https://oc.example.org/paella7/ui/watch.html?mode=embed&id=f50344d2-b74d-445a-9144-e1cc6352fef0', $multimediaObject->getProperty('opencasturl'));
        $this->assertSame('slide_professor', $multimediaObject->getProperty('paellalayout'));
        $this->assertSame('2026-11-01', $multimediaObject->getRecordDate()->format('Y-m-d'));
        $this->assertSame('Matemáticas Álgebra', $multimediaObject->getSeries()->getTitle());
        $this->assertSame('67da045a-d612-4b80-9b24-7517154b2d32', $multimediaObject->getSeries()->getProperty('opencast'));

        $track = $multimediaObject->getTracks()[0];
        $this->assertTrue($track->tags()->containsAllTags(['opencast', 'display', 'presenter/preview', 'engage-streaming']));
        $this->assertSame(9, $track->metadata()->duration());
        $this->assertSame(9, $multimediaObject->getDuration());
        $this->assertTrue($multimediaObject->isVideoType());
        $this->assertCount(1, $multimediaObject->getPics());
        $this->assertCount(1, $this->events);
    }

    public function testImportingAgainSynchronizesTheTracksWithoutDuplicates(): void
    {
        $first = $this->service()->importRecordingFromMediaPackage($this->mediaPackage());

        $data = $this->mediaPackage()->toArray();
        $data['media']['track']['url'] = 'https://oc.example.org/static/new/oc-test.mp4';
        $second = $this->service()->importRecordingFromMediaPackage(MediaPackage::fromArray($data));

        $this->assertSame($first->getId(), $second->getId());
        $this->assertCount(1, $this->dm->getRepository(MultimediaObject::class)->findBy(['properties.opencast' => $first->getProperty('opencast')]));
        $this->assertSame('https://oc.example.org/static/new/oc-test.mp4', $second->getTracks()[0]->storage()->url()->url());
        $this->assertCount(1, $this->events);
    }

    public function testARecordingWithoutSeriesGoesToTheDefaultSeries(): void
    {
        $data = $this->mediaPackage()->toArray();
        unset($data['series'], $data['seriestitle'], $data['metadata']);

        $multimediaObject = $this->service()->importRecordingFromMediaPackage(MediaPackage::fromArray($data), true);

        $this->assertSame(SeriesImportService::DEFAULT_SERIES, $multimediaObject->getSeries()->getProperty('opencast'));
        $this->assertSame('professor_slide', $multimediaObject->getProperty('paellalayout'));
    }

    public function testTheGalicasterTargetMultimediaObjectIsCompleted(): void
    {
        $series = self::getContainer()->get(FactoryService::class)->createSeries();
        $target = self::getContainer()->get(FactoryService::class)->createMultimediaObject($series);
        $this->galicaster = ['galicaster' => ['properties' => ['pmk_mmobj' => $target->getId()]]];

        $multimediaObject = $this->service()->importRecordingFromMediaPackage($this->mediaPackage());

        $this->assertSame($target->getId(), $multimediaObject->getId());
        $this->assertSame($series->getId(), $multimediaObject->getSeries()->getId());
        $this->assertSame(['properties' => ['pmk_mmobj' => $target->getId()]], $multimediaObject->getProperty('galicaster'));
    }

    public function testTheSeriesTextKeepsTheAccentsAndRemovesTheHtml(): void
    {
        $sync = new SeriesSyncService($this->dm, $this->createStub(ClientService::class), new NullLogger());

        $this->assertSame('Matemáticas: Álgebra y Señales (2º curso) & cía.', $sync->text("Matemáticas: Álgebra y Señales (2º curso)\n <b>&amp; cía.</b>"));
    }

    private function mediaPackage(): MediaPackage
    {
        $search = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/search.json'), true);

        return MediaPackage::fromArray($search['result'][0]['mediapackage']);
    }

    private function service(): OpencastImportService
    {
        $container = self::getContainer();
        $client = $this->createStub(ClientService::class);
        $client->method('getPlayerUrl')->willReturn('https://oc.example.org/paella7/ui/watch.html');
        $client->method('getGalicasterProperties')->willReturnCallback(fn () => $this->galicaster);
        $client->method('getSpatialField')->willReturn(null);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ImportEvent::class, function (ImportEvent $event): void {
            $this->events[] = $event;
        });

        $opencastService = new OpencastService($container->get(JobCreator::class), $container->get(ProfileService::class), $container->get(MultimediaObjectService::class), [], [], false);
        $seriesImportService = new SeriesImportService($this->dm, $container->get(FactoryService::class), $client, $container->get(SeriesEventDispatcherService::class));

        return new OpencastImportService(
            $this->dm,
            $container->get(FactoryService::class),
            new NullLogger(),
            $container->get(TranslatorInterface::class),
            $container->get(TrackService::class),
            $container->get(TagService::class),
            $container->get(MultimediaObjectService::class),
            $client,
            $opencastService,
            $container->get(InspectionFfprobeService::class),
            $seriesImportService,
            $dispatcher,
            customLanguages: ['es', 'en'],
        );
    }
}
