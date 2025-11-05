<?php

namespace Pumukit\OpencastBundle\Application\Opencast\EnsureVersionIsSupported;

use Pumukit\OpencastBundle\Domain\Repository\OpencastVersionRepositoryInterface;

class EnsureVersionIsSupported
{
    private OpencastVersionRepositoryInterface $versionRepository;

    private const SUPPORTED_VERSION = 16;

    public function __construct(OpencastVersionRepositoryInterface $versionRepository)
    {
        $this->versionRepository = $versionRepository;
    }

    public function __invoke(): EnsureVersionIsSupportedResponse
    {
        try {
            $currentVersion = $this->versionRepository->getCurrentVersion();
            $isSupported = $this->isSupportedVersion($currentVersion);

            $message = $isSupported ? null : sprintf(
                'Opencast version %s is not supported. Required: %s',
                $currentVersion,
                self::SUPPORTED_VERSION
            );

            return new EnsureVersionIsSupportedResponse($isSupported, $currentVersion, $message);

        } catch (\Throwable $e) {
            return new EnsureVersionIsSupportedResponse(false, 'unknown', 'Cannot connect to Opencast: ' . $e->getMessage());
        }
    }

    private function isSupportedVersion(string $currentVersion): bool
    {
        $majorVersion = (int) explode('.', $currentVersion)[0];

        return $majorVersion === self::SUPPORTED_VERSION;
    }
}