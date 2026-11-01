<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Pumukit\OpencastBundle\Client\OpencastConnection;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\ClientServiceFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractRemoteOpencastCommand extends Command
{
    protected SymfonyStyle $io;
    protected ClientService $remote;
    protected string $host;
    protected ?string $id = null;
    protected bool $force = false;

    public function __construct(
        private readonly ClientServiceFactory $clientServiceFactory,
        protected readonly int $secondsToSleep = 1,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'URL of the Opencast server')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Digest user of the Opencast server')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Digest password of the Opencast server')
            ->addOption('admin-host', null, InputOption::VALUE_REQUIRED, 'URL of the Opencast admin node, if it is not the host')
            ->addOption('insecure', null, InputOption::VALUE_NONE, 'Do not verify the TLS certificate of the Opencast server')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Only this PuMuKIT object id')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Execute the changes. Without it, the command only lists them')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->host = rtrim(trim((string) $input->getOption('host')), '/');
        $user = trim((string) $input->getOption('user'));
        $password = (string) $input->getOption('password');
        if ('' === $this->host || '' === $user || '' === $password) {
            throw new \InvalidArgumentException('The --host, --user and --password options are required');
        }
        if (!filter_var($this->host, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(sprintf('The host "%s" is not a valid URL', $this->host));
        }

        $this->id = $input->getOption('id');
        if (null !== $this->id && !preg_match('/^[a-f\d]{24}$/i', $this->id)) {
            throw new \InvalidArgumentException(sprintf('The id "%s" is not a valid PuMuKIT id', $this->id));
        }
        $this->force = (bool) $input->getOption('force');

        $this->remote = $this->clientServiceFactory->create(new OpencastConnection($this->host, $input->getOption('admin-host'), $user, $password, (bool) $input->getOption('insecure')));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = $this->remote->checkSupported();
        $this->io->text(sprintf('Opencast %s at %s%s', $version, $this->host, $this->force ? '' : ' <comment>(listing only, use --force to execute)</comment>'));

        return $this->process();
    }

    abstract protected function process(): int;

    protected function hostPattern(): string
    {
        return preg_quote((string) preg_replace('#^https?://#i', '', $this->host), '/');
    }

    protected function pause(): void
    {
        if ($this->secondsToSleep > 0) {
            sleep($this->secondsToSleep);
        }
    }
}
