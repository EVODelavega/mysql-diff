<?php
namespace Diff\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\DialogHelper;
use Diff\Service\DbService;
use Diff\Service\CompareService;
use Diff\Model\Table;
use Diff\Model\Database;
use Symfony\Component\Console\Output\StreamOutput;


class CompareCommand extends Command
{
    const INTERACTION_NONE = 0;
    const INTERACTION_LOW = 1;
    const INTERACTION_HIGH = 2;
    const INTERACTION_EXTENDED = 3;

    const QUERY_USE = 0;
    const QUERY_REWRITE = 1;
    const QUERY_COMMENT = 2;
    const QUERY_SKIP = 3;

    /**
     * @var array
     */
    protected $queryOptions = [
        self::QUERY_USE => 'Use suggested query (default)',
        self::QUERY_REWRITE => 'Write custom query',
        self::QUERY_COMMENT => 'Comment out',
        self::QUERY_SKIP => 'Skip',
    ];

    /**
     * @var bool
     */
    protected $interactive = false;

    /**
     * @var DialogHelper
     */
    protected $dialog = null;

    /**
     * @var bool
     */
    protected $dropSchema = false;

    /**
     * @var array
     */
    protected $arguments = [
        'host'      => '127.0.0.1',
        'username'  => 'root',
        'password'  => '',
        'base'      => null,
        'target'    => null,
        'tables'    => null,
        'file'      => null,
    ];

