<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\SeriesSyncService;
use Pumukit\SchemaBundle\Document\Series;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OpencastSyncSeriesCommand extends Command
{
    private $output;
    private $input;
    private $dm;
    private $logger;
    private $user;
    private $password;
    private $host;
    private $id;
    private $force;

    /** @var ClientService */
    private $clientService;
    private $seriesSyncService;

    public function __construct(
        DocumentManager $documentManager,
        LoggerInterface $logger,
        SeriesSyncService $opencastSeriesSyncService
    ) {
        $this->dm = $documentManager;
        $this->logger = $logger;
        $this->seriesSyncService = $opencastSeriesSyncService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:opencast:sync:series')
            ->setDescription('Synchronize PuMuKIT series in Opencast. This command is not idempotent.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Opencast user')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Opencast password')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Path to selected tracks from PMK using regex')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'ID of multimedia object to import')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')
            ->setHelp(
                <<<'EOT'


            Command to synchronize PuMuKIT series in Opencast

            <info> ** Example ( check and list ):</info>

            <comment>php bin/console pumukit:opencast:sync:series --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es"</comment>
            <comment>php bin/console pumukit:opencast:sync:series --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --id="5bcd806ebf435c25008b4581"</comment>

            This example will be check the connection with these Opencast and list all multimedia objects from PuMuKIT find by regex host.

            <info> ** Example ( <error>execute</error> ):</info>

            <comment>php bin/console pumukit:opencast:sync:series --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --force</comment>
            <comment>php bin/console pumukit:opencast:sync:series --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --id="5bcd806ebf435c25008b4581" --force</comment>

EOT
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->output = $output;
        $this->input = $input;

        $this->user = trim($this->input->getOption('user'));
        $this->password = trim($this->input->getOption('password'));
        $this->host = trim($this->input->getOption('host'));
        $this->id = $this->input->getOption('id');
        $this->force = (true === $this->input->getOption('force'));

        $this->clientService = new ClientService(
            $this->host,
            $this->user,
            $this->password,
            '/engage/ui/watch.html',
            '/admin/index.html#/recordings',
            '/dashboard/index.html',
            false,
            'delete-archive',
            false,
            true,
            null,
            $this->logger,
            null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checkInputs();

        if ($this->checkOpencastStatus()) {
            $series = $this->getSeries();
            if ($this->force) {
                $this->syncSeries($series);
            } else {
                $this->showSeries($series);
            }
        }

        return 0;
    }

    private function checkInputs(): void
    {
        if (!$this->user || !$this->password || !$this->host) {
            throw new \Exception('Please, set values for user, password and host');
        }

        if ($this->id) {
            $validate = preg_match('/^[a-f\d]{24}$/i', $this->id);
            if (0 === $validate || false === $validate) {
                throw new \Exception('Please, use a valid ID');
            }
        }
    }

    private function checkOpencastStatus(): bool
    {
        if ($this->clientService->getAdminUrl()) {
            return true;
        }

        return false;
    }

    private function getSeries()
    {
        $criteria = [];
        $criteria['properties.opencast'] = ['$exists' => true];

        if ($this->id) {
            $criteria['_id'] = new ObjectId($this->id);
        }

        return $this->dm->getRepository(Series::class)->findBy($criteria);
    }

    private function syncSeries($series): void
    {
        $this->output->writeln(
            [
                '',
                '<info> **** Sync Series **** </info>',
                '',
                '<comment> ----- Total: </comment>'.(is_countable($series) ? count($series) : 0),
            ]
        );

        foreach ($series as $oneSeries) {
            if (!$this->clientService->getOpencastSeries($oneSeries)) {
                $this->seriesSyncService->createSeries($oneSeries);
            } else {
                $this->output->writeln(' Series: '.$oneSeries->getId().' OC series: '.$oneSeries->getProperty('opencast').' ya existe en Opencast');
            }
        }
    }

    private function showSeries($series): void
    {
        $this->output->writeln(
            [
                '',
                '<info> **** Finding Series **** </info>',
                '',
                '<comment> ----- Total: </comment>'.(is_countable($series) ? count($series) : 0),
            ]
        );

        foreach ($series as $oneSeries) {
            if (!$this->clientService->getOpencastSeries($oneSeries)) {
                $this->output->writeln(' Series: '.$oneSeries->getId().' Opencast Series: -'.$oneSeries->getProperty('opencast').' - no existe en Opencast');
            } else {
                $this->output->writeln(' Series: '.$oneSeries->getId().' Opencast Series: -'.$oneSeries->getProperty('opencast').' - ya existe en Opencast');
            }
        }
    }
}
