<?php

namespace Pumukit\OpencastBundle\Command;

use Pumukit\SchemaBundle\Document\Series;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OpencastSyncSeriesCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('opencast:sync:series')
            ->setDescription('Syncs all series without an "opencast" property with Opencast')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'If set, the command will only show text output')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(sprintf('<info>Starting command</info>'), OutputInterface::VERBOSITY_VERBOSE);
        $dryRun = $input->getOption('dry-run');
        $numSynced = $this->syncSeries($output, $dryRun);
        $output->writeln(sprintf('<info>Synced %s series</info>', $numSynced));
    }

    protected function syncSeries(OutputInterface $output = null, bool $dryRun = false): int
    {
        $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $seriesRepo = $dm->getRepository(Series::class);
        $allSeries = $seriesRepo->findBy(['properties.opencast' => ['$exists' => 0]]);
        $dispatcher = $this->getContainer()->get('pumukitschema.series_dispatcher');

        $numSynced = 0;
        foreach ($allSeries as $series) {
            ++$numSynced;
            if (false === $dryRun) {
                $dispatcher->dispatchCreate($series);
            }
            if ($output) {
                $output->writeln(sprintf('<info>- Synced series with id %s </info>(%s)', $series->getId(), $numSynced), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        return $numSynced;
    }
}
