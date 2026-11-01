<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pumukit:opencast:import', description: 'Import a single Opencast recording')]
class SingleImportCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly OpencastImportService $opencastImportService,
        private readonly ClientService $clientService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Opencast mediapackage id')
            ->addOption('invert', 'i', InputOption::VALUE_NONE, 'Inverted recording (CAMERA <-> SCREEN)')
            ->addOption('mmobjid', 'o', InputOption::VALUE_REQUIRED, 'Import the tracks into this existing multimedia object')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Language if Opencast has none')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $opencastId = (string) $input->getArgument('id');
        $repository = $this->documentManager->getRepository(MultimediaObject::class);

        if ($multimediaObjectId = $input->getOption('mmobjid')) {
            $multimediaObject = $repository->find($multimediaObjectId);
            if (!$multimediaObject instanceof MultimediaObject) {
                $io->error(sprintf('No multimedia object with id %s', $multimediaObjectId));

                return Command::FAILURE;
            }
            $mediaPackage = $this->clientService->getMediaPackage($opencastId);
            if (null === $mediaPackage) {
                $io->error(sprintf('The mediapackage %s is not published in Opencast', $opencastId));

                return Command::FAILURE;
            }
            $this->opencastImportService->importIntoMultimediaObject($multimediaObject, $mediaPackage, (bool) $input->getOption('invert'), $input->getOption('language'));
            $io->success(sprintf('Mediapackage %s imported into %s', $opencastId, $multimediaObject->getId()));

            return Command::SUCCESS;
        }

        if ($existing = $repository->findOneBy(['properties.opencast' => $opencastId])) {
            $io->note(sprintf('Mediapackage %s already imported as %s', $opencastId, $existing->getId()));

            return Command::SUCCESS;
        }

        $multimediaObject = $this->opencastImportService->importRecording($opencastId, (bool) $input->getOption('invert'));
        $io->success(sprintf('Mediapackage %s imported as %s', $opencastId, $multimediaObject->getId()));

        return Command::SUCCESS;
    }
}
