<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use Pumukit\OpencastBundle\Services\ClientServiceFactory;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pumukit:opencast:import:multiple:host', description: 'Import the tracks of the multimedia objects of an Opencast server, e.g. after migrating Opencast')]
class ImportMultipleHostCommand extends AbstractRemoteOpencastCommand
{
    private bool $master = false;

    public function __construct(
        ClientServiceFactory $clientServiceFactory,
        private readonly DocumentManager $documentManager,
        private readonly OpencastImportService $opencastImportService,
        private readonly MultimediaObjectService $multimediaObjectService,
        int $secondsToSleep = 1,
    ) {
        parent::__construct($clientServiceFactory, $secondsToSleep);
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('master', null, InputOption::VALUE_NONE, 'Import the archived (master) tracks instead of the published ones')
            ->setHelp(<<<'EOT'
                Imports the tracks from the given Opencast server into the multimedia objects whose Opencast URL
                matches the host. The objects that already have published (or master, with --master) tracks are skipped.
                Configure pumukit_opencast.url_mapping with the paths of the new server first.

                  <info>php bin/console pumukit:opencast:import:multiple:host --host=https://opencast.example.org -u user -p password</info>
                  <info>php bin/console pumukit:opencast:import:multiple:host --host=https://opencast.example.org -u user -p password --master --force</info>
                EOT)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->master = (bool) $input->getOption('master');
    }

    protected function process(): int
    {
        $criteria = ['properties.opencasturl' => new Regex($this->hostPattern(), 'i')];
        if ($this->id) {
            $criteria['_id'] = new ObjectId($this->id);
        }
        $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->findBy($criteria);
        $this->io->section(sprintf('%d multimedia objects (%s tracks)', count($multimediaObjects), $this->master ? 'master' : 'published'));

        $errors = 0;
        foreach ($multimediaObjects as $multimediaObject) {
            $mediaPackageId = (string) $multimediaObject->getProperty('opencast');
            if ($this->hasTracks($multimediaObject)) {
                $this->io->text(sprintf(' %s - %s: already has the tracks', $multimediaObject->getId(), $mediaPackageId));

                continue;
            }

            $this->pause();

            try {
                $mediaPackage = $this->master ? $this->remote->getMasterMediaPackage($mediaPackageId) : $this->remote->getMediaPackage($mediaPackageId);
                if (null === $mediaPackage) {
                    $this->io->text(sprintf(' <comment>%s - %s: not found in Opencast</comment>', $multimediaObject->getId(), $mediaPackageId));

                    continue;
                }
                if (!$this->force) {
                    $this->io->text(sprintf(' %s - %s: %d tracks to import', $multimediaObject->getId(), $mediaPackageId, count($mediaPackage->tracks())));

                    continue;
                }
                $imported = $this->opencastImportService->importTracks($mediaPackage, $multimediaObject, [$this->master ? 'master' : 'display']);
                $this->multimediaObjectService->updateMultimediaObject($multimediaObject);
                $this->io->text(sprintf(' %s - %s: %d tracks imported', $multimediaObject->getId(), $mediaPackageId, $imported));
            } catch (\Throwable $exception) {
                ++$errors;
                $this->io->text(sprintf(' <error>%s - %s: %s</error>', $multimediaObject->getId(), $mediaPackageId, $exception->getMessage()));
            }
        }

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }

    private function hasTracks(MultimediaObject $multimediaObject): bool
    {
        if ($this->master) {
            return null !== $multimediaObject->getTrackWithTag('master');
        }

        return null !== $multimediaObject->getTrackWithTag('presentation/delivery') || null !== $multimediaObject->getTrackWithTag('presenter/delivery');
    }
}
