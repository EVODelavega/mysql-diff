<?php
namespace Diff\Model;


class Database extends AbstractModel
{
    /**
     * @var bool
     */
    protected $dropExisting = false;

    /**
     * @var string
     */
    protected $charset = 'utf8';

    /**
     * @var array
     */
    protected $tables = [];

    /**
     * @var array
     */
    protected $sortedTables = [];

    /**
     * @param bool $forceResort = false
     * @return array
     */
    public function getCreateStatements($forceResort = false)
    {
        if ($forceResort) {
            $this->sortedTables = [];
        }
        $sorted = $this->getSortedTables();
        $queries = [];
        /** @var Table $table */
        foreach ($sorted as $name => $table) {
            $queries[$name] = $table->getDefinitionString();
        }
        return $queries;
    }

    /**
     * @return array
     */
    public function getSortedTables()
    {
        if (!$this->sortedTables) {
            /** @var Table $table */
            foreach ($this->tables as $name => $table) {
                if ($table->hasGuardianTables()) {
                    $this->prependGuardianTables($table);
                }
                $this->sortedTables[$name] = $table;
            }
        }
        return $this->sortedTables;
    }

    /**
     * @param Table $table
     */
    protected function prependGuardianTables(Table $table)
    {
        $guardians = $table->getGuardianTables();
        /** @var Table $guardian */
        foreach ($guardians as $name => $guardian) {
            if (!isset($this->sortedTables[$name])) {
                if ($guardian->hasGuardianTables()) {
                    //recursive resolving of guardian tables
                    $this->prependGuardianTables($guardian);
                }
                $this->sortedTables[$name] = $guardian;
            }
        }
    }

    /**
     * Add tables, bulk setter, ignores duplicate table names
     * Clears any tables already set on this DB
     * @param array $tables
     * @return $this
     */
    public function setTables(array $tables)
    {
        if ($this->tables) {
            $this->tables = [];
        }
        foreach ($tables as $table) {
            if ($table instanceof Table) {
                $this->addTable($table);
            } elseif (is_string($table)) {
                $this->addTableByString($table);
            }
        }
        return $this;
    }

    /**
     * @return array
     */
    public function getTables()
    {
        return $this->tables;
    }

    /**
     * @param string $name
     * @return null|Table
     */
    public function getTableByName($name)
    {
        if (!isset($this->tables[$name])) {
            return null;
        }
        return $this->tables[$name];
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasTable($name)
    {
        return isset($this->tables[$name]);
    }

    /**
     * @param Table $tbl
     * @param bool $ignoreDuplicate = true
     * @return $this
     */
    public function addTable(Table $tbl, $ignoreDuplicate = true)
    {
        $name = $tbl->getName();
        if (isset($this->tables[$name])) {
            if ($ignoreDuplicate === false) {
                throw new \RuntimeException(
                    sprintf(
                        'DB %s already contains a table named "%s"',
                        $this->name,
                        $name
                    )
                );
            }
            //@todo unlink table here
        }
        $this->tables[$name] = $tbl;
        return $this;
    }

    /**
     * @param string $query
     * @param bool $ignoreDuplicate
     * @return Table
     */
    public function addTableByString($query, $ignoreDuplicate = true)
    {
        $tbl = new Table($query);
        $this->addTable($tbl, $ignoreDuplicate);
        return $tbl;
    }

    /**
     * @param string $charSet
     * @return $this
     */
    public function setCharset($charSet)
    {
        $this->charset = $charSet;
        return $this;
    }

    /**
     * @return string
     */
    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * @param bool $drop
     * @return $this
     */
    public function setDropExisting($drop)
    {
        $this->dropExisting = (bool) $drop;
        return $this;
    }

    /**
     * @return bool
     */
    public function getDropExisting()
    {
        return $this->dropExisting;
    }

    /**
     * @param string $stmt
     * @return $this
     */
    public function parse($stmt)
    {
        if (!preg_match('/`([^`]+).+?([\w\d]+)\s\*\//', $stmt, $match)) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to parse statement "%s"',
                    $stmt
                )
            );
        }
        $this->name = $match[1];
        $this->charset = $match[2];
        return $this;
    }

    /**
     * @return string
     */
    public function getDefinitionString()
    {
        $pre = '';
        if ($this->dropExisting) {
            $pre = sprintf(
                'DROP SCHEMA IF EXISTS `%s`;' . PHP_EOL,
                $this->name
            );
        }
        return sprintf(
            '%sCREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET %s;',
            $pre,
            $this->name,
            $this->charset
        );
    }

}