<?php
namespace Diff\Service;

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
            'mysql:%s;charset=utf8;',
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