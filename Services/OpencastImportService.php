<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Pumukit\InspectionBundle\Services\InspectionFfprobeService;
use Pumukit\OpencastBundle\Event\ImportEvent;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\MediaPackage\MediaPackage;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\MediaMetadata;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\VideoAudio;
use Pumukit\SchemaBundle\Document\MediaType\Storage;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Pic;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Document\ValueObject\i18nText;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Document\ValueObject\StorageUrl;
use Pumukit\SchemaBundle\Document\ValueObject\Tags;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\SchemaBundle\Services\TrackService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[WithMonologChannel('opencast')]
class OpencastImportService
{
    public const PIC_TYPES = ['presenter/player+preview', 'presenter/search+preview'];

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly FactoryService $factoryService,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly TrackService $trackService,
        private readonly TagService $tagService,
        private readonly MultimediaObjectService $multimediaObjectService,
        private readonly ClientService $clientService,
        private readonly OpencastService $opencastService,
        private readonly InspectionFfprobeService $inspectionService,
        private readonly SeriesImportService $seriesImportService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly string $defaultTagImported = 'TECHOPENCAST',
        private readonly array $otherLocales = [],
        private readonly array $customLanguages = [],
        private readonly string $locale = 'en',
    ) {}

    public function importRecording(string $opencastId, bool $invert = false, ?User $loggedInUser = null): MultimediaObject
    {
        $mediaPackage = $this->clientService->getMediaPackage($opencastId);
        if (null === $mediaPackage) {
            throw new OpencastException(sprintf('The mediapackage "%s" is not published in Opencast', $opencastId), 404);
        }

        return $this->importRecordingFromMediaPackage($mediaPackage, $invert, $loggedInUser);
    }

    public function importRecordingFromMediaPackage(MediaPackage $mediaPackage, bool $invert = false, ?User $loggedInUser = null): MultimediaObject
    {
        $repository = $this->documentManager->getRepository(MultimediaObject::class);

        $multimediaObject = $repository->findOneBy(['properties.opencast' => $mediaPackage->id()]);
        if ($multimediaObject instanceof MultimediaObject) {
            $this->syncTracks($multimediaObject, $mediaPackage);
            $this->syncPics($multimediaObject, $mediaPackage);
            $this->multimediaObjectService->updateMultimediaObject($multimediaObject);
            $this->logger->info('Opencast mediapackage synchronized', ['mediapackage' => $mediaPackage->id(), 'multimedia_object' => $multimediaObject->getId()]);

            return $multimediaObject;
        }

        $galicasterProperties = $this->clientService->getGalicasterProperties($mediaPackage);
        $template = null;
        if (isset($galicasterProperties['galicaster']['properties']['pmk_mmobj'])) {
            $template = $repository->find($galicasterProperties['galicaster']['properties']['pmk_mmobj']);
        }

        $language = $this->getLanguage($mediaPackage);
        $tracks = array_map(fn (array $track) => $this->createTrack($track, $language), $mediaPackage->tracks());

        $multimediaObject = $template instanceof MultimediaObject
            ? $this->fromTemplate($template)
            : $this->createMultimediaObject($mediaPackage, $loggedInUser);

        foreach ($tracks as $track) {
            $this->trackService->addTrackToMultimediaObject($multimediaObject, $track, false);
        }

        $this->setOpencastProperties($multimediaObject, $mediaPackage, $invert, $language);
        if (isset($galicasterProperties['galicaster'])) {
            $multimediaObject->setProperty('galicaster', $galicasterProperties['galicaster']);
        }
        if ($start = $mediaPackage->start()) {
            $multimediaObject->setRecordDate(\DateTime::createFromImmutable($start));
        }
        if (0 === $multimediaObject->getPics()->count()) {
            $this->addPics($multimediaObject, $mediaPackage);
        }

        $tag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $this->defaultTagImported]);
        if ($tag instanceof Tag) {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $tag->getId());
        }

        $multimediaObject = $this->multimediaObjectService->updateMultimediaObject($multimediaObject);

        if ($multimediaObject->getTracks()->count() > 0) {
            $this->opencastService->genAutoSbs($multimediaObject, $mediaPackage->trackUrls());
        }

        $this->logger->info('Opencast mediapackage imported', ['mediapackage' => $mediaPackage->id(), 'multimedia_object' => $multimediaObject->getId(), 'template' => $template?->getId()]);
        $this->dispatcher->dispatch(new ImportEvent($multimediaObject, $mediaPackage));

        return $multimediaObject;
    }

    public function importIntoMultimediaObject(MultimediaObject $multimediaObject, MediaPackage $mediaPackage, bool $invert = false, ?string $language = null): MultimediaObject
    {
        $language = $this->getLanguage($mediaPackage, $language);
        $this->setOpencastProperties($multimediaObject, $mediaPackage, $invert, $language);
        $this->importTracks($mediaPackage, $multimediaObject, ['display'], $language);

        return $this->multimediaObjectService->updateMultimediaObject($multimediaObject);
    }

    public function importTracks(MediaPackage $mediaPackage, MultimediaObject $multimediaObject, array $trackTags = ['display'], ?string $language = null): int
    {
        $language = $this->getLanguage($mediaPackage, $language);
        $imported = 0;
        foreach ($mediaPackage->tracks() as $opencastTrack) {
            if (false !== stripos((string) ($opencastTrack['url'] ?? ''), 'rtmp:')) {
                continue;
            }
            $this->trackService->addTrackToMultimediaObject($multimediaObject, $this->createTrack($opencastTrack, $language, $trackTags), false);
            ++$imported;
        }

        return $imported;
    }

    public function createTrack(array $opencastTrack, string $language, array $trackTags = ['display']): Track
    {
        $url = (string) ($opencastTrack['url'] ?? '');
        if ('' === $url) {
            throw new OpencastException(sprintf('The Opencast track "%s" has no url', $opencastTrack['id'] ?? ''));
        }

        $tags = Tags::create(MediaPackage::tags($opencastTrack));
        $tags->add('opencast');
        foreach ($trackTags as $tag) {
            $tags->add($tag);
        }
        if (!empty($opencastTrack['type'])) {
            $tags->add((string) $opencastTrack['type']);
        }

        $description = $opencastTrack['description'] ?? '';
        $description = is_array($description) ? i18nText::create($description) : i18nText::create(['en' => (string) $description]);

        [$storage, $metadata] = $this->storageAndMetadata($opencastTrack);
        $originalName = (string) ($opencastTrack['originalName'] ?? basename((string) parse_url($url, PHP_URL_PATH)));

        return Track::create($originalName, $description, $language, $tags, false, false, 0, $storage, $metadata);
    }

    public function syncTracks(MultimediaObject $multimediaObject, ?MediaPackage $mediaPackage = null): void
    {
        $mediaPackage ??= $this->fetchMediaPackage($multimediaObject);
        if (null === $mediaPackage) {
            return;
        }

        foreach ($mediaPackage->tracks() as $opencastTrack) {
            if (empty($opencastTrack['type']) || empty($opencastTrack['url'])) {
                continue;
            }
            $track = $multimediaObject->getTrackWithAllTags(['opencast', $opencastTrack['type']]);
            if (!$track instanceof Track) {
                continue;
            }
            [$storage, $metadata] = $this->storageAndMetadata($opencastTrack);
            $track->updateStorage($storage);
            $track->updateMetadata($metadata);
        }
    }

    public function syncPics(MultimediaObject $multimediaObject, ?MediaPackage $mediaPackage = null): void
    {
        $mediaPackage ??= $this->fetchMediaPackage($multimediaObject);
        if (null === $mediaPackage) {
            return;
        }

        foreach ($mediaPackage->attachments() as $attachment) {
            if (empty($attachment['type']) || empty($attachment['url'])) {
                continue;
            }
            $pic = $multimediaObject->getPicWithAllTags(['opencast', $attachment['type']]);
            if ($pic instanceof Pic) {
                $pic->setUrl((string) $attachment['url']);
            }
        }
    }

    public function getOpencastUrls(?string $opencastId): array
    {
        if (null === $opencastId || '' === $opencastId) {
            return [];
        }

        try {
            return $this->clientService->getMasterMediaPackage($opencastId)?->trackUrls() ?? [];
        } catch (OpencastException $exception) {
            $this->logger->warning('Archived mediapackage not read', ['mediapackage' => $opencastId, 'error' => $exception->getMessage()]);

            return [];
        }
    }

    public function getLanguage(MediaPackage $mediaPackage, ?string $defaultLanguage = null): string
    {
        $language = $mediaPackage->language();
        if (null !== $language) {
            $parsed = \Locale::parseLocale($language)['language'] ?? null;
            if ($parsed && (!$this->customLanguages || in_array($parsed, $this->customLanguages, true))) {
                return $parsed;
            }
        }

        return $defaultLanguage ?: $this->locale;
    }

    private function fetchMediaPackage(MultimediaObject $multimediaObject): ?MediaPackage
    {
        $id = $multimediaObject->getProperty('opencast');
        if (!$id) {
            return null;
        }

        $mediaPackage = $this->clientService->getMediaPackage((string) $id);
        if (null === $mediaPackage) {
            throw new OpencastException(sprintf('The mediapackage "%s" is not published in Opencast', $id), 404);
        }

        return $mediaPackage;
    }

    private function createMultimediaObject(MediaPackage $mediaPackage, ?User $loggedInUser): MultimediaObject
    {
        $series = $this->seriesImportService->importSeries($mediaPackage, $loggedInUser);
        $owner = $loggedInUser ?? $this->getSeriesOwner($series);
        $multimediaObject = $this->factoryService->createMultimediaObject($series, true, $owner);

        $title = $mediaPackage->title();
        if (null !== $title) {
            $multimediaObject->setTitle($title);
            foreach ($this->otherLocales as $locale) {
                $multimediaObject->setTitle($title, $locale);
            }
        }

        return $multimediaObject;
    }

    private function fromTemplate(MultimediaObject $template): MultimediaObject
    {
        if (0 === $template->getTracks()->count()) {
            return $template;
        }

        $multimediaObject = $this->factoryService->cloneMultimediaObject($template, $template->getSeries(), false);
        $multimediaObject->setStatus($template->getStatus());
        $multimediaObject->setComments($this->translator->trans('From Opencast. Used "%title%" (%id%) as template.', ['%title%' => $template->getTitle(), '%id%' => $template->getId()]));
        foreach ($multimediaObject->getTracks() as $track) {
            $multimediaObject->removeTrack($track);
        }
        foreach ($multimediaObject->getPics() as $pic) {
            $multimediaObject->removePic($pic);
        }

        return $multimediaObject;
    }

    private function setOpencastProperties(MultimediaObject $multimediaObject, MediaPackage $mediaPackage, bool $invert, string $language): void
    {
        $multimediaObject->setProperty('opencast', $mediaPackage->id());
        $multimediaObject->setProperty('opencasturl', $this->clientService->getPlayerUrl().'?mode=embed&id='.$mediaPackage->id());
        $multimediaObject->setProperty('opencastlanguage', $language);
        $multimediaObject->setProperty('opencastinvert', $invert);
        $multimediaObject->setProperty('paellalayout', $invert ? 'professor_slide' : 'slide_professor');
    }

    private function addPics(MultimediaObject $multimediaObject, MediaPackage $mediaPackage): void
    {
        foreach (self::PIC_TYPES as $type) {
            foreach ($mediaPackage->attachmentsOfType($type) as $attachment) {
                if (empty($attachment['url'])) {
                    continue;
                }
                $pic = new Pic();
                $pic->addTag('opencast');
                $pic->addTag($type);
                foreach (MediaPackage::tags($attachment) as $tag) {
                    $pic->addTag($tag);
                }
                $pic->setUrl((string) $attachment['url']);
                $multimediaObject->addPic($pic);
            }
            if ($multimediaObject->getPics()->count() > 0) {
                return;
            }
        }
    }

    private function storageAndMetadata(array $opencastTrack): array
    {
        $url = (string) $opencastTrack['url'];
        $path = $this->opencastService->getPath($url);
        $storage = Storage::create(StorageUrl::create($url), null === $path ? null : Path::create($path));

        return [$storage, $this->metadata($opencastTrack, $path)];
    }

    private function metadata(array $opencastTrack, ?string $path): MediaMetadata
    {
        if (null !== $path) {
            try {
                return VideoAudio::create($this->inspectionService->getFileMetadataAsString(Path::create($path)));
            } catch (\Exception $exception) {
                $this->logger->warning('Track file not inspected, using the Opencast metadata', ['path' => $path, 'error' => $exception->getMessage()]);
            }
        }

        $streams = [];
        if (isset($opencastTrack['video'])) {
            [$width, $height] = array_map('intval', explode('x', (string) ($opencastTrack['video']['resolution'] ?? '0x0')) + [0, 0]);
            $streams[] = ['codec_type' => 'video', 'width' => $width, 'height' => $height];
        }
        if (isset($opencastTrack['audio'])) {
            $streams[] = ['codec_type' => 'audio'];
        }

        return VideoAudio::create(json_encode([
            'format' => ['duration' => (string) (((int) ($opencastTrack['duration'] ?? 0)) / 1000)],
            'streams' => $streams,
        ], JSON_THROW_ON_ERROR));
    }

    private function getSeriesOwner(Series $series): ?User
    {
        $prototype = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
            'series' => new ObjectId($series->getId()),
            'status' => MultimediaObject::STATUS_PROTOTYPE,
        ]);
        $person = $prototype?->getPeopleByRoleCod('owner', true)[0] ?? null;
        if (null === $person) {
            return null;
        }

        return $this->documentManager->getRepository(User::class)->findOneBy(['person' => $person->getId()]);
    }
}
