<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\MediaPackage;

use PHPUnit\Framework\TestCase;
use Pumukit\OpencastBundle\Client\OpencastResponse;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;

/**
 * @internal
 *
 * @coversNothing
 */
class MediaPackageTest extends TestCase
{
    public function testThePublishedMediaPackageOfOpencast16IsRead(): void
    {
        $search = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/search.json'), true);
        $mediaPackage = MediaPackage::fromArray($search['result'][0]['mediapackage']);

        $this->assertSame('f50344d2-b74d-445a-9144-e1cc6352fef0', $mediaPackage->id());
        $this->assertSame('Clase de prueba', $mediaPackage->title());
        $this->assertSame('Matemáticas Álgebra', $mediaPackage->seriesTitle());
        $this->assertSame('es', $mediaPackage->language());
        $this->assertSame('2026-11-01', $mediaPackage->start()->format('Y-m-d'));
        $this->assertCount(1, $mediaPackage->tracks());
        $this->assertSame(['presenter/preview'], array_keys($mediaPackage->trackUrls()));
        $this->assertSame(['atom', 'default', 'engage-download', 'engage-streaming', 'rss'], MediaPackage::tags($mediaPackage->tracks()[0]));
        $this->assertStringEndsWith('oc-test_1.000s-search.jpg', $mediaPackage->thumbnail());
        $this->assertStringEndsWith('dublincore.xml', $mediaPackage->episodeCatalogUrl());
        $this->assertNull($mediaPackage->galicasterPropertiesUrl());
    }

    public function testTheArchivedMediaPackageXmlKeepsTheAttributes(): void
    {
        $mediaPackage = MediaPackage::fromArray((new OpencastResponse('assets', 200, (string) file_get_contents(__DIR__.'/../Fixtures/assets.xml')))->xml());

        $this->assertSame('f50344d2-b74d-445a-9144-e1cc6352fef0', $mediaPackage->id());
        $this->assertSame(8000, $mediaPackage->duration());
        $this->assertSame('presenter/source', $mediaPackage->tracks()[0]['type']);
        $this->assertStringContainsString('/assets/assets/', $mediaPackage->trackUrls()['presenter/source']);
    }

    public function testListsAndSingleElementsAreNormalized(): void
    {
        $mediaPackage = MediaPackage::fromArray([
            'id' => 'mp',
            'media' => ['track' => [['id' => 't1', 'type' => 'presenter/delivery', 'url' => 'a'], ['id' => 't2', 'type' => 'presentation/delivery', 'url' => 'b']]],
            'attachments' => ['attachment' => ['id' => 'galicaster-properties', 'type' => 'galicaster/properties', 'url' => 'https://gc/galicaster.json', 'tags' => ['tag' => 'archive']]],
        ]);

        $this->assertCount(2, $mediaPackage->tracks());
        $this->assertCount(1, $mediaPackage->attachments());
        $this->assertSame('https://gc/galicaster.json', $mediaPackage->galicasterPropertiesUrl());
        $this->assertSame(['archive'], MediaPackage::tags($mediaPackage->attachments()[0]));
        $this->assertSame([], $mediaPackage->segments());
    }

    public function testTheSegmentsAreReadFromTheSegmentPreviewsOfOpencast16(): void
    {
        $mediaPackage = MediaPackage::fromArray([
            'id' => 'mp',
            'duration' => 120000,
            'attachments' => ['attachment' => [
                ['type' => 'presentation/segment+preview', 'ref' => 'track:t1;time=T00:01:40:0F1000', 'url' => 'https://oc/100.jpg'],
                ['type' => 'presenter/segment+preview', 'ref' => 'track:t2;time=T00:00:10:0F1000', 'url' => 'https://oc/presenter.jpg'],
                ['type' => 'presentation/segment+preview', 'ref' => 'track:t1;time=T00:00:00:0F1000', 'url' => 'https://oc/0.jpg'],
                ['type' => 'presentation/segment+preview', 'ref' => 'track:t1;time=T00:00:04:12F25', 'url' => 'https://oc/4.jpg'],
                ['type' => 'presentation/segment+preview', 'ref' => 'track:t1', 'url' => 'https://oc/without-time.jpg'],
                ['type' => 'presentation/player+preview', 'ref' => 'track:t1;time=T00:00:30:0F1000', 'url' => 'https://oc/player.jpg'],
            ]],
        ]);

        $this->assertSame([
            ['index' => 0, 'time' => 0, 'duration' => 4480, 'preview' => 'https://oc/0.jpg'],
            ['index' => 1, 'time' => 4480, 'duration' => 95520, 'preview' => 'https://oc/4.jpg'],
            ['index' => 2, 'time' => 100000, 'duration' => 20000, 'preview' => 'https://oc/100.jpg'],
        ], $mediaPackage->segments());
    }

    public function testThePresenterSegmentsAreUsedWithoutPresentationSegments(): void
    {
        $mediaPackage = MediaPackage::fromArray(['id' => 'mp', 'attachments' => ['attachment' => [
            'type' => 'presenter/segment+preview', 'ref' => 'track:t2;time=T00:00:10:0F1000', 'url' => 'https://oc/presenter.jpg',
        ]]]);

        $this->assertSame([['index' => 0, 'time' => 10000, 'duration' => 0, 'preview' => 'https://oc/presenter.jpg']], $mediaPackage->segments());
    }

    public function testTheModifiedDateOfTheSearchResultIsKept(): void
    {
        $mediaPackage = MediaPackage::fromArray(['id' => 'mp'])->withModified('2026-11-01T08:57:55.709312891Z');

        $this->assertSame('2026-11-01', $mediaPackage->modified()->format('Y-m-d'));
    }

    public function testAMediaPackageNeedsAnId(): void
    {
        $this->expectException(OpencastException::class);

        MediaPackage::fromArray(['title' => 'No id']);
    }
}
