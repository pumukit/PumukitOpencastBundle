<?php

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\OpencastBundle\Services\OpencastImportService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MultipleOpencastHostImportCommand extends Command
{
    private $documentManager;
    private $opencastImportService;
    private $user;
    private $password;
    private $host;
    private $id;
    private $force;
    private $master;
    /** @var ClientService */
    private $clientService;
    private $secondsToSleep;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        OpencastImportService $opencastImportService,
        LoggerInterface $logger,
        int $secondsToSleep
    ) {
        $this->documentManager = $documentManager;
        $this->opencastImportService = $opencastImportService;
        $this->secondsToSleep = $secondsToSleep;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:opencast:import:multiple:host')
            ->setDescription('Import tracks from opencast passing data')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Opencast user')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Opencast password')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Path to selected tracks from PMK using regex')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'ID of multimedia object to import')
            ->addOption('master', null, InputOption::VALUE_NONE, 'Import master tracks')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')
            ->setHelp(
                <<<'EOT'

            Important:

            Before executing the command add Opencast URL MAPPING configuration with OC data you will access.

            ---------------

            Command to import all tracks from Opencast to PuMuKIT defining Opencast configuration

            <info> ** Example ( check and list ):</info>

            * Tracks without master
            <comment>php app/console pumukit:opencast:import:multiple:host --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es"</comment>
            <comment>php app/console pumukit:opencast:import:multiple:host --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --id="5bcd806ebf435c25008b4581"</comment>

            * Tracks master
            <comment>php app/console pumukit:opencast:import:multiple:host --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --master</comment>
            <comment>php app/console pumukit:opencast:import:multiple:host --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --master --id="5bcd806ebf435c25008b4581"</comment>

            This example will be check the connection with these Opencast and list all multimedia objects from PuMuKIT find by regex host.

            <info> ** Example ( <error>execute</error> ):</info>

            * Import tracks no master
            <comment>php app/console pumukit:opencast:import:multiple:host --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --force</comment>
            <comment>php app/console pumukit:opencast:import:multiple:host --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --id="5bcd806ebf435c25008b4581" --force</comment>

            * Import tracks master
            <comment>php app/console pumukit:opencast:import:multiple:host --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --master --force</comment>
            <comment>php app/console pumukit:opencast:import:multiple:host --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --master --id="5bcd806ebf435c25008b4581" --force</comment>

EOT
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->user = trim($input->getOption('user'));
        $this->password = trim($input->getOption('password'));
        $this->host = trim($input->getOption('host'));
        $this->id = $input->getOption('id');
        $this->force = (true === $input->getOption('force'));
        $this->master = (true === $input->getOption('master'));

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
            $multimediaObjects = $this->getMultimediaObjects();
            if ($this->force) {
                if ($this->master) {
                    $this->importMasterTracks($output, $multimediaObjects);
                } else {
                    $this->importBroadcastTracks($output, $multimediaObjects);
                }
            } else {
                $this->showMultimediaObjects($output, $multimediaObjects, $this->master);
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

    private function getMultimediaObjects(): array
    {
        $criteria = [
            'properties.opencasturl' => new Regex($this->host, 'i'),
        ];

        if ($this->id) {
            $criteria['_id'] = new ObjectId($this->id);
        }

        return $this->documentManager->getRepository(MultimediaObject::class)->findBy($criteria);
    }

    private function importBroadcastTracks(OutputInterface $output, array $multimediaObjects): void
    {
        $output->writeln(
            [
                '',
                '<info> **** Adding tracks to multimedia object **** </info>',
                '',
                '<comment> ----- Total: </comment>'.count($multimediaObjects),
            ]
        );

        foreach ($multimediaObjects as $multimediaObject) {
            if (!$multimediaObject->getTrackWithTag('presentation/delivery') && !$multimediaObject->getTrackWithTag('presenter/delivery')) {
                sleep($this->secondsToSleep);
                $this->importTrackOnMultimediaObject(
                    $output,
                    $multimediaObject,
                    false
                );
            } else {
                $output->writeln('<info> Multimedia Object - '.$multimediaObject->getId().' have opencast tracks from OC imported');
            }
        }
    }

    private function importMasterTracks(OutputInterface $output, array $multimediaObjects): void
    {
        $output->writeln(
            [
                '',
                '<info> **** Import master tracks to multimedia object **** </info>',
                '',
                '<comment> ----- Total: </comment>'.count($multimediaObjects),
            ]
        );

        foreach ($multimediaObjects as $multimediaObject) {
            if (!$multimediaObject->getTrackWithTag('master')) {
                sleep($this->secondsToSleep);
                $this->importTrackOnMultimediaObject(
                    $output,
                    $multimediaObject,
                    true
                );
            } else {
                $output->writeln('<info> Multimedia Object - '.$multimediaObject->getId().' have master tracks from OC imported');
            }
        }
    }

    private function importTrackOnMultimediaObject(OutputInterface $output, MultimediaObject $multimediaObject, bool $master): void
    {
        if ($master) {
            $mediaPackage = $this->clientService->getMasterMediaPackage($multimediaObject->getProperty('opencast'));
            $trackTags = ['master'];
        } else {
            $mediaPackage = $this->clientService->getMediaPackage($multimediaObject->getProperty('opencast'));
            $trackTags = ['display'];
        }

        try {
            $this->opencastImportService->importTracksFromMediaPackage($mediaPackage, $multimediaObject, $trackTags);
            $this->showMessage($output, $multimediaObject, $mediaPackage);
        } catch (\Exception $exception) {
            $output->writeln('<error>Error - MMobj: '.$multimediaObject->getId().' and mediaPackage: '.$multimediaObject->getProperty('opencast').' with this error: '.$exception->getMessage().'</error>');
        }
    }

    private function showMultimediaObjects(OutputInterface $output, array $multimediaObjects, bool $master): void
    {
        $message = '<info> **** Finding Multimedia Objects **** </info>';
        if ($master) {
            $message = '<info> **** Finding Multimedia Objects (master)**** </info>';
        }
        $output->writeln(
            [
                '',
                $message,
                '',
                '<comment> ----- Total: </comment>'.count($multimediaObjects),
            ]
        );

        foreach ($multimediaObjects as $multimediaObject) {
            if ($master) {
                $mediaPackage = $this->clientService->getMasterMediaPackage($multimediaObject->getProperty('opencast'));
                $this->showMessage($output, $multimediaObject, $mediaPackage);
            } else {
                $mediaPackage = $this->clientService->getMediaPackage($multimediaObject->getProperty('opencast'));
                $this->showMessage($output, $multimediaObject, $mediaPackage);
            }
        }
    }

    private function showMessage(OutputInterface $output, MultimediaObject $multimediaObject, array $mediaPackage): void
    {
        $media = $this->opencastImportService->getMediaPackageField($mediaPackage, 'media');
        $tracks = $this->opencastImportService->getMediaPackageField($media, 'track');
        $tracksCount = 1;
        if (isset($tracks[0])) {
            $tracksCount = count($tracks);
        }

        $output->writeln(' Multimedia Object: '.$multimediaObject->getId().' - URL: '.$multimediaObject->getProperty('opencasturl').' - Tracks: '.$tracksCount);
    }
}
