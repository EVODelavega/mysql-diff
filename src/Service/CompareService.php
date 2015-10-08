<?php
namespace Diff\Service;

use Diff\Model\ForeignKey;
use Diff\Model\Table;
use Diff\Model\Database;

class CompareService
{
    const PROCESS_CREATE = 1;
    const PROCESS_ALTER = 2;
    const PROCESS_PURGE = 4;
    const PROCESS_EXISTING = 7;
    const PROCESS_DROP = 8;
    const PROCESS_ALL = 15;

    /**
     * @var DbService
     */
    protected $dbService = null;

    /**
     * @var array
     */
    protected $baseTables = [];

    /**
     * @var array
     */
    protected $targetTables = [];

    /**
     * @var bool
     */
    protected $createsSorted = false;

    /**
     * @var array
     */
    protected $changes = [
        'createTables'  => [],
        'dropTables'    => [],
        'alterTables'   => [],
    ];

    /**
     * @param DbService $service
     */
    public function __construct(DbService $service)
    {
        $this->dbService = $service;
    }

    /**
     * Default behaviour is to sort create statements!
     * @return array
     */
    public function getChanges()
    {
        $this->getCreateStatements();
        return $this->changes;
    }

    /**
     * @return array
     */
    public function getAlterStatements()
    {
        return $this->changes['alterTables'];
    }

    /**
     * @param bool $sort = true
     * @return array
     */
    public function getCreateStatements($sort = true)
    {
        if (!$sort || $this->createsSorted) {
            return $this->changes['createTables'];
        }
        $resolved = [];
        $dependencyMap = [];
        $pending = [];
        //resolve simple tables (no dependencies)
        foreach ($this->changes['createTables'] as $query) {
            $table = new Table($query);
            $tableName = $table->getName();
            $depends = $table->getDependencies();
            if (!$depends) {
                $resolved[$tableName] = $query;
            } elseif ($this->dependenciesMet($resolved, $depends)) {
                $resolved[$tableName] = $query;
            } else {
                $dependencyMap[$tableName] = $depends;
                $pending[$tableName] = [
                    'query'     => $query,
                    'parsed'    => $table,
                ];
            }
        }
        //keep checking pending alters until we can't resolve any more queries
        $afterCount = count($resolved);
        do {
            $preCount = $afterCount;
            foreach ($dependencyMap as $name => $depends) {
                if ($this->dependenciesMet($resolved, $depends)) {
                    $resolved[$name] = $pending[$name]['query'];
                    unset($pending[$name]);
                } else {
                    //remove dependencies that might not be in create list here
                    $clean = [];
                    foreach ($depends as $name) {
                        if (isset($resolved[$name]) || isset($pending[$name])) {
                            $clean[] = $name;
                        }
                    }
                    //rework map
                    $dependencyMap[$name] = $clean;
                }
            }
            $afterCount = count($resolved);
        } while ($preCount != $afterCount);
        $this->changes['createTables'] = $this->addMarkedCreates($pending, $resolved);
        $this->createsSorted = true;
        return $this->changes['createTables'];
    }

    /**
     * @param array $pending
     * @param array $resolved
     * @return array
     */
    private function addMarkedCreates(array $pending, array $resolved)
    {
        foreach ($pending as $name => $data) {
            /** @var Table $table */
            $table = $data['parsed'];
            $unmet = array_diff($table->getDependencies(), array_keys($resolved));
            $resolved[$name] = sprintf(
                '%s -- Might have unmet dependencies on one or more of these tables: %s',
                $data['query'],
                implode(', ', $unmet)
            );
        }
        return $resolved;
    }

