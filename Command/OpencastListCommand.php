<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OpencastListCommand extends Command
{
    private $clientService;
    private $dm;

    public function __construct(DocumentManager $documentManager, ClientService $clientService)
    {
        $this->dm = $documentManager;
        $this->clientService = $clientService;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:opencast:list')
            ->setDescription('List imported or not mediapackages on PuMuKIT')
            ->setHelp(
                <<<'EOT'

            Show not imported mediaPackages on PuMuKIT

            Example:
            php bin/console pumukit:opencast:list
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$total, $mediaPackages] = $this->clientService->getMediaPackages('', 0, 0);

        $output->writeln('Total - '.$total);

        foreach ($mediaPackages as $mediaPackage) {
            $multimediaObject = $this->dm->getRepository(MultimediaObject::class)->findOneBy([
                'properties.opencast' => $mediaPackage['id'],
            ]);

            if (!$multimediaObject) {
                $output->writeln('MediaPackage - <info>'.$mediaPackage['id'].'</info>');
            }
        }

        return 0;
    }
}
