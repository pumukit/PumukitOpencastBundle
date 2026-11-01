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
    private const ASSETS_PATH = 'assets/assets/';

    public function __construct(
        private readonly JobCreator $jobCreator,
        private readonly ProfileService $profileService,
        private readonly MultimediaObjectService $multimediaObjectService,
        private readonly array $sbsConfiguration = [],
        private readonly array $urlMapping = [],
        private readonly bool $errorIfFileNotExist = true,
        private readonly string $locale = 'en',
    ) {}

    public function isSbsEnabled(): bool
    {
        return (bool) ($this->sbsConfiguration['generate_sbs'] ?? false) && '' !== $this->getSbsProfile();
    }

    public function getSbsProfile(): string
    {
        return (string) ($this->sbsConfiguration['profile'] ?? '');
    }

    public function genAutoSbs(MultimediaObject $multimediaObject, array $opencastUrls = []): bool
    {
        if (!$this->isSbsEnabled()) {
            return false;
        }

        if ($this->sbsConfiguration['use_flavour'] ?? false) {
            foreach ($multimediaObject->getTracksWithTag((string) $this->sbsConfiguration['flavour']) as $track) {
                if (!$track->metadata()->isOnlyAudio()) {
                    return $this->useTrackAsSbs($multimediaObject, $track);
                }
            }
        }

        return $this->generateSbsTrack($multimediaObject, $opencastUrls);
    }

    public function generateSbsTrack(MultimediaObject $multimediaObject, array $opencastUrls = []): bool
    {
        if (!$this->isSbsEnabled()) {
            return false;
        }

        $track = $multimediaObject->getTracks()[0] ?? null;
        if (!$track instanceof Track) {
            return false;
        }

        $path = $track->storage()->path()->path();
        if (!$path) {
            return false;
        }

        $language = $multimediaObject->getProperty('opencastlanguage') ? strtolower((string) $multimediaObject->getProperty('opencastlanguage')) : $this->locale;
        $vars = $opencastUrls ? ['ocurls' => $opencastUrls] : [];

        $this->jobCreator->fromPath($multimediaObject, Path::create($path), new JobOptions($this->getSbsProfile(), 2, $language, [], $vars));

        return true;
    }

    public function getPath(string $url): ?string
    {
        $url = $this->toStorageUrl($url);
        foreach ($this->urlMapping as $mapping) {
            $position = '' === $mapping['url'] ? false : strpos($url, $mapping['url']);
            if (false === $position) {
                continue;
            }
            $path = substr_replace($url, $mapping['path'], $position, strlen($mapping['url']));
            if (false !== realpath($path)) {
                return $path;
            }
        }

        if ($this->errorIfFileNotExist) {
            throw new \RuntimeException(sprintf('The track file of "%s" is not accessible. Check "pumukit_opencast.url_mapping".', $url));
        }

        return null;
    }

    private function toStorageUrl(string $url): string
    {
        $position = stripos($url, self::ASSETS_PATH);
        if (false === $position) {
            return $url;
        }

        $parts = explode('/', substr($url, $position + strlen(self::ASSETS_PATH)));
        if (4 !== count($parts)) {
            return $url;
        }
        [$mediaPackageId, $elementId, $version, $file] = $parts;
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        return substr($url, 0, $position).self::ASSETS_PATH.$mediaPackageId.'/'.$version.'/'.$elementId.('' === $extension ? '' : '.'.$extension);
    }

    private function useTrackAsSbs(MultimediaObject $multimediaObject, Track $track): bool
    {
        $profile = $this->profileService->getProfile($this->getSbsProfile());
        if (!$profile) {
            return false;
        }

        $track->tags()->add('profile:'.$this->getSbsProfile());
        foreach (['master', 'display'] as $tag) {
            if (!empty($profile[$tag])) {
                $track->tags()->add($tag);
            }
        }
        foreach (array_filter(preg_split('/[,\s]+/', (string) ($profile['tags'] ?? ''))) as $tag) {
            $track->tags()->add(trim($tag));
        }

        $this->multimediaObjectService->updateMultimediaObject($multimediaObject);

        return true;
    }
}
