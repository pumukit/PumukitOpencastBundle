<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Pumukit\EncoderBundle\Services\DTO\JobOptions;
use Pumukit\EncoderBundle\Services\JobCreator;
use Pumukit\EncoderBundle\Services\ProfileService;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;

class OpencastService
{
    private $sbsConfiguration;
    private $sbsProfileName;
    private $generateSbs = false;
    private $useFlavour = false;
    private $sbsFlavour;
    private $urlPathMapping;
    private $jobCreator;
    private $profileService;
    private $multimediaObjectService;
    private $defaultVars;
    private $errorIfFileNotExist;

    public function __construct(
        JobCreator $jobCreator,
        ProfileService $profileService,
        MultimediaObjectService $multimediaObjectService,
        array $sbsConfiguration = [],
        array $urlMapping = [],
        array $defaultVars = [],
        bool $errorIfFileNotExist = true
    ) {
        $this->jobCreator = $jobCreator;
        $this->profileService = $profileService;
        $this->multimediaObjectService = $multimediaObjectService;
        $this->sbsConfiguration = $sbsConfiguration;
        $this->urlPathMapping = $urlMapping;
        $this->defaultVars = $defaultVars;
        $this->errorIfFileNotExist = $errorIfFileNotExist;
        $this->initSbsConfiguration();
    }

    public function genAutoSbs(MultimediaObject $multimediaObject, array $opencastUrls = [])
    {
        if (!$this->generateSbs) {
            return false;
        }

        if (!$this->useFlavour) {
            return $this->generateSbsTrack($multimediaObject, $opencastUrls);
        }

        $flavourTrack = null;
        foreach ($multimediaObject->getTracksWithTag($this->sbsFlavour) as $track) {
            if (!$track->metadata()->isOnlyAudio()) {
                $flavourTrack = $track;

                break;
            }
        }

        if ($flavourTrack) {
            return $this->useTrackAsSbs($multimediaObject, $flavourTrack);
        }

        return $this->generateSbsTrack($multimediaObject, $opencastUrls);
    }

    public function getPath($url): ?string
    {
        $url = $this->refactorUrl($url);

        foreach ($this->urlPathMapping as $m) {
            $path = str_replace($m['url'], $m['path'], $url);
            if (realpath($path)) {
                return $path;
            }
        }

        if ($this->errorIfFileNotExist) {
            throw new \RuntimeException(sprintf(
                'Error accessing to the track path of "%s". Check "pumukit_opencast.url_mapping".',
                $url
            ));
        }

        return null;
    }

    public function generateSbsTrack(MultimediaObject $multimediaObject, array $opencastUrls = [])
    {
        if (!$this->generateSbs) {
            return false;
        }

        if (!$this->sbsProfileName) {
            return false;
        }

        $tracks = $multimediaObject->getTracks();
        if (!$tracks) {
            return false;
        }

        $track = $tracks[0];
        $path = $this->getPath($track->storage()->path()->path());

        $language = $multimediaObject->getProperty('opencastlanguage') ? strtolower($multimediaObject->getProperty('opencastlanguage')) : \Locale::getDefault();

        $vars = $this->defaultVars;
        if ($opencastUrls) {
            $vars += ['ocurls' => $opencastUrls];
        }

        $jobOptions = new JobOptions($this->sbsProfileName, 2, $language, [], $vars);
        $path = Path::create($path);

        return $this->jobCreator->fromPath($multimediaObject, $path, $jobOptions);
    }

    public function getMediaPackageThumbnail($mediaPackage): ?string
    {
        if (!isset($mediaPackage['attachments']['attachment'])) {
            return null;
        }

        $attachments = $mediaPackage['attachments']['attachment'];
        if (isset($attachments['id'])) {
            $attachments = [$attachments];
        }

        foreach ($attachments as $attachment) {
            if (!isset($attachment['type'])) {
                continue;
            }

            if (!in_array(
                $attachment['type'],
                [
                    'presenter/search+preview',
                    'presentation/search+preview',
                    'presenter/player+preview',
                    'presentation/player+preview',
                ]
            )
            ) {
                continue;
            }

            if (!isset($attachment['url'])) {
                continue;
            }

            return $attachment['url'];
        }

        return null;
    }

    private function initSbsConfiguration(): void
    {
        if ($this->sbsConfiguration) {
            if (isset($this->sbsConfiguration['generate_sbs'])) {
                $this->generateSbs = $this->sbsConfiguration['generate_sbs'];
            }
            if (isset($this->sbsConfiguration['profile'])) {
                $this->sbsProfileName = $this->sbsConfiguration['profile'];
            }
            if (isset($this->sbsConfiguration['use_flavour'])) {
                $this->useFlavour = $this->sbsConfiguration['use_flavour'];
            }
            if (isset($this->sbsConfiguration['flavour'])) {
                $this->sbsFlavour = $this->sbsConfiguration['flavour'];
            }
        }
    }

    private function refactorUrl(string $url): string
    {
        // NOTE: Refactor for Opencast 3 or greather version
        if (false !== stripos($url, 'assets/assets')) {
            $data = explode('assets/assets/', $url);
            $variables = explode('/', $data[1]);
            $file = end($variables);
            $version = prev($variables);
            $track = prev($variables);
            $mediaPackageID = prev($variables);
            $url = $data[0].'assets/assets/'.$mediaPackageID.'/'.$version.'/'.$track.'.'.pathinfo($file, PATHINFO_EXTENSION);
        }

        // NOTE: Refactor for Opencast 1.4 or 1.6
        $findPathMediaPackage = false !== stripos($url, '/episode/archive/mediapackage/');
        $findPathEpisode = false !== stripos($url, '/episode/');
        if ($findPathEpisode || $findPathMediaPackage) {
            if ($findPathMediaPackage) {
                $data = explode('/episode/archive/mediapackage/', $url);
                $delimiterPath = '/episode/archive/mediapackage/';
            } else {
                $data = explode('/episode/', $url);
                $delimiterPath = '/episode/';
            }

            $variables = explode('/', $data[1]);
            $file = end($variables);
            $version = prev($variables);
            $element = prev($variables);
            $mediaPackageID = prev($variables);
            $url = $data[0].$delimiterPath.$mediaPackageID.'/'.$version.'/'.$element.'.'.pathinfo($file, PATHINFO_EXTENSION);
        }

        return $url;
    }

    private function useTrackAsSbs(MultimediaObject $multimediaObject, Track $track): bool
    {
        if (!$this->sbsProfileName) {
            return false;
        }

        $sbsProfile = $this->profileService->getProfile($this->sbsProfileName);

        $track->tags()->add('profile:'.$this->sbsProfileName);

        $tags = ['master', 'display'];
        foreach ($tags as $tag) {
            if ($sbsProfile[$tag] && !$track->tags()->contains($tag)) {
                $track->tags()->add($tag);
            }
        }

        foreach (array_filter(preg_split('/[,\s]+/', $sbsProfile['tags'])) as $tag) {
            $track->tags()->add(trim($tag));
        }

        $this->multimediaObjectService->updateMultimediaObject($multimediaObject);

        return true;
    }
}
