<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Pumukit\OpencastBundle\Services\ClientService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pumukit:opencast:workflow:stop', description: 'Delete the Opencast events whose publications were retracted')]
class StopWorkflowCommand extends Command
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly bool $deleteArchiveMediaPackage = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mediaPackageId', null, InputOption::VALUE_REQUIRED, 'Delete only this event')
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'Workflow that retracts the publications', 'retract')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the events')
            ->setHelp('Deletes the Opencast events that are no longer published and ran the retract workflow successfully. It needs delete_archive_mediapackage: true.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->deleteArchiveMediaPackage) {
            $io->note('Nothing to do: pumukit_opencast.delete_archive_mediapackage is false');

            return Command::SUCCESS;
        }

        $ids = $input->getOption('mediaPackageId') ? [(string) $input->getOption('mediaPackageId')] : $this->clientService->getSucceededWorkflowMediaPackageIds((string) $input->getOption('workflow'));
        $force = !$input->getOption('dry-run');

        $errors = 0;
        foreach ($ids as $id) {
            if (!$force) {
                $io->text(sprintf(' %s will be deleted', $id));

                continue;
            }

            try {
                $this->clientService->removeEvent($id);
                $io->text(sprintf(' %s deleted', $id));
            } catch (\Throwable $exception) {
                ++$errors;
                $io->text(sprintf(' <error>%s: %s</error>', $id, $exception->getMessage()));
            }
        }
        $io->text(sprintf('%d events%s', count($ids), $force ? '' : ' (dry run)'));

        return $errors ? Command::FAILURE : Command::SUCCESS;
    }
}
