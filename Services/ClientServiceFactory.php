<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Services;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Api\OpencastApiResolver;
use Pumukit\OpencastBundle\Client\OpencastConnection;
use Pumukit\OpencastBundle\Client\OpencastHttpClient;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

#[WithMonologChannel('opencast')]
class ClientServiceFactory
{
    public function __construct(
        private readonly OpencastApiResolver $resolver,
        private readonly LoggerInterface $logger,
        private readonly ?RoleHierarchyInterface $roleHierarchy = null,
    ) {}

    public function create(OpencastConnection $connection, string $player = '/paella7/ui/watch.html'): ClientService
    {
        return new ClientService(new OpencastHttpClient($connection, $this->logger), $this->resolver, $this->roleHierarchy, $this->logger, $player);
    }
}
