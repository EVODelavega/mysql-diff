<?php
namespace Diff\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Diff\Service\DbService;
use Diff\Service\CompareService;

class DiffCommand extends Command
{
    protected function configure()
    {
        $this->setName('db:diff')
            ->setDescription('Diff 2 Databases')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'The host on which both DBs are found', '127.0.0.1')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'The DB username to use', 'root')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'The DB password to use', '')
            ->addOption('base', 'b', InputOption::VALUE_REQUIRED, 'The base DB (The db that needs to change)', null)
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'The target DB (The example schema we want to migrate to)', null)
            ->addOption('create', 'c', InputOption::VALUE_NONE, 'Output create statements for missing tables')
            ->addOption('alter', 'a', InputOption::VALUE_NONE, 'Output alter statements')
            ->addOption('fks', 'F', InputOption::VALUE_NONE, 'When generating alter statement, include FK changes')
            ->addOption('drop', 'd', InputOption::VALUE_NONE, 'Output drop statements for tables that are not in target schema')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fks = false;
        $mode = 0;
        if ($input->getOption('create')) {
            $mode |= CompareService::PROCESS_CREATE;
        }
        if ($input->getOption('alter')) {
            $mode |= CompareService::PROCESS_ALTER;
            $fks = (bool) $input->getOption('fks');
        }
        if ($input->getOption('drop')) {
            $mode |= CompareService::PROCESS_DROP;
        }
        $output->writeln(
            sprintf(
                '<info>Processing tables using mode %d</info>',
                $mode
            )
        );
        if ($mode === 0) {
            $output->writeln(
                '<info>Nothing to process: use the tcaFd flags to control what should be checked</info>'
            );
            return;
        }
        $dbService = $this->getDbService($input, $output);
        if (!$dbService) {
            return;
        }
        $compare = new CompareService($dbService);
        $compare->processTables($mode, $fks);
        $changes =  $compare->getChanges();
        foreach ($changes as $key => $vals) {
            if ($vals) {
                $output->writeln(
                    sprintf(
                        '-- %s',
                        $key
                    )
                );
                foreach ($vals as $val) {
                    $output->writeln($val);
                }
            }
        }
    }

    private function getDbService(InputInterface $input, OutputInterface $output)
    {
        $base = $input->getOption('base');
        if (!$base) {
            $output->writeln(
                '<error>Missing base DB name</error>'
            );
            return null;
        }
        $target = $input->getOption('target');
        if (!$target) {
            $output->writeln(
                '<error>Missing target DB name</error>'
            );
            return null;
        }
        $host = $input->getOption('host');
        $user = $input->getOption('username');
        $pass = $input->getOption('password');
        return new DbService($host, $user, $pass, $base, $target);
    }
}