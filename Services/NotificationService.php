<?php

namespace Pumukit\OpencastBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\NotificationBundle\Services\SenderService;
use Pumukit\OpencastBundle\Event\ImportEvent;
use Pumukit\SchemaBundle\Document\User;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class NotificationService
{
    protected $dm;
    protected $senderService;
    protected $router;
    protected $logger;
    protected $template;
    protected $subject;
    protected $accessUrl;

    public function __construct(
        DocumentManager $documentManager,
        SenderService $senderService,
        RouterInterface $router,
        LoggerInterface $logger,
        string $template,
        string $accessUrl,
        string $subject
    ) {
        $this->dm = $documentManager;
        $this->senderService = $senderService;
        $this->router = $router;
        $this->logger = $logger;
        $this->template = $template;
        $this->accessUrl = $accessUrl;
        $this->subject = $subject;
    }

    public function onImportSuccess(ImportEvent $event): void
    {
        $multimediaObject = $event->getMultimediaObject();
        $emailsList = [];
        foreach ($multimediaObject->getPeopleByRoleCod('owner', true) as $person) {
            $owner = $this->dm->getRepository(User::class)->findOneBy(['person' => $person->getId()]);
            if (!$owner) {
                $this->logger->error(__CLASS__.'['.__FUNCTION__.'] Person ('.$person->getId().') assigned as owner of multimediaObject ('.$multimediaObject->getId().') does NOT have an associated USER!');

                continue;
            }
            $emailsList[$owner->getEmail()] = $owner->getFullname();
        }
        $users = $this->dm->getRepository(User::class)->findUsersInAnyGroups($multimediaObject->getGroups()->toArray());

        foreach ($users as $owner) {
            $emailsList[$owner->getEmail()] = $owner->getFullname();
        }
        $emailsList = array_unique($emailsList);

        $backofficeUrl = preg_replace('/{{ *id *}}/', $multimediaObject->getId(), $this->accessUrl);

        try {
            $backofficeUrl = $this->router->generate($this->accessUrl, ['id' => $multimediaObject->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException $e) {
            $this->logger->info(__CLASS__.'['.__FUNCTION__.'] Route name "'.$backofficeUrl.'" not found. Using as route literally.');
        }
        $parameters = [
            'url' => $backofficeUrl,
            'multimediaObject' => $multimediaObject,
        ];
        foreach ($emailsList as $email => $name) {
            $parameters['username'] = $name;
            $this->senderService->sendEmails($email, $this->subject, $this->template, $parameters);
        }
    }
}
