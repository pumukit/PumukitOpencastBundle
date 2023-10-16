<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OpencastSingleImportCommand extends Command
{
    private $documentManager;
    private $opencastImportService;
    private $multimediaObjectService;
    private $clientService;

    public function __construct(
        DocumentManager $documentManager,
        OpencastImportService $opencastImportService,
        MultimediaObjectService $multimediaObjectService,
        ClientService $clientService
    ) {
        $this->documentManager = $documentManager;
        $this->opencastImportService = $opencastImportService;
        $this->multimediaObjectService = $multimediaObjectService;
        $this->clientService = $clientService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:opencast:import')
            ->setDescription('Import a single opencast recording')
            ->addArgument('id', InputArgument::REQUIRED, 'Opencast id to import')
            ->addOption('invert', 'i', InputOption::VALUE_NONE, 'Inverted recording (CAMERA <-> SCREEN)')
            ->addOption('mmobjid', 'o', InputOption::VALUE_OPTIONAL, 'Use an existing multimedia object. Not create a new one')
            ->addOption('language', null, InputOption::VALUE_OPTIONAL, 'Default language if not present in Opencast', null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $opencastId = $input->getArgument('id');
        if ($input->getOption('verbose')) {
            $output->writeln('Importing opencast recording: '.$opencastId);
        }

        $mmobjRepo = $this->documentManager->getRepository(MultimediaObject::class);

        if ($mmObjId = $input->getOption('mmobjid')) {
            if ($mmobj = $mmobjRepo->find($mmObjId)) {
                $this->completeMultimediaObject($mmobj, $opencastId, $input->getOption('invert'), $input->getOption('language'));
            } else {
                $output->writeln('No multimedia object with id '.$mmObjId);
            }
        } else {
            if ($mmobjRepo->findOneBy(['properties.opencast' => $opencastId])) {
                $output->writeln('Mediapackage '.$opencastId.' has already been imported, skipping to next mediapackage');
            } else {
                $this->opencastImportService->importRecording($opencastId, $input->getOption('invert'));
            }
        }

        return 0;
    }

    protected function completeMultimediaObject(MultimediaObject $multimediaObject, string $opencastId, bool $invert, string $language): void
    {
        $mediaPackage = $this->clientService->getMediaPackage($opencastId);

        $properties = $this->opencastImportService->getMediaPackageField($mediaPackage, 'id');
        if ($properties) {
            $multimediaObject->setProperty('opencast', $properties);
            $multimediaObject->setProperty('opencasturl', $this->clientService->getPlayerUrl().'?id='.$properties);
        }
        $multimediaObject->setProperty('opencastinvert', $invert);

        if ($language) {
            $parsedLocale = \Locale::parseLocale($language);
            $multimediaObject->setProperty('opencastlanguage', $parsedLocale['language']);
        }

        $media = $this->opencastImportService->getMediaPackageField($mediaPackage, 'media');
        $tracks = $this->opencastImportService->getMediaPackageField($media, 'track');
        if (isset($tracks[0])) {
            // NOTE: Multiple tracks
            $limit = is_countable($tracks) ? count($tracks) : 0;
            for ($i = 0; $i < $limit; ++$i) {
                $this->opencastImportService->createTrackFromMediaPackage($mediaPackage, $multimediaObject, $i, ['display'], $language);
            }
        } else {
            // NOTE: Single track
            $this->opencastImportService->createTrackFromMediaPackage($mediaPackage, $multimediaObject, null, ['display'], $language);
        }

        $this->multimediaObjectService->updateMultimediaObject($multimediaObject);
    }
}
