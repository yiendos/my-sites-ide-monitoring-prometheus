<?php

namespace Yiendos\MySitesIde\Monitoring\Prometheus\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiendos\MySitesIde\Monitoring\Prometheus\Ide;
use Yiendos\MySitesIde\Monitoring\Prometheus\Traits\InteractsWithPrometheus;

class PrometheusStartCommand extends Command
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
            ->setName('monitoring:prometheus-start')
            ->setDescription('Write Prometheus\'s config for the IDE\'s network, then start Prometheus')
        ;
    }

    /**
     * Writes storage/plugins/prometheus/conf/prometheus.yml from the stub, with
     * the IDE's Docker network filled in, so only this IDE's containers are
     * discovered. Prometheus only reads it when it starts, so a running
     * Prometheus is restarted when it's changed.
     *
     * `up -d --build` is a no-op for an up-to-date container, recreates one
     * whose compose config changed (e.g. a new PROMETHEUS_PORT), and builds
     * the image after an update to the Dockerfile.
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return integer
     */
    public function __invoke(OutputInterface $output, InputInterface $input, SymfonyStyle $io): int
    {
        if (!is_dir(Ide::storage('data'))) {
            mkdir(Ide::storage('data'), 0755, true);
        }

        $config = str_replace('__NETWORK__', Ide::network(), (string) file_get_contents(Ide::package('stubs/prometheus.yml')));
        $changed = Ide::write('conf/prometheus.yml', $config);
        $wasRunning = $this->running();

        $this->socketGroup();

        if ($this->compose($output, 'up -d --build prometheus') !== 0) {
            $io->error('Prometheus did not start - see above.');
            return Command::FAILURE;
        }

        if ($changed && $wasRunning && $this->compose($output, 'restart prometheus') !== 0) {
            $io->error('Prometheus did not restart with the new config - see above.');
            return Command::FAILURE;
        }

        $io->success('Prometheus started - http://localhost:' . (getenv('PROMETHEUS_PORT') ?: '9090'));

        return Command::SUCCESS;
    }
}
