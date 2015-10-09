<?php
namespace Diff\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\DialogHelper;
use Diff\Service\DbService;
use Diff\Service\CompareService;
use Diff\Model\Table;
use Diff\Model\Database;
use Symfony\Component\Console\Output\StreamOutput;


class CompareCommand extends Command
{
    /**
     * @var bool
     */
    protected $interactive = false;

    /**
     * @var DialogHelper
     */
    protected $dialog = null;

    protected function configure()
    {
        $this->setName('db:compare')
            ->setDescription('compare 2 Databases')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'The host on which both DBs are found', '127.0.0.1')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'The DB username to use', 'root')
            ->addOption('base', 'b', InputOption::VALUE_REQUIRED, 'The base DB (The db that needs to change)', null)
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'The target DB (The example schema we want to migrate to)', null)
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Compare interactively (prompt for possible renames, allow skipping changes')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var DialogHelper $dialog */
        $this->dialog = $this->getHelper('dialog');
        $dbService = $this->getDbService($input, $output);
        if (!$dbService) {
            return;
        }
        $compareService = new CompareService($dbService);
        $loadTables = $this->dialog->askConfirmation(
            $output,
            '<question>Do you wish to load table dependencies while loading the schemas? (Recommended)</question>',
            true
        );
        $dbs = $compareService->getDatabases($loadTables);
        $done = [];
        do {
            $tasks = $this->getTasks($output, $done);
            foreach ($tasks as $task) {
                $done[$task] = $this->processTask($task, $compareService, $output, $dbs, $loadTables);
            }
            $quit = $this->dialog->askConfirmation(
                $output,
                '<question>Do you want to quit?</question>',
                true
            );
        } while ($quit === false);
        $this->outputQueries($output, $done);
    }

    private function outputQueries(OutputInterface $output, array $queries)
    {
        /*
        $multiFiles = $this->dialog->askConfirmation(
            $output,
            '<question>Do you want to create separate files for each change-set? (default: no)</question>',
            false
        );*/
        $outFile = $this->dialog->ask(
            $output,
            '<question>Where do you want to write the queries to? (file path, leave blank for stdout)</question>',
            null
        );
        if ($outFile === null) {
            $outStream = $output;
        } else {
            $append = $this->dialog->askConfirmation(
                $output,
                '<question>Files will be truncated by default, do you wish to append output instead?</question>',
                false
            );
            $mode = $append ? 'a' : 'w';
            $outStream = fopen($outFile, $mode, false);
            if (!$outStream) {
                $output->writeln(
                    sprintf(
                        '<error>Failed to open file %s, falling back to stdout</error>',
                        $outFile
                    )
                );
                $outStream = $output;
            } else {
                $outStream = new StreamOutput($outStream);
            }
        }
        foreach ($queries as $section => $statements) {
            $outStream->writeln(
                sprintf(
                    '-- %s queries',
                    $section
                )
            );
            foreach ($statements as $q) {
                $outStream->writeln($q . ';' . PHP_EOL);
            }
            $outStream->writeln(
                sprintf(
                    '-- END %s queries ' . PHP_EOL,
                    $section
                )
            );
        }
    }

    /**
     * @param string $task
     * @param CompareService $service
     * @param OutputInterface $output
     * @param array $dbs
     * @param bool $loaded
     * @return array
     */
    protected function processTask($task, CompareService $service, OutputInterface $output, array $dbs, $loaded)
    {
        $queries = [];
        switch ($task) {
            case 'create':
                $renames = $this->dialog->askConfirmation(
                    $output,
                    '<question>Attempt to rename tables? - Default true if interactive + loaded dependencies, false in other cases</question>',
                    ($this->interactive && $loaded)
                );
                if ($renames && !$loaded) {
                    //inform the user, but let them shoot themselves in the foot regardless...
                    $output->writeln(
                        '<error>Renaming tables without checking relations between tables is dangerous!</error>'
                    );
                }
                $return = $service->getMissingTablesFor($dbs['base'], $dbs['target'], $renames);
                $output->writeln(
                    sprintf(
                        '<info>Added %d new tables (%s)</info>',
                        count($return['added']),
                        implode(', ', array_keys($return['added']))
                    )
                );
                /** @var Table $table */
                foreach ($return['added'] as $table) {
                    $queries[] = $table->getDefinitionString();
                }
                if ($return['renames']) {
                    $process = $this->processRenames($return['renames'], $output);
                    /** @var Database $base */
                    $base = $dbs['base'];
                    $base->addMissingTables($process['add']);
                    foreach ($process['add'] as $table) {
                        $queries[] = $table->getDefinitionString();
                    }
                    foreach ($process['rename'] as $oldName => $table) {
                        $queries[] = $table->getRenameQuery();
                        $base->applyRename($table, $oldName);
                    }
                }
                break;
            case 'alter':
                $dropFields = false;
                $checkFks = false;
                if ($this->interactive) {
                    $dropFields = $this->dialog->askConfirmation(
                        $output,
                        '<question>Drop fields not in target table? (default: false)</question>',
                        $dropFields
                    );
                    $checkFks = $this->dialog->askConfirmation(
                        $output,
                        '<question>Check Foreign Key constraints? (default: false)</question>',
                        $checkFks
                    );
                }
                $queries = $service->compareTables($dbs['base'], $dbs['target'], $dropFields, $checkFks);
                break;
            case 'constraints':
                $output->writeln('<info>constraints task not implemented yet</info>');
                $queries[] = '';
                break;
            case 'drop':
                if (!$loaded) {
                    $output->writeln(
                        '<error>Cannot reliably drop tables if relational table links were not set up</error>'
                    );
                    $output->writeln('<comment>As a result, these drop statements might not work</coment>');
                }
                $queries = $service->dropRedundantTables($dbs['base'], $dbs['target']);
                break;
            default:
                $queries[] = '';
        }
        return $queries;
    }

    protected function processRenames(array $renames, OutputInterface $output)
    {
        $return = [
            'add'       => [],
            'rename'    => [],
        ];
        foreach ($renames as $name => $data) {
            $output->writeln(
                sprintf(
                    '<info>Found missing table "%s", possible renames found:</info>',
                    $name
                )
            );
            if ($this->interactive) {
                $possibleRenames = [];
                $options = [];
                foreach ($data['possibleRenames'] as $name => $values) {
                    $possibleRenames[$name] = $values['table'];
                    $options[] = sprintf(
                        '%s (Similarity %.2f%%)',
                        $name,
                        $values['similarity']
                    );
                }
                $options = array_keys($data['possibleRenames']);
                $default = count($options);
                $options[] = 'Add as new table';
                $action = $this->dialog->select(
                    $output,
                    'Please select the table to rename, or select "Add as new table" (default)',
                    $options,
                    $default
                );
                if ($action === $default) {
                    $return['add'][$name] = $data['new'];
                } else {
                    $key = $options[$action];
                    /** @var Table $table */
                    $table = $possibleRenames[$key];
                    $return['rename'][$name] = $table->renameTable($name);
                }
            } else {
                if (count($data['possibleRenames']) > 1) {
                    $output->writeln(
                        sprintf(
                            '<info>More than one possible rename found, adding as new table</info>'
                        )
                    );
                    $return['add'][$name] = $data['new'];
                } else {
                    $names = array_keys($data['possibleRenames']);
                    $oldName = $names[0];
                    /** @var Table $table */
                    $table = $data['possibleRenames'][$oldName]['table'];
                    $table->renameTable($name);
                    $output->writeln(
                        sprintf(
                            '<info>Found one rename candidate, renaming table %s to %s (%.2f%% similarity)</info>',
                            $oldName,
                            $name,
                            $data['possibleRenames'][$oldName]['similarity']
                        )
                    );
                    $return['rename'][$oldName] = $table;
                }
            }
        }
        return $return;
    }

    private function getTasks(OutputInterface $output, array $done)
    {
        $options = [
            'create',
            'alter',
            'constraints',
            'drop',
        ];

        $selected = $this->dialog->select(
            $output,
            'Please select what you want to check (default is create and alter)',
            $options,
            '0,1',
            false,
            'Option "%s" is invalid',
            true
        );

        return array_map(function ($i) use ($options, $done, $output) {
            $action = $options[$i];
            if (array_key_exists($action, $done)) {
                $output->writeln(
                    sprintf(
                        '<info>Skipping task %s, already done</info>',
                        $action
                    )
                );
            } else {
                return $action;
            }
        }, $selected);
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
        $pass = (string) $this->dialog->askHiddenResponse(
            $output,
            '<question>Please enter the DB password (default "")</question>',
            false
        );
        $this->interactive = $input->getOption('interactive');
        return new DbService($host, $user, $pass, $base, $target);
    }

}