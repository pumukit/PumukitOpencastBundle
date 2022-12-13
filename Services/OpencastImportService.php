<?php

namespace Pumukit\OpencastBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\InspectionBundle\Services\InspectionServiceInterface;
use Pumukit\OpencastBundle\Event\ImportEvent;
use Pumukit\OpencastBundle\Event\OpencastEvents;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Pic;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\SchemaBundle\Services\TrackService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Translation\TranslatorInterface;

class OpencastImportService
{
    private $dm;
    private $factoryService;
    private $logger;
    private $translator;
    private $trackService;
    private $tagService;
    private $mmsService;
    private $opencastClient;
    private $opencastService;
    private $inspectionService;
    private $otherLocales;
    private $defaultTagImported;
    private $seriesImportService;
    private $customLanguages;
    private $dispatcher;

    public function __construct(
        DocumentManager $documentManager,
        FactoryService $factoryService,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        TrackService $trackService,
        TagService $tagService,
        MultimediaObjectService $mmsService,
        ClientService $opencastClient,
        OpencastService $opencastService,
        InspectionServiceInterface $inspectionService,
        array $otherLocales,
        string $defaultTagImported,
        SeriesImportService $seriesImportService,
        array $customLanguages,
        EventDispatcherInterface $dispatcher
    ) {
        $this->opencastClient = $opencastClient;
        $this->dm = $documentManager;
        $this->factoryService = $factoryService;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->trackService = $trackService;
        $this->tagService = $tagService;
        $this->mmsService = $mmsService;
        $this->opencastService = $opencastService;
        $this->inspectionService = $inspectionService;
        $this->otherLocales = $otherLocales;
        $this->defaultTagImported = $defaultTagImported;
        $this->seriesImportService = $seriesImportService;
        $this->customLanguages = $customLanguages;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Given a media package id
     * create a multimedia object
     * with the media package metadata.
     */
    public function importRecording(string $opencastId, ?bool $invert = false, ?User $loggedInUser = null): void
    {
        $mediaPackage = $this->opencastClient->getMediaPackage($opencastId);
        $this->importRecordingFromMediaPackage($mediaPackage, $invert, $loggedInUser);
    }

    /**
     * Given a media package
     * create a multimedia object
     * with the media package metadata.
     */
    public function importRecordingFromMediaPackage(array $mediaPackage, ?bool $invert = false, ?User $loggedInUser = null): void
    {
        $multimediaObject = null;
        $multimediaObjectRepository = $this->dm->getRepository(MultimediaObject::class);
        $mediaPackageId = $this->getMediaPackageField($mediaPackage, 'id');
        if ($mediaPackageId) {
            $multimediaObject = $multimediaObjectRepository->findOneBy(['properties.opencast' => $mediaPackageId]);
        }

        if (null !== $multimediaObject) {
            $this->syncTracks($multimediaObject, $mediaPackage);
            $this->syncPics($multimediaObject, $mediaPackage);
            $multimediaObject = $this->mmsService->updateMultimediaObject($multimediaObject);

            return;
        }

        //Check if mp has Galicaster properties and look for an mmobj with the given id.
        $galicasterPropertiesUrl = null;
        foreach ($mediaPackage['attachments']['attachment'] as $attachment) {
            if ('galicaster-properties' !== $attachment['id']) {
                continue;
            }
            $galicasterPropertiesUrl = $attachment['url'];

            break;
        }

        $galicasterProperties = [];
        if ($galicasterPropertiesUrl) {
            $galicasterProperties = $this->opencastClient->getGalicasterPropertiesFromUrl($galicasterPropertiesUrl);
        } else {
            $this->logger->warning(sprintf('No \'galicaster-properties\' id exist on attachments list from mediapackage.'));
            //NOTE: This will only work correctly if the mp was only ingested once. We need to figure out and pass it
            //the correct 'mediapackage version', but the endpoint with that info does not work currently.
            $galicasterProperties = $this->opencastClient->getGalicasterProperties($mediaPackageId);
        }

        if (isset($galicasterProperties['galicaster']['properties']['pmk_mmobj'])) {
            $multimediaObjectId = $galicasterProperties['galicaster']['properties']['pmk_mmobj'];
            $multimediaObject = $multimediaObjectRepository->find($multimediaObjectId);
        }

        //We try to initialize the tracks before anything to prevent importing if any tracks have wrong data
        $media = $this->getMediaPackageField($mediaPackage, 'media');
        $opencastTracks = $this->getMediaPackageField($media, 'track');
        $language = $this->getMediaPackageLanguage($mediaPackage);

        if (isset($opencastTracks['id'])) {
            // NOTE: Single track
            $opencastTracks = [$opencastTracks];
        }
        $tracks = [];
        $opencastUrls = [];
        foreach ($opencastTracks as $opencastTrack) {
            $tracks[] = $this->createTrackFromOpencastTrack($opencastTrack, $language);
            $opencastUrls = $this->addOpencastUrl($opencastUrls, $opencastTrack);
        }

        // - If the id does not exist, create a new mmobj
        if (null === $multimediaObject) {
            $series = $this->seriesImportService->importSeries($mediaPackage, $loggedInUser);

            $loggedInUser = $this->findOwnerForMultimediaObject($series);

            $multimediaObject = $this->factoryService->createMultimediaObject($series, true, $loggedInUser);
            $multimediaObject->setSeries($series);

            $title = $this->getMediaPackageField($mediaPackage, 'title');

            if ($title) {
                $multimediaObject->setTitle($title);
            }

            foreach ($this->otherLocales as $locale) {
                $multimediaObject->setTitle($title, $locale);
            }

            // -- If it exist, but already has tracks, clone the mmobj, but clear tracks/attachments NOTE: What about tags?
        } elseif (count($multimediaObject->getTracks()) > 0) {
            $newMultimediaObject = $this->factoryService->cloneMultimediaObject($multimediaObject, $multimediaObject->getSeries(), false);

            $newMultimediaObject->setStatus($multimediaObject->getStatus());

            $commentsText = $this->translator->trans(
                'From Opencast. Used "%title%" (%id%) as template.',
                ['%title%' => $multimediaObject->getTitle(), '%id%' => $multimediaObject->getId()]
            );
            $multimediaObject = $newMultimediaObject;
            $multimediaObject->setComments($commentsText);
            foreach ($multimediaObject->getTracks() as $track) {
                $multimediaObject->removeTrack($track);
            }
            foreach ($multimediaObject->getPics() as $pic) {
                $multimediaObject->removePic($pic);
            }
        }

        $multimediaObject->setProperty('opencastlanguage', $language);
        foreach ($tracks as $track) {
            $this->trackService->addTrackToMultimediaObject($multimediaObject, $track, false);
        }

        if (isset($galicasterProperties['galicaster'])) {
            $multimediaObject->setProperty('galicaster', $galicasterProperties['galicaster']);
        }

        // -- Then, add opencast object to mmobj
        $properties = $this->getMediaPackageField($mediaPackage, 'id');
        if ($properties) {
            $multimediaObject->setProperty('opencast', $properties);
            $multimediaObject->setProperty('opencasturl', $this->opencastClient->getPlayerUrl().'?mode=embed&id='.$properties);
        }

        // NOTE: Should this be added to the already created mmobj? I think not.
        if ($invert) {
            $multimediaObject->setProperty('opencastinvert', true);
            $multimediaObject->setProperty('paellalayout', 'professor_slide');
        } else {
            $multimediaObject->setProperty('opencastinvert', false);
            $multimediaObject->setProperty('paellalayout', 'slide_professor');
        }

        $recDate = $this->getMediaPackageField($mediaPackage, 'start');
        if ($recDate) {
            $multimediaObject->setRecordDate($recDate);
        }

        $attachments = $this->getMediaPackageField($this->getMediaPackageField($mediaPackage, 'attachments'), 'attachment');
        if (isset($attachments['id'])) {
            $attachments = [$attachments];
        }

        $attachmentsByType = [];
        foreach ($attachments as $attachment) {
            $type = $this->getMediaPackageField($attachment, 'type');
            if (null === $type) {
                continue;
            }
            $attachmentsByType[$type][] = $attachment;
        }
        $picTypes = ['presenter/player+preview', 'presenter/search+preview'];
        foreach ($picTypes as $picType) {
            if ($multimediaObject->getPics()->count() > 0) {
                break;
            }
            $picAttachments = $attachmentsByType[$picType] ?? [];
            foreach ($picAttachments as $attachment) {
                $multimediaObject = $this->addPicFromAttachment($multimediaObject, $attachment);
            }
        }

        $tagRepo = $this->dm->getRepository(Tag::class);
        $opencastTag = $tagRepo->findOneByCod($this->defaultTagImported);
        if ($opencastTag) {
            $tagService = $this->tagService;
            $tagAdded = $tagService->addTagToMultimediaObject($multimediaObject, $opencastTag->getId());
        }

        $multimediaObject = $this->mmsService->updateMultimediaObject($multimediaObject);

        if ($multimediaObject->getTracks()->count() > 0) {
            $this->opencastService->genAutoSbs($multimediaObject, $opencastUrls);
        }

        $event = new ImportEvent($multimediaObject);
        $this->dispatcher->dispatch(OpencastEvents::IMPORT_SUCCESS, $event);
    }

    public function getOpencastUrls($opencastId = ''): array
    {
        $opencastUrls = [];
        if (null !== $opencastId) {
            try {
                $archiveMediaPackage = $this->opencastClient->getMasterMediaPackage($opencastId);
            } catch (\Exception $e) {
                return $opencastUrls;
            }
            $media = $this->getMediaPackageField($archiveMediaPackage, 'media');
            $tracks = $this->getMediaPackageField($media, 'track');
            if (!isset($tracks['id'])) {
                // NOTE: Multiple tracks
                $limit = count($tracks);
                for ($i = 0; $i < $limit; ++$i) {
                    $track = $tracks[$i];
                    $opencastUrls = $this->addOpencastUrl($opencastUrls, $track);
                }
            } else {
                // NOTE: Single track
                $track = $tracks;
                $opencastUrls = $this->addOpencastUrl($opencastUrls, $track);
            }
        }

        return $opencastUrls;
    }

    public function getMediaPackageField($mediaFields = [], $field = '')
    {
        if ($mediaFields && $field && isset($mediaFields[$field])) {
            return $mediaFields[$field];
        }

        return null;
    }

    public function createTrackFromMediaPackage($mediaPackage, MultimediaObject $multimediaObject, $index = null, $trackTags = ['display'], $defaultLanguage = null): Track
    {
        $media = $this->getMediaPackageField($mediaPackage, 'media');
        $tracks = $this->getMediaPackageField($media, 'track');
        if ($tracks) {
            if (null === $index) {
                $opencastTrack = $tracks;
            } else {
                $opencastTrack = $tracks[$index];
            }
        } else {
            throw new \Exception(sprintf("No media track info in MP '%s'", $multimediaObject->getProperty('opencast')));
        }
        $language = $this->getMediaPackageLanguage($mediaPackage, $defaultLanguage);

        $track = $this->createTrackFromOpencastTrack($opencastTrack, $language, $trackTags);
        $multimediaObject->setDuration($track->getDuration());
        $this->trackService->addTrackToMultimediaObject($multimediaObject, $track, false);

        return $track;
    }

    public function createTrackFromOpencastTrack($opencastTrack, $language, $trackTags = ['display']): Track
    {
        $track = new Track();
        $track->setLanguage($language);

        $tagsArray = $this->getMediaPackageField($opencastTrack, 'tags');
        $tags = $this->getMediaPackageField($tagsArray, 'tag');
        if (!is_array($tags)) {
            // NOTE: Single tag
            $tags = [$tags];
        }

        $limit = count($tags);
        for ($i = 0; $i < $limit; ++$i) {
            $track = $this->addTagToTrack($tags, $track, $i);
        }

        $url = $this->getMediaPackageField($opencastTrack, 'url');
        if ($url) {
            $track->setUrl($url);
            $track->setPath($this->opencastService->getPath($url));
        }

        $mime = $this->getMediaPackageField($opencastTrack, 'mimetype');
        if ($mime) {
            $track->setMimeType($mime);
        }

        $duration = $this->getMediaPackageField($opencastTrack, 'duration');
        if ($duration) {
            $track->setDuration($duration / 1000);
        }

        $audio = $this->getMediaPackageField($opencastTrack, 'audio');
        $encoder = $this->getMediaPackageField($audio, 'encoder');
        $acodec = $this->getMediaPackageField($encoder, 'type');
        if ($acodec) {
            $track->setAcodec($acodec);
        }

        $video = $this->getMediaPackageField($opencastTrack, 'video');
        $encoder = $this->getMediaPackageField($video, 'encoder');
        $vcodec = $this->getMediaPackageField($encoder, 'type');
        if ($vcodec) {
            $track->setVcodec($vcodec);
        }

        $framerate = $this->getMediaPackageField($video, 'framerate');
        if ($framerate) {
            $track->setFramerate($framerate);
        }

        if (!$track->getVcodec() && $track->getAcodec()) {
            $track->setOnlyAudio(true);
        } else {
            $track->setOnlyAudio(false);
        }

        $track->addTag('opencast');
        foreach ($trackTags as $trackTag) {
            $track->addTag($trackTag);
        }

        $type = $this->getMediaPackageField($opencastTrack, 'type');
        if ($type) {
            $track->addTag($opencastTrack['type']);
        }

        if ($track->getPath()) {
            $this->inspectionService->autocompleteTrack($track);
        }

        return $track;
    }

    public function importTracksFromMediaPackage($mediaPackage, MultimediaObject $multimediaObject, $trackTags): void
    {
        $media = $this->getMediaPackageField($mediaPackage, 'media');
        $tracks = $this->getMediaPackageField($media, 'track');
        if (is_array($tracks) && isset($tracks[0])) {
            $limit = count($tracks);
            for ($i = 0; $i < $limit; ++$i) {
                if (false === stripos($tracks[$i]['url'], 'rtmp:')) {
                    $this->createTrackFromMediaPackage($mediaPackage, $multimediaObject, $i, $trackTags);
                }
            }
        } elseif (false === stripos($tracks['url'], 'rtmp:')) {
            $this->createTrackFromMediaPackage($mediaPackage, $multimediaObject, null, $trackTags);
        }
    }

    public function syncTracks(MultimediaObject $multimediaObject, $mediaPackage = null): void
    {
        $mediaPackageId = $multimediaObject->getProperty('opencast');
        if (!$mediaPackageId) {
            return;
        }

        if (!$mediaPackage) {
            $mediaPackage = $this->opencastClient->getMediaPackage($mediaPackageId);
        }

        if (!$mediaPackage) {
            throw new \Exception('Opencast communication error');
        }

        $media = $this->getMediaPackageField($mediaPackage, 'media');
        $tracks = $this->getMediaPackageField($media, 'track');
        if (is_array($tracks) && isset($tracks[0])) {
            // NOTE: Multiple tracks
            $limit = count($tracks);
            for ($i = 0; $i < $limit; ++$i) {
                $track = $tracks[$i];
                $type = $this->getMediaPackageField($track, 'type');
                $url = $this->getMediaPackageField($track, 'url');
                if ($type && $url) {
                    $this->syncTrack($multimediaObject, $type, $url);
                }
            }
        } else {
            // NOTE: Single track
            $type = $this->getMediaPackageField($tracks, 'type');
            $url = $this->getMediaPackageField($tracks, 'url');
            if ($type && $url) {
                $this->syncTrack($multimediaObject, $type, $url);
            }
        }
    }

    public function syncPics(MultimediaObject $multimediaObject, $mediaPackage = null): void
    {
        $mediaPackageId = $multimediaObject->getProperty('opencast');
        if (!$mediaPackageId) {
            return;
        }

        if (!$mediaPackage) {
            $mediaPackage = $this->opencastClient->getMediaPackage($mediaPackageId);
        }

        if (!$mediaPackage) {
            throw new \Exception('Opencast communication error');
        }

        $attachments = $this->getMediaPackageField($mediaPackage, 'attachments');
        $attachment = $this->getMediaPackageField($attachments, 'attachment');
        if (is_array($attachment) && isset($attachment[0])) {
            $limit = count($attachment);
            for ($i = 0; $i < $limit; ++$i) {
                $pic = $attachment[$i];
                $type = $this->getMediaPackageField($pic, 'type');
                $url = $this->getMediaPackageField($pic, 'url');
                if ($type && $url) {
                    $this->syncPic($multimediaObject, $type, $url);
                }
            }
        } else {
            $type = $this->getMediaPackageField($attachment, 'type');
            $url = $this->getMediaPackageField($attachment, 'url');
            if ($type && $url) {
                $this->syncPic($multimediaObject, $type, $url);
            }
        }
    }

    private function addPicFromAttachment(MultimediaObject $multimediaObject, $attachment): MultimediaObject
    {
        $tags = $this->getMediaPackageField($this->getMediaPackageField($attachment, 'tags'), 'tag');
        if (!is_array($tags)) {
            $tags = [$tags];
        }

        $type = $this->getMediaPackageField($attachment, 'type');
        $url = $this->getMediaPackageField($attachment, 'url');
        if (!$url) {
            $this->logger->error(__CLASS__.'['.__FUNCTION__.'] '.'No url on pic attachment '.json_encode($attachment));

            return $multimediaObject;
        }
        $pic = new Pic();
        $pic->addTag('opencast');
        $pic->addTag($type);
        $pic->setUrl($url);
        if ($tags) {
            foreach ($tags as $tag) {
                $pic->addTag($tag);
            }
        }
        $multimediaObject->addPic($pic);

        return $multimediaObject;
    }

    private function syncTrack(MultimediaObject $multimediaObject, $type, $url): bool
    {
        $track = $multimediaObject->getTrackWithAllTags(['opencast', $type]);
        if (!$track) {
            return false;
        }

        $track->setUrl($url);
        $track->setPath($this->opencastService->getPath($url));

        if ($track->getPath()) {
            $this->inspectionService->autocompleteTrack($track);
        }

        return true;
    }

    private function addOpencastUrl($opencastUrls = [], $track = []): array
    {
        $type = $this->getMediaPackageField($track, 'type');
        $url = $this->getMediaPackageField($track, 'url');
        if ($type && $url) {
            $opencastUrls[$type] = $url;
        }

        return $opencastUrls;
    }

    private function createPicFromAttachment($attachment, MultimediaObject $multimediaObject, $index = null, $targetType = 'presenter/search+preview')
    {
        if ($attachment) {
            if (null === $index) {
                $itemAttachment = $attachment;
            } else {
                $itemAttachment = $attachment[$index];
            }
            $type = $this->getMediaPackageField($itemAttachment, 'type');
            if ($targetType === $type) {
                $tags = $this->getMediaPackageField($itemAttachment, 'tags');
                $type = $this->getMediaPackageField($itemAttachment, 'type');
                $url = $this->getMediaPackageField($itemAttachment, 'url');
                if ($tags || $url) {
                    $pic = new Pic();
                    $pic->addTag('opencast');
                    $pic->addTag($type);
                    if ($tags) {
                        foreach ($tags as $tag) {
                            if (!is_array($tag)) {
                                $pic->addTag($tag);
                            }
                        }
                    }
                    if ($url) {
                        $pic->setUrl($url);
                    }
                    $multimediaObject->addPic($pic);
                }
            }
        }

        return $multimediaObject;
    }

    private function addTagToTrack($tags, Track $track, $index = null): Track
    {
        if ($tags) {
            if (null === $index) {
                $tag = $tags;
            } else {
                $tag = $tags[$index];
            }
            if (!$track->containsTag($tag)) {
                $track->addTag($tag);
            }
        }

        return $track;
    }

    private function getMediaPackageLanguage($mediaPackage, $defaultLanguage = null)
    {
        $language = $this->getMediaPackageField($mediaPackage, 'language');
        if ($language) {
            $parsedLocale = \Locale::parseLocale($language);
            if (!$this->customLanguages || in_array($parsedLocale['language'], $this->customLanguages, true)) {
                return $parsedLocale['language'];
            }
        }

        return $defaultLanguage ?? \Locale::getDefault();
    }

    private function syncPic(MultimediaObject $multimediaObject, $type, $url): bool
    {
        $pic = $multimediaObject->getPicWithAllTags(['opencast', $type]);
        if (!$pic) {
            return false;
        }

        $pic->setUrl($url);

        return true;
    }

    private function findOwnerForMultimediaObject(Series $series)
    {
        $prototype = $this->dm->getRepository(MultimediaObject::class)->findOneBy([
            'series' => new ObjectId($series->getId()),
            'status' => MultimediaObject::STATUS_PROTOTYPE,
        ]);

        $people = $prototype->getPeopleByRoleCod('owner', true);

        try {
            $embeddedPerson = $people[0];
            $ownerId = $embeddedPerson->getId();
        } catch (\Exception $exception) {
            $this->logger->warning('Prototype '.$prototype->getId().' from series '.$series->getId().' havent got owner.');

            return null;
        }

        return $this->dm->getRepository(User::class)->findOneBy(['person' => $ownerId]);
    }
}
