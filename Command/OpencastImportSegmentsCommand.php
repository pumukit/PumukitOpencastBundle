<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use Psr\Log\LoggerInterface;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Document\EmbeddedSegment;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OpencastImportSegmentsCommand extends Command
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
    private $secondsToSleep;

    public function __construct(DocumentManager $documentManager, LoggerInterface $logger, int $secondsToSleep)
    {
        $this->dm = $documentManager;
        $this->logger = $logger;
        $this->secondsToSleep = $secondsToSleep;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:opencast:import:segments')
            ->setDescription('Import segments from OC to PMK')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Opencast user')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Opencast password')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Path to selected tracks from PMK using regex')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'ID of multimedia object to import')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')
            ->setHelp(
                <<<'EOT'

            Important:

            Before executing the command add Opencast URL MAPPING configuration with OC data you will access.

            ---------------

            Command to import segments from Opencast to PuMuKIT defining Opencast configuration

            <info> ** Example ( check and list ):</info>

            <comment>php bin/console pumukit:opencast:import:segments --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es"</comment>
            <comment>php bin/console pumukit:opencast:import:segments --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --id="5bcd806ebf435c25008b4581"</comment>

            This example will be check the connection with these Opencast and list all multimedia objects from PuMuKIT find by regex host.

            <info> ** Example ( <error>execute</error> ):</info>

            <comment>php bin/console pumukit:opencast:import:segments --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --force</comment>
            <comment>php bin/console pumukit:opencast:import:segments --user="myuser" --password="mypassword" --host="https://opencast-local.teltek.es" --id="5bcd806ebf435c25008b4581" --force</comment>

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
            $multimediaObjects = $this->getMultimediaObjects();
            if ($this->force) {
                $this->importSegments($multimediaObjects);
            } else {
                $this->showMultimediaObjects($multimediaObjects);
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

    private function getMultimediaObjects()
    {
        $criteria = [
            'properties.opencasturl' => new Regex($this->host, 'i'),
        ];

        if ($this->force) {
            $criteria['embeddedSegments'] = ['$exists' => false];
        }

        if ($this->id) {
            $criteria['_id'] = new ObjectId($this->id);
        }

        return $this->dm->getRepository(MultimediaObject::class)->findBy($criteria);
    }

    private function importSegments(array $multimediaObjects): void
    {
        $this->output->writeln(
            [
                '',
                '<info> **** Import segments on multimedia object **** </info>',
                '',
                '<comment> ----- Total: </comment>'.count($multimediaObjects),
            ]
        );

        foreach ($multimediaObjects as $multimediaObject) {
            sleep($this->secondsToSleep);
            $mediaPackage = $this->clientService->getFullMediaPackage($multimediaObject->getProperty('opencast'));

            $segments = 0;
            if (isset($mediaPackage['segments']['segment'])) {
                if (!isset($mediaPackage['segments']['segment'][0])) {
                    $segments = [$mediaPackage['segments']['segment']];
                } else {
                    $segments = $mediaPackage['segments']['segment'];
                }
                $embeddedSegments = [];
                foreach ($segments as $segment) {
                    $embeddedSegments[] = $this->createNewSegment($segment);
                }

                if ($embeddedSegments) {
                    $multimediaObject->setEmbeddedSegments($embeddedSegments);
                    $this->dm->flush();
                }
            }
            $numSegments = isset($mediaPackage['segments']['segment']) ? count($segments) : 0;
            $this->output->writeln(' Multimedia object: '.$multimediaObject->getId().' MediaPackage: -'.$multimediaObject->getProperty('opencast').' - Segments: '.$numSegments);
        }
    }

    private function showMultimediaObjects(array $multimediaObjects): void
    {
        $this->output->writeln(
            [
                '',
                '<info> **** Finding Multimedia Objects **** </info>',
                '',
                '<comment> ----- Total: </comment>'.count($multimediaObjects),
            ]
        );

        foreach ($multimediaObjects as $multimediaObject) {
            $mediaPackage = $this->clientService->getFullMediaPackage($multimediaObject->getProperty('opencast'));
            $numSegments = 0;
            if (isset($mediaPackage['segments'])) {
                if (!isset($mediaPackage['segments']['segment'][0])) {
                    $segments = [$mediaPackage['segments']['segment']];
                } else {
                    $segments = $mediaPackage['segments']['segment'];
                }

                $numSegments = isset($mediaPackage['segments']['segment']) ? count($segments) : 0;
            }
            $this->output->writeln(' Multimedia object: '.$multimediaObject->getId().' MediaPackage: -'.$multimediaObject->getProperty('opencast').' - Segments: '.$numSegments);
        }
    }

    private function createNewSegment(array $segment): EmbeddedSegment
    {
        $embeddedSegment = new EmbeddedSegment();

        $embeddedSegment->setIndex($segment['index']);
        $embeddedSegment->setTime($segment['time']);
        $embeddedSegment->setDuration($segment['duration']);
        $embeddedSegment->setRelevance($segment['relevance']);
        $embeddedSegment->setHit((bool) $segment['hit']);
        $embeddedSegment->setText($segment['text']);

        $image = $segment['previews']['preview']['$'] ?? '';
        $embeddedSegment->setPreview($image);

        $this->dm->persist($embeddedSegment);

        return $embeddedSegment;
    }
}
