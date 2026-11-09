<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\EventListener;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Exception\OpencastException;
use Pumukit\OpencastBundle\Exception\OpencastNotFoundException;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Document\User;
use Pumukit\SchemaBundle\Event\SchemaEvents;
use Pumukit\SchemaBundle\Event\UserEvent;

#[WithMonologChannel('opencast')]
class UserListener
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly LoggerInterface $logger,
        private readonly bool $manageOpencastUsers = false,
    ) {}

    public function onUserEvent(UserEvent $event, string $eventName): void
    {
        if (!$this->manageOpencastUsers) {
            return;
        }

        $user = $event->getUser();

        try {
            match ($eventName) {
                SchemaEvents::USER_CREATE => $this->clientService->createUser($user),
                SchemaEvents::USER_UPDATE => $this->update($user),
                SchemaEvents::USER_DELETE => $this->clientService->deleteUser($user),
                default => null,
            };
            $this->logger->info('Opencast user synchronized', ['user' => $user->getUsername(), 'event' => $eventName]);
        } catch (OpencastException $exception) {
            $this->logger->error('Opencast user not synchronized', ['user' => $user->getUsername(), 'event' => $eventName, 'error' => $exception->getMessage()]);
        }
    }

    private function update(User $user): void
    {
        try {
            $this->clientService->updateUser($user);
        } catch (OpencastNotFoundException) {
            $this->clientService->createUser($user);
        }
    }
}