    /**
     * @param array $resolved
     * @param array $dependencies
     * @return bool
     */
    private function dependenciesMet(array $resolved, array $dependencies)
    {
        foreach ($dependencies as $name) {
            if (!isset($resolved[$name])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array
     */
    public function getDropStatements()
    {
        return $this->changes['dropTables'];
    }

    /**
     * @param string $dbName
     * @param bool $resolveDependencies = true
     * @return Database
     */
    public function getDatabase($dbName, $resolveDependencies = true)
    {
        $db = $this->dbService->getDatabase($dbName);
        if (!$db) {
            throw new \RuntimeException(
                sprintf(
                    'Schema "%s" not found',
                    $dbName
                )
            );
        }
        $this->dbService->loadTablesForDatabase($db, $resolveDependencies);
        return $db;
    }

    /**
     * @param bool $resolveDependencies
     * @return array
     */
    public function getDatabases($resolveDependencies = true)
    {
        $dbs = $this->dbService->getDatabaseObjects();
        /** @var Database $db */
        foreach ($dbs as $db) {
            $this->dbService->loadTablesForDatabase($db, $resolveDependencies);
        }
        return $dbs;
    }

    /**
     * @param Database $toUpgrade
     * @param Database $targetVersion
     * @param bool $checkRenames = false
     * @return array
     * @throws \RuntimeException
     */
    public function getMissingTablesFor(Database $toUpgrade, Database $targetVersion, $checkRenames = false)
    {
        $return = [
            'added'     => [],
            'renames'   => [],
        ];
        /**
         * @var string $name
         * @var  Table $table
         */
        foreach ($targetVersion->getTables() as $name => $table) {
            if ($checkRenames) {
                $renames = $this->crossCheckDependencies($toUpgrade, $table, $targetVersion);
                if ($renames) {
                    $return['renames'][$name] = [
                        'new'               => $table,
                        'possibleRenames'   => $renames,
                    ];
                    continue;
                }
            }
            if (!$toUpgrade->hasTable($name)) {
                $return['added'][$name] = $table;
            }
        }
        $toUpgrade->addMissingTables($return['added']);
        return $return;
    }

    /**
     * @param Database $toCheck
     * @param Table $missing
     * @param Database $from
     * @return array
     */
    protected function crossCheckDependencies(Database $toCheck, Table $missing, Database $from)
    {
        $renameCandidates = [];
        $possibleDrops = [];
        foreach ($toCheck as $name => $table) {
            if (!$from->hasTable($name)) {
                $possibleDrops[$name] = $table;
            }
        }
        $depString = $missing->getSortedDependencyString();
        /** @var Table $table */
        foreach ($possibleDrops as $name => $table) {
            if ($depString == $table->getSortedDependencyString()) {
                $renameCandidates[$name] = $table;
            } else {
                //X-check existing FK's
                /** @var ForeignKey $fk */
                foreach ($table->getConstraints() as $fkName => $fk) {
                    $missingFk = $missing->getConstraintByName($fkName);
                    if ($missingFk && $missingFk->getReferences() === $fk->getReferences()) {
                        $renameCandidates[$name] = $table;
                    }
                }
            }
        }
        return $renameCandidates;
    }

    /**
     * @param int $mode
     * @param bool $fks = false
     * @return $this
     */
    public function processTables($mode = self::PROCESS_EXISTING, $fks = false)
    {
        $existing = ($mode & self::PROCESS_EXISTING);
        if ($existing) {
            //should we purge columns?
            $purge = (($mode & self::PROCESS_PURGE) === self::PROCESS_PURGE);
            $checkNames = $this->processExisting($mode);
            foreach ($checkNames as $name) {
                /** @var Table $base */
                $base = $this->baseTables[$name];
                $query = $base->getChangeToQuery(
                    $this->targetTables[$name],
                    $purge,
                    $fks
                );
                if ($query) {
                    $this->changes['alterTables'][] = $query;
                }
            }
        }
        if (($mode & self::PROCESS_DROP) === self::PROCESS_DROP) {
            $this->getDropTables();
        }
        return $this;
    }

    /**
     * @param int $mode
     * @return array
     */
    protected function processExisting($mode)
    {
        $todo = [];
        $tables = $this->dbService->getTargetTables();
        $for = ($mode & self::PROCESS_ALTER) ? DbService::FOR_BOTH : DbService::FOR_TARGET;
        foreach ($tables as $tblName) {
            if ($for === DbService::FOR_BOTH && !$this->dbService->baseHasTable($tblName)) {
                $creates = $this->dbService->getCreateStatements($tblName, DbService::FOR_TARGET);
                $this->changes['createTables'][] = $creates['target'] . ';';//add semi-colon
            } else {
                $creates = $this->dbService->getCreateStatements($tblName, $for);
                if ($for === DbService::FOR_TARGET && !$this->dbService->baseHasTable($tblName)) {
                    $this->changes['createTables'][] = $creates['target'];
                } elseif ($for === DbService::FOR_BOTH) {
                    $todo[] = $tblName;
                    $this->targetTables[$tblName] = new Table($creates['target'], $tblName);
                    $this->baseTables[$tblName] = new Table($creates['base'], $tblName);
                }
            }
        }
        return $todo;
    }

    /**
     * @return $this
     */
    protected function getDropTables()
    {
        $tables = $this->dbService->getBaseTables();
        foreach ($tables as $table) {
            if (!$this->dbService->targetHasTable($table)) {
                $this->changes['dropTables'][] = $this->dbService->getDropStatement(
                    $table
                );
            }
        }
        return $this;
    }
}