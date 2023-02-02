<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OpencastBatchImportCommand extends Command
{
    private $documentManager;
    private $opencastClientService;
    private $opencastImportService;
    private $opencastBatchImportInverted;

    public function __construct(DocumentManager $documentManager, ClientService $opencastClientService, OpencastImportService $opencastImportService, $opencastBatchImportInverted)
    {
        $this->documentManager = $documentManager;
        $this->opencastClientService = $opencastClientService;
        $this->opencastImportService = $opencastImportService;
        $this->opencastBatchImportInverted = $opencastBatchImportInverted;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:opencast:batchimport')
            ->setDescription('Import the complete opencast repository')
            ->addOption('invert', 'i', InputOption::VALUE_OPTIONAL, 'Inverted recording (CAMERA <-> SCREEN)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $mediaPackages = $this->opencastClientService->getMediaPackages('', 1, 0);

        $invert = $input->getOption('invert');
        if (('0' === $invert) || ('1' === $invert)) {
            $invert = ('1' === $invert);
        } else {
            $invert = $this->opencastBatchImportInverted;
        }

        $totalMediaPackages = $mediaPackages[0];
        $batchSize = 200;
        $batchPlace = 0;

        $output->writeln('Number of mediapackages: '.$mediaPackages[0]);

        while ($batchPlace < $totalMediaPackages) {
            $output->writeln('Importing recordings '.$batchPlace.' to '.($batchPlace + $batchSize));
            $mediaPackages = $this->opencastClientService->getMediaPackages('', $batchSize, $batchPlace);

            $repositoryMultimediaObjects = $this->documentManager->getRepository(MultimediaObject::class);

            foreach ($mediaPackages[1] as $mediaPackage) {
                $output->writeln('Importing mediapackage: '.$mediaPackage['id']);
                if ($repositoryMultimediaObjects->findOneBy(['properties.opencast' => $mediaPackage['id']])) {
                    $output->writeln('Mediapackage '.$mediaPackage['id'].' has already been imported, skipping to next mediapackage');
                } else {
                    $this->opencastImportService->importRecording($mediaPackage['id'], $invert);
                }
            }
            $batchPlace += $batchSize;
        }
        $stopTime = microtime(true);
        $output->writeln('Finished importing '.$totalMediaPackages.' recordings in '.($stopTime - $startTime).' seconds');

        return 0;
    }
}
