<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use Pumukit\OpencastBundle\Services\ClientServiceFactory;
use Pumukit\OpencastBundle\Services\SegmentFactory;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'pumukit:opencast:import:segments', description: 'Import the segments of the multimedia objects of an Opencast server')]
class ImportSegmentsCommand extends AbstractRemoteOpencastCommand
{
    public function __construct(
        ClientServiceFactory $clientServiceFactory,
        private readonly DocumentManager $documentManager,
        int $secondsToSleep = 1,
    ) {
        parent::__construct($clientServiceFactory, $secondsToSleep);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(<<<'EOT'
            Imports the segments of the published mediapackages into the multimedia objects whose Opencast URL matches
            the host and that have no segments yet.

              <info>php bin/console pumukit:opencast:import:segments --host=https://opencast.example.org -u user -p password --force</info>
            EOT);
    }

    protected function process(): int
    {
        $criteria = ['properties.opencasturl' => new Regex($this->hostPattern(), 'i'), 'embeddedSegments' => ['$exists' => false]];
        if ($this->id) {
            $criteria['_id'] = new ObjectId($this->id);
        }
        $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->findBy($criteria);
        $this->io->section(sprintf('%d multimedia objects without segments', count($multimediaObjects)));

        $errors = 0;
        foreach ($multimediaObjects as $multimediaObject) {
            $mediaPackageId = (string) $multimediaObject->getProperty('opencast');
            $this->pause();

            try {
                $mediaPackage = $this->remote->getMediaPackage($mediaPackageId);
                $segments = $mediaPackage ? SegmentFactory::fromMediaPackage($mediaPackage) : [];
                if ($this->force && $segments) {
                    $multimediaObject->setEmbeddedSegments($segments);
                    $this->documentManager->flush();
                }
                $this->io->text(sprintf(' %s - %s: %d segments%s', $multimediaObject->getId(), $mediaPackageId, count($segments), $this->force && $segments ? ' imported' : ''));
            } catch (\Throwable $exception) {
                ++$errors;
                $this->io->text(sprintf(' <error>%s - %s: %s</error>', $multimediaObject->getId(), $mediaPackageId, $exception->getMessage()));
            }
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
