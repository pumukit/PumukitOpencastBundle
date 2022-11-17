<?php

namespace Pumukit\OpencastBundle\Command;

use Pumukit\OpencastBundle\Services\ClientService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OpencastDeleteEmptyPersonalSeriesCommand extends ContainerAwareCommand
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
    private $clientService;
    private $seriesSyncService;
    private $locale;

    protected function configure()
    {
        $this
            ->setName('pumukit:opencast:delete:empty:personal:series')
            ->setDescription('Delete personal series without OMs. This command is not idempotent.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Opencast user')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Opencast password')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Path to selected tracks from PMK using regex')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'ID of multimedia object to import')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')
            ->setHelp(
                <<<'EOT'
            
            
            Command to delete in Opencast the personal series that do not have videos
            
            <info> ** Example ( check and list ):</info>
            
            <comment>php app/console pumukit:opencast:delete:empty:personal:series --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es"</comment>
            <comment>php app/console pumukit:opencast:delete:empty:personal:series --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --id="5bcd806ebf435c25008b4581"</comment>
            
            This example will be check the connection with these Opencast and list all multimedia objects from PuMuKIT find by regex host.
            
            <info> ** Example ( <error>execute</error> ):</info>
            
            <comment>php app/console pumukit:opencast:delete:empty:personal:series --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --force</comment>
            <comment>php app/console pumukit:opencast:delete:empty:personal:series --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --id="5bcd806ebf435c25008b4581" --force</comment>

EOT
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->locale = $this->getContainer()->getParameter('locale');

        $this->logger = $this->getContainer()->get('logger');

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

        $this->seriesSyncService = $this->getContainer()->get('pumukit_opencast.series_sync');
    }

    /**
     * @return int|void|null
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkInputs();

        if ($this->checkOpencastStatus()) {
            $series = $this->getSeries();
            if ($this->force) {
                $this->deleteSeries($series);
            } else {
                $this->showSeries($series);
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function checkInputs()
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

    /**
     * @return bool
     */
    private function checkOpencastStatus()
    {
        if ($this->clientService->getAdminUrl()) {
            return true;
        }

        return false;
    }

    /**
     * @return mixed
     */
    private function getSeries()
    {
        $criteria['properties.opencast'] = ['$exists' => true];

        if ($this->id) {
            $criteria['_id'] = new \MongoId($this->id);
        }

        $criteria["title.$this->locale"] = [
                '$regex' => 'Videos of ',
                '$options' => 'i',
        ];

        $series = $this->dm->getRepository('PumukitSchemaBundle:Series')->findBy($criteria);

        return $series;
    }

    /**
     * @param $series
     */
    private function deleteSeries($series)
    {
        $this->output->writeln(
            [
                '',
                '<info> **** Sync Series **** </info>',
                '',
                '<comment> ----- Total: </comment>'.count($series),
            ]
        );

        foreach ($series as $oneseries) {
            if ($this->dm->getRepository('PumukitSchemaBundle:Series')->countMultimediaObjects($oneseries) == 0) {
                if ($this->clientService->getOpencastSeries($oneseries)) {
                    $this->output->writeln(' ** Removing series: '.$oneseries->getId().' OC series: '.$oneseries->getProperty('opencast'));
                    $this->seriesSyncService->deleteSeries($oneseries);
                } else {
                    $this->output->writeln(' ** Series: '.$oneseries->getId().' OC series: '.$oneseries->getProperty('opencast').' doesnt exist in Opencast');
                }
            }
        }
    }

    /**
     * @param $series
     */
    private function showSeries($series)
    {
        $this->output->writeln(
            [
                '',
                '<info> **** Finding Series **** </info>',
                '',
                '<comment> ----- Total: </comment>'.count($series),
            ]
        );

        foreach ($series as $oneseries) {
            if ($this->dm->getRepository('PumukitSchemaBundle:Series')->countMultimediaObjects($oneseries) == 0) {
                if (!$this->clientService->getOpencastSeries($oneseries)) {
                    $this->output->writeln(' Series: '.$oneseries->getId().' Opencast Series: -'.$oneseries->getProperty('opencast').' - doesnt exist in Opencast');
                } else {
                    $this->output->writeln(' Series: '.$oneseries->getId().' Opencast Series: -'.$oneseries->getProperty('opencast').' - will be deleted on Opencast');
                }
            }
        }
    }
}
