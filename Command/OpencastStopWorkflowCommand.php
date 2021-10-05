<?php

namespace Pumukit\OpencastBundle\Command;

use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\WorkflowService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OpencastStopWorkflowCommand extends Command
{
    private $opencastWorkflowService;
    private $opencastClientService;
    private $opencastDeleteArchiveMediaPackage;
    private $logger;

    public function __construct(
        WorkflowService $opencastWorkflowService,
        ClientService $opencastClientService,
        $opencastDeleteArchiveMediaPackage,
        LoggerInterface $logger
    ) {
        $this->opencastWorkflowService = $opencastWorkflowService;
        $this->opencastClientService = $opencastClientService;
        $this->opencastDeleteArchiveMediaPackage = $opencastDeleteArchiveMediaPackage;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:opencast:workflow:stop')
            ->setDescription('Stop given workflow or all finished workflows')
            ->addOption('mediaPackageId', null, InputOption::VALUE_REQUIRED, 'Set this parameter to stop workflow with given mediaPackageId')
            ->setHelp(
                <<<'EOT'
Command to stop workflows in Opencast Server.

Given mediaPackageId, will stop that workflow, all finished otherwise.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $opencastVersion = $this->opencastClientService->getOpencastVersion();
        if ($opencastVersion < '2.0.0') {
            if ($this->opencastDeleteArchiveMediaPackage) {
                $mediaPackageId = $input->getOption('mediaPackageId');
                $result = $this->opencastWorkflowService->stopSucceededWorkflows($mediaPackageId);
                if (!$result) {
                    $output->writeln('<error>Error on stopping workflows</error>');
                    $this->logger->error('['.__CLASS__.']('.__FUNCTION__.') Error on stopping workflows');

                    return -1;
                }
                $output->writeln('<info>Successfully stopped workflows</info>');
                $this->logger->info('['.__CLASS__.']('.__FUNCTION__.') Successfully stopped workflows');
            } else {
                $output->writeln('<info>Not allowed to stop workflows</info>');
                $this->logger->warning('['.__CLASS__.']('.__FUNCTION__.') Not allowed to stop workflows');
            }

            return 1;
        }
        if ($mediaPackageId = $input->getOption('mediaPackageId')) {
            $this->opencastClientService->removeEvent($mediaPackageId);
            $output->writeln('<info>Removed event with id'.$mediaPackageId.'</info>');

            return 1;
        }
        $statistics = $this->opencastClientService->getWorkflowStatistics();
        $total = $statistics['statistics']['total'] ?? 0;

        if (0 === $total) {
            return 0;
        }

        $workflowName = 'retract';
        $decode = $this->opencastClientService->getCountedWorkflowInstances('', $total, $workflowName);
        if (!isset($decode['workflows']['workflow'])) {
            $output->writeln('<error>Error on getCountedWorkflowInstances</error>');
            $this->logger->error('['.__CLASS__.']('.__FUNCTION__.') Error on getCountedWorkflowInstances');

            return 0;
        }

        // Bugfix: When there is only one mediapackage, worflows => workflow is NOT an array. So we make it into one.
        if (isset($decode['workflows']['workflow']['mediapackage'])) {
            $decode['workflows']['workflow'] = [$decode['workflows']['workflow']];
        }

        foreach ($decode['workflows']['workflow'] as $workflow) {
            if (!isset($workflow['mediapackage']['id'])) {
                continue;
            }
            $this->opencastClientService->removeEvent($workflow['mediapackage']['id']);
        }

        return 1;
    }
}
