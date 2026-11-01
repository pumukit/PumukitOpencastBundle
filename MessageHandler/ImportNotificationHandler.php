<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\MessageHandler;

use Doctrine\ODM\MongoDB\DocumentManager;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Pumukit\NotificationBundle\Services\SenderService;
use Pumukit\OpencastBundle\Message\ImportNotification;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\User;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
#[WithMonologChannel('opencast')]
class ImportNotificationHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly SenderService $senderService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly array $notificationConfig = [],
    ) {}

    public function __invoke(ImportNotification $notification): void
    {
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->find($notification->multimediaObjectId);
        if (!$multimediaObject instanceof MultimediaObject) {
            $this->logger->warning('Import notification of a multimedia object that no longer exists', ['multimedia_object' => $notification->multimediaObjectId]);

            return;
        }

        $recipients = $this->getRecipients($multimediaObject);
        if (!$recipients) {
            $this->logger->info('Import notification without recipients', ['multimedia_object' => $multimediaObject->getId()]);

            return;
        }

        $parameters = ['url' => $this->getUrl($multimediaObject), 'multimediaObject' => $multimediaObject];
        foreach ($recipients as $email => $name) {
            $this->senderService->sendEmails($email, (string) $this->notificationConfig['subject'], (string) $this->notificationConfig['template'], $parameters + ['username' => $name]);
        }
        $this->logger->info('Import notification sent', ['multimedia_object' => $multimediaObject->getId(), 'recipients' => array_keys($recipients)]);
    }

    private function getRecipients(MultimediaObject $multimediaObject): array
    {
        $repository = $this->documentManager->getRepository(User::class);
        $users = [];
        foreach ($multimediaObject->getPeopleByRoleCod('owner', true) as $person) {
            $user = $repository->findOneBy(['person' => $person->getId()]);
            if ($user instanceof User) {
                $users[] = $user;
            } else {
                $this->logger->warning('Owner without user', ['person' => $person->getId(), 'multimedia_object' => $multimediaObject->getId()]);
            }
        }
        if (count($multimediaObject->getGroups()) > 0) {
            foreach ($repository->findUsersInAnyGroups($multimediaObject->getGroups()->toArray()) as $user) {
                $users[] = $user;
            }
        }

        $recipients = [];
        foreach ($users as $user) {
            if ($user->getEmail()) {
                $recipients[$user->getEmail()] = (string) $user->getFullname();
            }
        }

        return $recipients;
    }

    private function getUrl(MultimediaObject $multimediaObject): string
    {
        $url = (string) ($this->notificationConfig['url'] ?? '');

        try {
            return $this->urlGenerator->generate($url, ['id' => $multimediaObject->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return (string) preg_replace('/{{ *id *}}/', (string) $multimediaObject->getId(), $url);
        }
    }
}
