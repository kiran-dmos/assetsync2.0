<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Assetsync20agent\Worker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class WorkerCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('plugins:assetsync20agent:worker')->setDescription('Deliver bounded Computer notifications to GLPI A.')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Repeat agent cycles.')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between cycle starts (1-3600).', '15');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = filter_var($input->getOption('interval'), FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 1 || $interval > 3600) {
            $output->writeln('<error>Agent interval must be between 1 and 3600 seconds.</error>');
            return self::FAILURE;
        }
        do {
            $started = hrtime(true);
            $hadCurrentTime = array_key_exists('glpi_currenttime', $_SESSION ?? []);
            $previousCurrentTime = $_SESSION['glpi_currenttime'] ?? null;
            $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
            try {
                $count = (new Worker())->run();
                $output->writeln('Agent cycle delivered/attempted: ' . $count);
            } catch (\Throwable) {
                $output->writeln('<error>Agent cycle failed; durable work retained.</error>');
                if (!$input->getOption('loop')) {
                    return self::FAILURE;
                }
            } finally {
                if ($hadCurrentTime) {
                    $_SESSION['glpi_currenttime'] = $previousCurrentTime;
                } else {
                    unset($_SESSION['glpi_currenttime']);
                }
            }
            if ($input->getOption('loop')) {
                $sleepMicroseconds = max(0, $interval * 1_000_000 - (int) ceil((hrtime(true) - $started) / 1_000));
                if ($sleepMicroseconds > 0) { usleep($sleepMicroseconds); }
            }
        } while ($input->getOption('loop'));
        return self::SUCCESS;
    }
}
