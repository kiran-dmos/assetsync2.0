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
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Repeat every 15 seconds.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            try {
                $count = (new Worker())->run();
                $output->writeln('Agent cycle delivered/attempted: ' . $count);
            } catch (\Throwable) {
                $output->writeln('<error>Agent cycle failed; durable work retained.</error>');
                if (!$input->getOption('loop')) {
                    return self::FAILURE;
                }
            }
            if ($input->getOption('loop')) {
                sleep(15);
            }
        } while ($input->getOption('loop'));
        return self::SUCCESS;
    }
}
