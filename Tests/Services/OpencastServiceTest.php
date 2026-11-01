<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Tests\Services;

use Pumukit\EncoderBundle\Services\JobCreator;
use Pumukit\EncoderBundle\Services\ProfileService;
use Pumukit\OpencastBundle\Services\OpencastService;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class OpencastServiceTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/opencast-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/archive/mp/3', 0777, true);
        mkdir($this->dir.'/downloads/engage-player/mp/t1', 0777, true);
        touch($this->dir.'/archive/mp/3/t1.mp4');
        touch($this->dir.'/downloads/engage-player/mp/t1/video.mp4');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testThePublishedUrlIsMappedToThePath(): void
    {
        $this->assertSame($this->dir.'/downloads/engage-player/mp/t1/video.mp4', $this->service()->getPath('https://oc.example.org/static/mh_default_org/engage-player/mp/t1/video.mp4'));
    }

    public function testTheArchiveUrlIsMappedToTheStorageLayout(): void
    {
        $this->assertSame($this->dir.'/archive/mp/3/t1.mp4', $this->service()->getPath('https://oc.example.org/assets/assets/mp/t1/3/video.mp4'));
    }

    public function testAMissingFileFailsOrIsNullAsConfigured(): void
    {
        $this->assertNull($this->service(false)->getPath('https://oc.example.org/static/mh_default_org/engage-player/mp/t2/none.mp4'));

        $this->expectException(\RuntimeException::class);
        $this->service()->getPath('https://oc.example.org/static/mh_default_org/engage-player/mp/t2/none.mp4');
    }

    public function testTheSbsNeedsAProfile(): void
    {
        $this->assertFalse($this->service(sbs: ['generate_sbs' => true, 'profile' => ''])->isSbsEnabled());
        $this->assertTrue($this->service(sbs: ['generate_sbs' => true, 'profile' => 'sbs'])->isSbsEnabled());
    }

    private function service(bool $errorIfFileNotExist = true, array $sbs = []): OpencastService
    {
        return new OpencastService(
            self::getContainer()->get(JobCreator::class),
            self::getContainer()->get(ProfileService::class),
            self::getContainer()->get(MultimediaObjectService::class),
            $sbs,
            [
                ['url' => 'https://oc.example.org/static/mh_default_org/', 'path' => $this->dir.'/downloads/'],
                ['url' => 'https://oc.example.org/assets/assets/', 'path' => $this->dir.'/archive/'],
            ],
            $errorIfFileNotExist,
        );
    }
}
