<?php

namespace Pumukit\OpencastBundle\EventListener;

use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Event\UserEvent;

class UserListener
{
    private $clientService;
    private $logger;
    private $manageOpencastUsers;

    public function __construct(ClientService $clientService, LoggerInterface $logger, bool $manageOpencastUsers = false)
    {
        $this->clientService = $clientService;
        $this->logger = $logger;
        $this->manageOpencastUsers = $manageOpencastUsers;
    }

    public function onUserCreate(UserEvent $event): void
    {
        if (!$this->manageOpencastUsers) {
            return;
        }

        try {
            $user = $event->getUser();
            $output = $this->clientService->createUser($user);
            if (!$output) {
                throw new \Exception('Error on creating an User on the Opencast Server');
            }
            $this->logger->debug('Created User "'.$user->getUsername().'" on the Opencast Server');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
        }
    }

    public function onUserUpdate(UserEvent $event): void
    {
        if (!$this->manageOpencastUsers) {
            return;
        }

        try {
            $user = $event->getUser();
            $output = $this->clientService->updateUser($user);
            if (!$output) {
                throw new \Exception('Error on updating an User on the Opencast Server');
            }
            $this->logger->debug('Updated User "'.$user->getUsername().'" on the Opencast Server');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
        }
    }

    public function onUserDelete(UserEvent $event): void
    {
        if (!$this->manageOpencastUsers) {
            return;
        }

        try {
            $user = $event->getUser();
            $output = $this->clientService->deleteUser($user);
            if (!$output) {
                throw new \Exception('Error on deleting an User on the Opencast Server');
            }
            $this->logger->debug('Deleted User "'.$user->getUsername().'" on the Opencast Server');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
        }
    }
}
