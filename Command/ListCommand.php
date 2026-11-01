<?php

declare(strict_types=1);

namespace Pumukit\OpencastBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\OpencastBundle\Services\ClientService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pumukit:opencast:list', description: 'List the published Opencast recordings that are not in PuMuKIT')]
class ListCommand extends Command
{
    public const PAGE = 100;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ClientService $clientService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repository = $this->documentManager->getRepository(MultimediaObject::class);

        $rows = [];
        $total = 0;
        for ($offset = 0;; $offset += self::PAGE) {
            $result = $this->clientService->getMediaPackages('', self::PAGE, $offset);
            $total = $result->total;
            $imported = [];
            foreach ($repository->findBy(['properties.opencast' => ['$in' => $result->ids()]]) as $multimediaObject) {
                $imported[$multimediaObject->getProperty('opencast')] = true;
            }
            foreach ($result->mediaPackages as $mediaPackage) {
                if (!isset($imported[$mediaPackage->id()])) {
                    $rows[] = [$mediaPackage->id(), (string) $mediaPackage->title(), $mediaPackage->modified()?->format('Y-m-d') ?? ''];
                }
            }
            if (count($result->mediaPackages) < self::PAGE) {
                break;
            }
        }

        $io->text(sprintf('%d published mediapackages, %d not imported', $total, count($rows)));
        if ($rows) {
            $io->table(['Mediapackage', 'Title', 'Modified'], $rows);
        }

        return Command::SUCCESS;
    }
}
