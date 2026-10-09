<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Assetsync20\AssetSyncService;
use GlpiPlugin\Assetsync20\Config\WorkerSettings;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class WorkerCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('plugins:assetsync20:worker')
            ->setDescription(
                'Run AssetSync2.0 once or as a continuous worker.'
            )
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Check configured GLPI B connections without syncing assets.'
            )
            ->addOption(
                'loop',
                null,
                InputOption::VALUE_NONE,
                'Repeat synchronization using the configured interval.'
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        if ($input->getOption('check')) {
            return $this->checkConnections($output);
        }

        if (!$input->getOption('loop')) {
            return $this->runOneCycle($output);
        }

        $output->writeln(
            '<info>AssetSync2.0 continuous worker started.</info>'
        );

        while (true) {
            $interval = WorkerSettings::getInterval();
            $cycleStartedAt = microtime(true);

            $this->runOneCycle($output);

            $elapsed = microtime(true) - $cycleStartedAt;
            $sleepSeconds = max(0, $interval - (int) ceil($elapsed));

            if ($sleepSeconds > 0) {
                $output->writeln(
                    sprintf(
                        '<comment>Next cycle in %d seconds.</comment>',
                        $sleepSeconds
                    )
                );

                sleep($sleepSeconds);
            } else {
                $output->writeln(
                    '<comment>Cycle took at least the configured interval; continuing.</comment>'
                );
            }
        }

        return self::SUCCESS;
    }

    private function checkConnections(OutputInterface $output): int
    {
        $connections = GlpiBConnection::loadAll(true);
        $activeCount = 0;
        $failed = false;

        if ($connections === []) {
            $output->writeln(
                '<comment>No GLPI B connections are configured.</comment>'
            );

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $name = $connection['name'] !== ''
                ? $connection['name']
                : '(unnamed connection)';

            if (!$connection['active']) {
                $output->writeln(
                    sprintf(
                        '<comment>SKIPPED: %s (inactive)</comment>',
                        $name
                    )
                );
                continue;
            }

            $activeCount++;

            try {
                $result = GlpiBConnection::test($connection);

                if (!empty($result['success'])) {
                    $output->writeln(
                        sprintf('<info>CONNECTED: %s</info>', $name)
                    );
                } else {
                    $failed = true;
                    $output->writeln(
                        sprintf('<error>FAILED: %s</error>', $name)
                    );
                }
            } catch (\Throwable $exception) {
                $failed = true;
                $output->writeln(
                    sprintf('<error>FAILED: %s</error>', $name)
                );
            }
        }

        if ($activeCount === 0) {
            $output->writeln(
                '<comment>No active GLPI B connections were found.</comment>'
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function runOneCycle(OutputInterface $output): int
    {
        $output->writeln(
            '<info>Starting AssetSync2.0 synchronization...</info>'
        );

        try {
            $processed = (new AssetSyncService())->run(10, 60, false);

            $output->writeln(
                sprintf(
                    '<info>AssetSync2.0 cycle finished. Processed: %d</info>',
                    $processed
                )
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln(
                '<error>AssetSync2.0 cycle failed: '
                . $exception->getMessage()
                . '</error>'
            );

            return self::FAILURE;
        }
    }
}

