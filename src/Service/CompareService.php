<?php
namespace Diff\Service;

use Diff\Model\Table;

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
     * @return array
     */
    public function getChanges()
    {
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
     * @return array
     */
    public function getCreateStatements()
    {
        return $this->changes['createTables'];
    }

    /**
     * @return array
     */
    public function getDropStatements()
    {
        return $this->changes['dropTables'];
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
                $this->changes['createTables'][] = $creates['target'];
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