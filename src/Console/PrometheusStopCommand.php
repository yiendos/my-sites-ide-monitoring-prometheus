<?php

namespace Yiendos\MySitesIde\Monitoring\Prometheus\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Monitoring\Prometheus\Traits\InteractsWithPrometheus;

class PrometheusStopCommand extends Command
{
    use InteractsWithPrometheus;

    /**
     * The ability to configure the console command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('monitoring:prometheus-stop')
            ->setDescription('Stop the Prometheus container, leaving the rest of the IDE running')
        ;
    }

    /**
     * Stopped rather than removed, so monitoring:prometheus-start brings back
     * the same container. Its data is kept in storage/plugins/prometheus/
     * either way.
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        if (!$this->running()) {
            $io->writeln('Prometheus is not running - nothing to stop.');
            return Command::SUCCESS;
        }

        if ($this->compose($output, 'stop prometheus') !== 0) {
            $io->error('Prometheus did not stop - see above.');
            return Command::FAILURE;
        }

        $io->success('Prometheus stopped.');

        return Command::SUCCESS;
    }
}
