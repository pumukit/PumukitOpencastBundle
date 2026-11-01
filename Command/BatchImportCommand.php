<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pumukit:opencast:batchimport', description: 'Import every published Opencast recording that is not in PuMuKIT')]
class BatchImportCommand extends Command
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ClientService $clientService,
        private readonly OpencastImportService $opencastImportService,
        private readonly bool $opencastBatchImportInverted = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('invert', 'i', InputOption::VALUE_REQUIRED, 'Inverted recordings (CAMERA <-> SCREEN): 1 or 0. Default: batchimport_inverted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $invert = null === $input->getOption('invert') ? $this->opencastBatchImportInverted : '1' === (string) $input->getOption('invert');
        $repository = $this->documentManager->getRepository(MultimediaObject::class);

        $imported = $skipped = $errors = 0;
        for ($offset = 0;; $offset += self::BATCH_SIZE) {
            $result = $this->clientService->getMediaPackages('', self::BATCH_SIZE, $offset);
            if (0 === $offset) {
                $io->text(sprintf('%d published mediapackages', $result->total));
            }
            foreach ($result->mediaPackages as $mediaPackage) {
                if (null !== $repository->findOneBy(['properties.opencast' => $mediaPackage->id()])) {
                    ++$skipped;

                    continue;
                }

                try {
                    $multimediaObject = $this->opencastImportService->importRecordingFromMediaPackage($mediaPackage, $invert);
                    $io->text(sprintf(' %s imported as %s', $mediaPackage->id(), $multimediaObject->getId()));
                    ++$imported;
                } catch (\Throwable $exception) {
                    $io->text(sprintf(' <error>%s: %s</error>', $mediaPackage->id(), $exception->getMessage()));
                    ++$errors;
                }
            }
            if (count($result->mediaPackages) < self::BATCH_SIZE) {
                break;
            }
        }

        $io->text(sprintf('Imported: %d, already imported: %d, errors: %d', $imported, $skipped, $errors));

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
