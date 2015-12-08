<?php
namespace Diff\Service;

use Diff\Model\Database;
use Diff\Model\Field;
use Diff\Model\Table;

class DbService
{
    const FOR_BASE = 1;
    const FOR_TARGET = 2;
    const FOR_BOTH = 3;

    /**
     * @var \PDO
     */
    protected $conn = null;

    /**
     * The schema on which to run the updates
     * @var string
     */
    protected $base = null;

    /**
     * The schema we want to "migrate" towards
     * @var string
     */
    protected $target = null;

    /**
     * @var \PDOStatement
     */
    protected $tblExistsStmt = null;

    /**
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $base
     * @param string $target
     * @throws \PDOException
     */
    public function __construct($host, $user, $pass, $base, $target)
    {
        $dsn = sprintf(
            'mysql:host=%s;charset=utf8;',
            $host
        );
        $this->conn = new \PDO(
            $dsn,
            $user,
            $pass,
            [
                \PDO::ATTR_ERRMODE              => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_ORACLE_NULLS         => \PDO::NULL_NATURAL,
                //\PDO::ATTR_DEFAULT_FETCH_MODE   => \PDO::FETCH_BOTH,
            ]
        );
        $this->base = $base;
        $this->target = $target;
    }

    /**
     * @param bool $createTarget
     */
    public function checkSchemas($createTarget = true)
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(SCHEMA_NAME) AS sc FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :name'
        );
        $stmt->execute(
            [
                ':name' => $this->base,
            ]
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row['sc'] < 1) {
            throw new \RuntimeException(
                sprintf(
                    'Schema %s does not exist (base schema)',
                    $this->base
                )
            );
        }
        $stmt->execute(
            [
                ':name' => $this->target,
            ]
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row['sc'] < 1) {
            if ($createTarget) {
                $this->conn->exec(
                    sprintf(
                        'CREATE DATABASE `%s` CHARACTER SET utf8',
                        $this->target
                    )
                );
            } else {
                throw new \RuntimeException(
                    sprintf(
                        'Datbase %s does not exist (target schema)',
                        $this->target
                    )
                );
            }
        }
    }

    public function dropTargetSchema()
    {
        $this->conn->exec(
            sprintf(
                'DROP DATABASE IF EXISTS `%s`',
                $this->target
            )
        );
    }

    /**
     * @param string $file
     */
    public function loadTargetSchema($file)
    {
        $queries = file_get_contents($file);
        $this->conn->exec(
            sprintf(
                'USE `%s`; %s',
                $this->target,
                $queries
            )
        );
    }

    /**
     * @return array
     */
    public function getDatabaseObjects()
    {
        return [
            'base'      => $this->getDatabase($this->base),
            'target'    => $this->getDatabase($this->target),
        ];
    }

    /**
     * @param string $dbName
     * @return Database|null
     */
    public function getDatabase($dbName)
    {
        $query = sprintf(
            'SHOW CREATE DATABASE `%s`;',
            trim(
                trim(
                    $dbName
                ),
                '`'
            )
        );
        $stmt = $this->conn->query($query);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$result || !isset($result['Create Database'])) {
            return null;
        }
        $db = new Database($result['Create Database'], $dbName);
        return $db;
    }

    /**
     * @param Database $db
     * @param array|null $whiteList = null
     * @param bool $resolveDependencies = true
     * @return Database
     */
    public function loadTablesForDatabase(Database $db, array $whiteList = null, $resolveDependencies = true)
    {
        $tables = $this->getTables(
            $db->getName()
        );
        foreach ($tables as $tName) {
            if ($whiteList === null || in_array($tName, $whiteList)) {
                $this->addCreateStatement(
                    new Table('', $tName),
                    $db
                );
            }
        }
        if ($resolveDependencies) {
            $db->linkTables();
        }
        return $db;
    }

    /**
     * @param Table $table
     * @param Database $db
     * @return Table
     */
    protected function addCreateStatement(Table $table, Database $db)
    {
        $stmt = $this->conn->query(
            sprintf(
                'SHOW CREATE TABLE %s.%s',
                $db->getName(),
                $table->getName()
            )
        );
        $create = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$create || !isset($create['Create Table'])) {
            return null;
        }
        $table->setStatement($create['Create Table'])
            ->parse($create['Create Table']);
        $db->addTable(
            $this->getTableFields($db, $table)
        );
        return $table;
    }

    /**
     * @param string $tbl
     * @param int $for
     * @return array
     */
    public function getCreateStatements($tbl, $for = self::FOR_BOTH)
    {
        $creates = [];
        if (($for & self::FOR_BASE) === self::FOR_BASE) {
            $stmt = $this->conn->query(
                sprintf(
                    'SHOW CREATE TABLE %s.%s',
                    $this->base,
                    $tbl
                )
            );
            $create = $stmt->fetch(\PDO::FETCH_ASSOC);
            $creates['base'] = $create['Create Table'];
        }
        if (($for & self::FOR_TARGET) === self::FOR_TARGET) {
            $stmt = $this->conn->query(
                sprintf(
                    'SHOW CREATE TABLE %s.%s',
                    $this->target,
                    $tbl
                )
            );
            $create = $stmt->fetch(\PDO::FETCH_ASSOC);
            $creates['target'] = $create['Create Table'];
        }
        return $creates;
    }

    /**
     * @param Database $db
     * @param Table $tbl
     * @return Table
     */
    public function getTableFields(Database $db, Table $tbl)
    {
        $query = sprintf(
            'SHOW COLUMNS IN `%s` FROM `%s`',
            $tbl->getName(),
            $db->getName()
        );
        $stmt = $this->conn->query($query);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $field = new Field('', $row['Field']);
            $field->setType($row['Type'])
                ->setNullable(strtoupper($row['Null']) == 'YES')
                ->setDefaultValue($row['Default']);
            if ($row['Extra']) {
                if ($row['Extra'] == 'auto_increment') {
                    $field->setAutoIncrement(true);
                } else {
                    //things like on update?
                    $field->setExtraString($row['Extra']);
                }
            }
            $tbl->addField($field, true);
        }
        return $tbl;
    }

    /**
     * @param $tbl
     * @param int $for
     * @return string
     */
    public function getDropStatement($tbl, $for = self::FOR_BASE)
    {
        $schema = $for === self::FOR_BASE ? $this->base : $this->target;
        return sprintf(
            'DROP TABLE %s.%s;',
            $schema,
            $tbl
        );
    }

    /**
     * @return array
     */
    public function getBaseTables()
    {
        return $this->getTables($this->base);
    }

    /**
     * @return array
     */
    public function getTargetTables()
    {
        return $this->getTables($this->target);
    }

    /**
     * @param string $schema
     * @return array
     */
    protected function getTables($schema)
    {
        $q = sprintf(
            'SHOW TABLES FROM %s',
            $schema
        );
        $tables = [];
        $stmt = $this->conn->query($q);
        while ($row = $stmt->fetch()) {
            $tables[] = $row[0];
        }
        return $tables;
    }

    /**
     * @param string $tblName
     * @return bool
     */
    public function baseHasTable($tblName)
    {
        return $this->tableInSchema(
            $this->base,
            $tblName
        );
    }

    /**
     * @param string $tblName
     * @return bool
     */
    public function targetHasTable($tblName)
    {
        return $this->tableInSchema(
            $this->target,
            $tblName
        );
    }

    /**
     * @param string $schema
     * @param string $table
     * @return bool
     */
    protected function tableInSchema($schema, $table)
    {
        $bind = [
            ':name'     => $table,
            ':schema'   => $schema,
        ];
        $stmt = $this->getTableExistsStmt();
        $stmt->execute($bind);
        $result = $stmt->fetch();
        $stmt->closeCursor();
        return $result['occ'] > 0;
    }

    /**
     * @return \PDOStatement
     */
    protected function getTableExistsStmt()
    {
        if (!$this->tblExistsStmt) {
            $this->tblExistsStmt = $this->conn->prepare(
                'SELECT COUNT(TABLE_NAME) as occ
                  FROM information_schema.TABLES
                  WHERE TABLE_NAME = :name AND TABLE_SCHEMA = :schema'
            );
            $this->tblExistsStmt->setFetchMode(\PDO::FETCH_ASSOC);
        }
        return $this->tblExistsStmt;
    }
}
