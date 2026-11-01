<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Pumukit\OpencastBundle\Services\ClientService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pumukit:opencast:check', description: 'Check the connection, the credentials and the version of the configured Opencast server')]
class CheckCommand extends Command
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly array $urlMapping = [],
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->clientService->isEnabled()) {
            $io->error('Opencast is disabled (pumukit_opencast.enable: false)');

            return Command::FAILURE;
        }

        try {
            $version = $this->clientService->checkSupported();
            $search = $this->clientService->getMediaPackages('', 1, 0);
            $io->definitionList(
                ['Host' => $this->clientService->getUrl()],
                ['Admin' => $this->clientService->getAdminUrl()],
                ['Version' => $version],
                ['Published mediapackages' => (string) $search->total],
                ['Player' => $this->clientService->getPlayerUrl()],
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $failed = false;
        foreach ($this->urlMapping as $mapping) {
            if (!is_dir($mapping['path'])) {
                $io->warning(sprintf('The url_mapping path "%s" of "%s" is not a readable directory', $mapping['path'], $mapping['url']));
                $failed = true;
            }
        }

        if ($failed) {
            return Command::FAILURE;
        }

        $io->success('Opencast is reachable and supported');

        return Command::SUCCESS;
    }
}
