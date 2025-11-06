<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Application\Opencast\CheckOpencastConnection;

use Pumukit\OpencastBundle\Application\Opencast\EnsureVersionIsSupported\EnsureVersionIsSupportedResponse;
use Pumukit\OpencastBundle\Domain\Repository\OpencastConnectionRepositoryInterface;
use Pumukit\OpencastBundle\Domain\Repository\OpencastVersionRepositoryInterface;

final readonly class CheckOpencastConnection
{
    public function __construct(private OpencastConnectionRepositoryInterface $connectionRepository)
    {
    }

    public function __invoke(): CheckOpencastConnectionResponse
    {
        try {
            $check = $this->connectionRepository->check();

            return new CheckOpencastConnectionResponse($check, 'Opencast connection is up');

        } catch (\Throwable $e) {
            return new CheckOpencastConnectionResponse(false, $e->getMessage());
        }
    }
}