    protected function configure()
    {
        $this->setName('db:compare')
            ->setDescription('Generate update statements for an existing DB')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'The host on which both DBs are found', '127.0.0.1')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'The DB username to use', 'root')
            ->addOption('base', 'b', InputOption::VALUE_REQUIRED, 'The base DB (The db that needs to change)', null)
            ->addOption('file', 'F', InputOption::VALUE_REQUIRED, 'The output file, if any', null)
            ->addOption('target', 't', InputOption::VALUE_REQUIRED,
                'The target DB (What the base DB should look like after we\'re done)', null)
            ->addOption('interactive', 'i', InputOption::VALUE_OPTIONAL,
                'Set interactivity level, similar to verbose flag: -i|ii|iii, default is no interaction', null)
            ->addArgument('tables', InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Specific tables you want to compare', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var DialogHelper $dialog */
        $this->dialog = $this->getHelper('dialog');
        $this->setInteraction($input, $output);
        //connect to db, check tables - load schema if required etc...
        $dbService = $this->getDbService($input, $output);
        if (!$dbService) {
            return;
        }
        try {
            $this->ensureCleanExit($input, $output, $dbService);
        } catch (\Exception $e) {
            if ($this->dropSchema) {
                $dbService->dropTargetSchema();
            }
            throw $e;
        }
        if ($this->dropSchema) {
            $dbService->dropTargetSchema();
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param DbService $dbService
     */
    private function ensureCleanExit(InputInterface $input, OutputInterface $output, DbService $dbService)
    {
        //begin comparing
        $compareService = new CompareService($dbService);
        //see if we only need to compare specific tables
        $tables = $input->getArgument('tables');
        if (!$tables) {
            $output->writeln(
                '<info>Comparing all tables</info>'
            );
            $tables = null;//ensure whitelist is null, not an empty array
        } else {
            $output->writeln(
                sprintf(
                    '<info>Attempt to compare tables: %s</info>',
                    implode(', ', $tables)
                )
            );
        }
        //default is to link tables...
        if ($this->interactive >= self::INTERACTION_HIGH) {
            $loadTables = $this->dialog->askConfirmation(
                $output,
                '<question>Do you wish to load table dependencies while loading the schemas? (Recommended)</question>',
                true
            );
        } else {
            $loadTables = true;
        }
        $dbs = $compareService->getDatabases($tables, $loadTables);
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

    /**
     * Get the the tasks to perform (can be called an infinite number of times
     * @param OutputInterface $output
     * @param array $done
     * @return array
     */
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

        return array_filter(
            array_map(function ($i) use ($options, $done, $output) {
                $action = $options[$i];
                if (array_key_exists($action, $done)) {
                    $output->writeln(
                        sprintf(
                            '<info>Skipping task %s, already done</info>',
                            $action
                        )
                    );
                    return null;
                }
                return $action;
            }, $selected)
        );
    }

    /**
     * Runs at the end of the command -> write resulting queries to STDOUT or a chosen output file
     * @param OutputInterface $output
     * @param array $queries
     */
    private function outputQueries(OutputInterface $output, array $queries)
    {
        if (!$this->arguments['file']) {
            $outFile = $this->dialog->ask(
                $output,
                '<question>Where do you want to write the queries to? (file path, leave blank for stdout)</question>',
                $this->arguments['file']
            );
        } else {
            $outFile = $this->arguments['file'];
        }
        if ($outFile === null) {
            $outStream = $output;
        } else {
            if ($this->interactive >= self::INTERACTION_HIGH) {
                $append = $this->dialog->askConfirmation(
                    $output,
                    '<question>Files will be truncated by default, do you wish to append output instead?</question>',
                    false
                );
                $mode = $append ? 'a' : 'w';
            } else {
                //default is to truncate
                $mode = 'w';
            }
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
        $confirmKeys = [];
        if ($this->interactive > self::INTERACTION_LOW) {
            $confirmKeys = [
                'alter' => true,
                'drop'  => true,
            ];
        }
        if ($this->interactive === self::INTERACTION_EXTENDED) {
            $confirmKeys['create'] = true;
            $confirmKeys['constraints'] = true;
        }
        foreach ($queries as $section => $statements) {
            $interactive = isset($confirmKeys[$section]);
            if ($interactive && $this->interactive === self::INTERACTION_EXTENDED) {
                $details = sprintf(
                    '<question>Do you wish to skip all %d queries for section %s (default false)</question>',
                    count($statements),
                    $section
                );
                $skip = $this->dialog->askConfirmation(
                    $output,
                    $details,
                    false
                );
                if ($skip) {
                    //skip section
                    continue;
                }
            }
            $outStream->writeln(
                sprintf(
                    '-- %s queries',
                    $section
                )
            );
            foreach ($statements as $q) {
                $this->writeQueryString($outStream, $output, $q, $interactive);
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
     * @param OutputInterface $outStream
     * @param OutputInterface $output
     * @param string $query
     * @param bool $interactive
     */
    protected function writeQueryString(OutputInterface $outStream, OutputInterface $output, $query, $interactive)
    {
        if ($interactive) {
            $output->writeln(
                sprintf(
                    '<info>Query: %s</info>',
                    $query
                )
            );
            $keep = (int) $this->dialog->select(
                $output,
                '<question>What do you want to do with this query?</question>',
                $this->queryOptions,
                self::QUERY_USE
            );
            if ($keep === self::QUERY_SKIP) {
                //do not write query to $outStream
                return;
            }
            if ($keep === self::QUERY_COMMENT) {
                $query = sprintf(
                    '/** %s */',
                    $query
                );
            }
            if ($keep === self::QUERY_REWRITE) {
                $query = $this->dialog->ask(
                    $output,
                    '<comment>Your replacement query (blank uses current query)</comment>',
                    $query
                );
                $query = trim($query);
                if (substr($query, -1) === ';') {
                    $query = substr($query, 0, -1);//remove trailing semi-colon, we're adding it later anyway
                }
            }
        }
        $outStream->writeln($query . ';' . PHP_EOL);
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
                if ($this->interactive >= self::INTERACTION_LOW) {
                    $renames = $this->dialog->askConfirmation(
                        $output,
                        '<question>Attempt to rename tables? - Default true if interactive + loaded dependencies, false in other cases</question>',
                        ($this->interactive && $loaded)
                    );
                } else {
                    $renames = false;
                }
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
                    $output->writeln('<comment>As a result, these drop statements might not work</comment>');
                }
                $queries = $service->dropRedundantTables($dbs['base'], $dbs['target']);
                break;
            default:
                $queries[] = '';
        }
        return $queries;
    }

    /**
     * Process the possible table renames returned by the compare service
     * @param array $renames
     * @param OutputInterface $output
     * @return array
     */
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
                foreach ($data['possibleRenames'] as $name2 => $values) {
                    $possibleRenames[$name2] = $values['table'];
                    $options[] = sprintf(
                        '%s (Similarity %.2f%%)',
                        $name2,
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

    /**
     * Create DbService instance based on CLI options, prompt for pass
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return DbService
     */
    private function getDbService(InputInterface $input, OutputInterface $output)
    {
        $base = $input->getOption('base');
        if (!$base) {
            $output->writeln(
                '<error>Missing base DB name</error>'
            );
            return null;
        }
        $this->arguments['base'] = $base;
        $this->arguments['host'] = $input->getOption('host');
        $this->arguments['username'] = $input->getOption('username');
        $this->arguments['file'] = $input->getOption('file');
        $this->arguments['password'] = (string) $this->dialog->askHiddenResponse(
            $output,
            '<question>Please enter the DB password (default "")</question>',
            $this->arguments['password']
        );
        $target = $input->getOption('target');
        $schemaFile = null;
        if (!$target) {
            $target = 'compare_' . date('YmdHis');
            $output->writeln(
                sprintf(
                    '<info>Missing target DB name - creating schema %s</info>',
                    $target
                )
            );
            $schemaFile = $this->dialog->ask(
                $output,
                '<question>File to create base schema</question>',
                null
            );
            if (!$schemaFile || !file_exists($schemaFile)) {
                $output->writeln(
                    sprintf(
                        '<error>Invalid schema file: %s</error>',
                        $schemaFile
                    )
                );
                return null;
            }
        }
        $this->arguments['target'] = $target;
        $service = new DbService(
            $this->arguments['host'],
            $this->arguments['username'],
            $this->arguments['password'],
            $this->arguments['base'],
            $this->arguments['target']
        );
        $this->dropSchema = $schemaFile !== null;
        //ensure schemas exist, create target schema if required
        $service->checkSchemas($this->dropSchema);
        if ($schemaFile) {
            $service->loadTargetSchema($schemaFile);
        }
        return $service;
    }

    /**
     * Hacky way to support multiple interactivity modes similar to -v|vv|vvv
     * There must be a nicer way to do this... I hope
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return $this
     */
    private function setInteraction(InputInterface $input, OutputInterface $output)
    {
        $interaction = $input->getOption('interactive');
        if ($interaction === null) {
            //set to 1 if flag is used as-is
            if ($input->hasParameterOption(['-i', '--interactive'])) {
                $interaction = self::INTERACTION_LOW;
            } else {
                $interaction = self::INTERACTION_NONE;
            }
        } else {
            switch ($interaction) {
                case '0':
                    $interaction = self::INTERACTION_NONE;
                    break;
                case '1':
                    $interaction = self::INTERACTION_LOW;
                    break;
                case 'i':
                case '2':
                    $interaction = self::INTERACTION_HIGH;
                    break;
                case 'ii':
                case '3':
                    $interaction = self::INTERACTION_EXTENDED;
                    break;
                default:
                    $output->writeln(
                        sprintf(
                            '<error>Invalid value for interactive option: %s</error>',
                            $interaction
                        )
                    );
                    $options = [
                        self::INTERACTION_NONE      => 'Not interactive',
                        self::INTERACTION_LOW       => 'Normal interactivity',
                        self::INTERACTION_HIGH      => 'High interaction level (not implemented yet)',
                        self::INTERACTION_EXTENDED  => 'Extensive interaction (not implemented yet)',
                    ];
                    $interaction = $this->dialog->select(
                        $output,
                        '<question>Please select the desired interactivity level (default: Normal)</question>',
                        $options,
                        self::INTERACTION_LOW
                    );
                    break;
            }
        }
        $this->interactive = (int) $interaction;
        return $this;
    }
}