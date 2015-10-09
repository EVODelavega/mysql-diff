<?php
namespace Diff\Model;

class Table extends AbstractModel
{
    /**
     * @var string
     */
    protected $first = null;

    /**
     * @var string
     */
    protected $last = null;

    /**
     * @var array
     */
    protected $fields = [];

    /**
     * @var null|Primary
     */
    protected $primary = null;

    /**
     * @var array
     */
    protected $indexes = [];

    /**
     * @var array
     */
    protected $constraints = [];

    /**
     * @var array
     */
    protected $dependencies = [];

    /**
     * @var string
     */
    protected $sortedDependencyString = null;

    /**
     * @var array
     */
    protected $guardianTables = [];

    /**
     * @var array
     */
    protected $dependantTables = [];

    /**
     * @var bool
     */
    protected $rename = false;

    /**
     * @param string $newName
     * @return $this
     */
    public function renameTable($newName)
    {
        $this->rename = $this->name;
        $this->name = $newName;
        /** @var Table $table */
        foreach ($this->guardianTables as $table) {
            //unset link with old name
            unset($table->dependantTables[$this->rename]);
            //add link with new name
            $table->dependantTables[$newName] = $this;
        }
        foreach ($this->dependantTables as $table) {
            //unset link with old name
            unset($table->guardianTables[$this->rename]);
            //update to new name
            $table->guardianTables[$newName] = $this;
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getDropQuery()
    {
        //first, remove the constraints from dependant tables
        $preQuery = [];
        /** @var Table $table */
        foreach ($this->dependantTables as $name => $table) {
            $preQuery[] = $table->getDropFKQuery(
                $table->getConstraintsByTable($this)
            );
        }
        $this->dependantTables = [];//remove dependant tables
        //then drop the table
        return sprintf(
            '%s;DROP TABLE IF EXISTS `%s`;',
            implode('; ', $preQuery),
            $this->name
        );
    }

    /**
     * @return $this
     */
    public function markRenamed()
    {
        $this->rename = false;
        return $this;
    }

    /**
     * @return string
     */
    public function getRenameQuery()
    {
        if (!$this->rename) {
            return '';
        }
        return sprintf(
            'RENAME TABLE %s TO %s;',
            $this->rename,
            $this->name
        );
    }

    /**
     * @return bool
     */
    public function isRenamed()
    {
        return $this->rename !== false;
    }

    /**
     * @return string
     */
    public function getSortedDependencyString()
    {
        if (!$this->sortedDependencyString) {
            $dependencies = $this->getDependencies();
            sort($dependencies);
            $this->sortedDependencyString = json_encode($dependencies);
        }
        return $this->sortedDependencyString;
    }

    /**
     * @return array
     */
    public function getDependencies()
    {
        if (!$this->dependencies && $this->constraints) {
            /** @var ForeignKey $fk */
            foreach ($this->constraints as $fk) {
                $this->dependencies[] = $fk->getReferences();
            }
        }
        return $this->dependencies;
    }

    /**
     * @return bool
     */
    public function hasGuardianTables()
    {
        return !empty($this->guardianTables);
    }

    /**
     * Get a copy of this instance, without the linked guardian/dependant table objects
     * @return Table
     */
    public function getUnlinkedCopy()
    {
        $copy = new Table('', $this->name);
        $copyFields = [];
        foreach ($this->fields as $name => $field) {
            $copyFields[$name] = clone $field;
        }
        $copy->fields = $copyFields;
        if ($this->primary) {
            $copy->primary = clone $this->primary;
        }
        $copyConstraints = [];
        foreach ($this->constraints as $name => $fk) {
            $copyConstraints[$name] = clone $fk;
        }
        $copyIndexes = [];
        foreach ($this->indexes as $name => $idx) {
            $copyIndexes[$name] = clone $idx;
        }
        $copy->statement = $this->statement;
        $copy->first = $this->first;
        $copy->last = $this->last;
        $copy->dependencies = $this->getDependencies();
        return $copy;
    }

    /**
     * @return array
     */
    public function getGuardianTables()
    {
        return $this->guardianTables;
    }

    /**
     * @param Table $table
     * @return $this
     */
    public function addGuardianTable(Table $table)
    {
        //some checks to prevent deadlocks when adding linked tables
        if ($this->isGuardianTable($table)) {
            return $this;//link already set
        }
        $name = $table->getName();
        if (isset($this->guardianTables[$name])) { //link exists, but not to the linked table!
            $this->removeGuardianTable($this->guardianTables[$name]);
        }
        $this->guardianTables[$table->getName()] = $table;
        $table->addDependantTable($this);
        return $this;
    }

    /**
     * @param Table $table
     * @return bool
     */
    public function isGuardianTable(Table $table)
    {
        return (isset($this->guardianTables[$table->getName()]) && $this->guardianTables[$table->getName()] === $table);
    }

    /**
     * @param Table $table
     * @return $this
     */
    public function removeGuardianTable(Table $table)
    {
        if (isset($this->guardianTables[$table->getName()])) {
            unset($this->guardianTables[$table->getName()]);
            $table->removeDependantTable($this);
        }
        return $this;
    }

    public function getConstraintsByTable(Table $guardian)
    {
        $tableName = $guardian->getName();
        $keys = [];
        /** @var ForeignKey $fk */
        foreach ($this->constraints as $name => $fk) {
            if ($fk->getReferences() == $tableName) {
                $keys[] = $name;
            }
        }
        return $keys;
    }

    /**
     * @param array $keys
     * @return string
     */
    public function getDropFKQuery(array $keys)
    {
        foreach ($keys as $key) {
            if (!isset($this->constraints[$key])) {
                throw new \RuntimeException(
                    sprintf(
                        'Table %s does not have FK %s defined',
                        $this->name,
                        $key
                    )
                );
            }
        }
        $query = sprintf(
            'ALTER TABLE `%s` DROP FOREIGN KEY %s',
            implode(', DROP FOREIGN KEY ', $keys)
        );
        foreach ($keys as $key) {
            //remove FK's
            unset($this->constraints[$key]);
        }
        return $query;
    }

    /**
     * @param Table $table
     * @return $this
     */
    public function addDependantTable(Table $table)
    {
        if ($this->isDependantTable($table)) {
            return $this;//link already made
        }
        $name = $table->getName();
        if (isset($this->dependantTables[$name])) {
            //incorrect link
            $this->removeDependantTable($this->dependantTables[$name]);
        }
        $this->dependantTables[$name] = $table;
        $table->addGuardianTable($this);
        return $this;
    }

    /**
     * @return array
     */
    public function getDependantTables()
    {
        return $this->dependantTables;
    }

    /**
     * @param Table $table
     * @return bool
     */
    public function isDependantTable(Table $table)
    {
        return (isset($this->dependantTables[$table->getName()]) && $this->dependantTables[$table->getName()] === $table);
    }

    /**
     * @param Table $table
     * @return $this
     */
    public function removeDependantTable(Table $table)
    {
        if (isset($this->dependantTables[$table->getName()])) {
            unset($this->dependantTables[$table->getName()]);
            $table->removeGuardianTable($this);
        }
        return $this;
    }

    /**
     * Remove dependant and guardian table links for this table
     * @return $this
     */
    public function unlinkTable()
    {
        //use array_slice: we're changing the arrays whilst iterating over them
        //that's messy, so instead: use a copy and remove them from the properties here
        $guardianTables = array_slice($this->guardianTables, 0);//copy guardians
        /** @var Table $guardian */
        foreach ($guardianTables as $guardian) {
            $this->removeGuardianTable($guardian);
        }
        $dependantTables = array_slice($this->dependantTables, 0);//create copy
        /** @var Table $dependant */
        foreach ($dependantTables as $dependant) {
            $this->removeDependantTable($dependant);
        }
        return $this;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasField($name)
    {
        return array_key_Exists($name, $this->fields);
    }

    /**
     * @param string $name
     * @return Field
     */
    public function getField($name)
    {
        if (!$this->hasField($name)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Table %s does not have a field called %s',
                    $this->name,
                    $name
                )
            );
        }
        return $this->fields[$name];
    }

    /**
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * @return array
     */
    public function getFieldNames()
    {
        return array_keys($this->fields);
    }

    /**
     * @param Table $compare
     * @return float
     */
    public function getSimilarityPercentage(Table $compare)
    {
        $fieldPerc = $this->getFieldSimilarityPercentage($compare);
        $indexPerc = $this->getIndexSimilarityPercentage($compare);
        $primaryPerc = $this->getPrimarySimilarityPercentage($compare, false);
        //similarity of fields is way more important than indexes are
        //the PK itself is as important as all indexes combined, that seems fair
        $total = $fieldPerc*2 + $indexPerc + $primaryPerc;
        return round($total/400, 2);//max value of total is 400
    }

    /**
     * @param Table $compare
     * @return float
     */
    protected function getFieldSimilarityPercentage(Table $compare)
    {
        $fieldCount = count($this->fields);
        $targetFieldCount = count($compare->fields);
        //use highest of the two, if the number of fields does not match, the similarity percentage should drop
        if ($fieldCount < $targetFieldCount) {
            $fieldCount = $targetFieldCount;
        }
        $sharedFields = 0;
        foreach ($this->getFieldNames() as $fieldName) {
            $sharedFields += $compare->hasField($fieldName);//false == +0, true == +1
        }
        return round(($sharedFields/$fieldCount)*100, 2);
    }

    /**
     * @param Table $compare
     * @return float
     */
    protected function getIndexSimilarityPercentage(Table $compare)
    {
        $indexCount = count($this->indexes);
        $targetIndexCount = count($compare->indexes);
        if ($indexCount < $targetIndexCount) {
            $indexCount = $targetIndexCount;
        }
        if ($indexCount === 0) {
            return 50;
        }
        $sharedIndexes = 0;
        /** @var Index $idx */
        foreach ($this->indexes as $idxName => $idx) {
            $hasIndex = $compare->hasIndex($idxName);
            if ($hasIndex) {
                /** @var Index $compareIdx */
                $compareIdx = $compare->indexes[$idxName];
                foreach ($idx->getFields() as $idxField) {
                    if (!$compareIdx->containsField($idxField)) {
                        $hasIndex /= 2;
                    }
                }
                foreach ($compareIdx->getFields as $idxField) {
                    if (!$idx->containsField($idxField)) {
                        $hasIndex /=2;
                    }
                }
            }
            $sharedIndexes += $hasIndex;
        }
        return round(($sharedIndexes/$indexCount)*100, 2);
    }

    /**
     * @param Table $compare
     * @param bool|false $standAlone
     * @return float|int
     */
    protected function getPrimarySimilarityPercentage(Table $compare, $standAlone = false)
    {
        //nothing to compare, in terms of similarity they're identical
        if (!$this->hasPrimary() && $compare->hasPrimary()) {
            $similarity = 100;
            if ($standAlone === false) {
                //but let's not count that as a full match when this percentage
                //is used to determine the overall similarity percentage
                $similarity = 80;
            }
            return $similarity;
        }
        if (!$this->hasPrimary() && $compare->hasPrimary()) {
            //it is possible the primary key needs to be added, check for missing field:
            $similarity = 0;
            foreach ($compare->primary->getFieldNames() as $fieldName) {
                $similarity += $this->hasField($fieldName);
            }
            if ($similarity === count($compare->primary->getFieldNames())) {
                //all primary fields exist: 50/50 chance the primary needs to be added
                $similarity = 50;
            } else {
                //not all fields exist, but count those that do as a 10% similarity
                $similarity *= 10;
            }
            return $similarity;
        } elseif ($this->primary && !$compare->hasPrimary()) {
            //this table has a primary, but the target one doesn't... how likely is it to
            //DROP PRIMARY KEY without creating a new one? not very -> return that 1/1000 chance?
            return .1;
        }
        //both have PK's defined:
        $itemsCompared = 0;
        $similarity = (int) (count($this->primary->getFieldNames()) === count($compare->primary->getFieldNames()));
        ++$itemsCompared;
        $compareFields = $compare->primary->getFieldNames();
        foreach ($this->primary->getFieldNames() as $fieldName) {
            //does the PK contain the same key?
            $similarity += in_array($fieldName, $compareFields);
            //does the field exist?
            $similarity += $compare->hasField($fieldName);
            $itemsCompared += 2;

        }
        $ownFields = $this->primary->getFieldNames();
        foreach ($compareFields as $fieldName) {
            $similarity += in_array($fieldName, $ownFields);
            $similarity += $this->hasField($fieldName);
            $itemsCompared += 2;
        }
        if ($this->primary->isEqual($compare->primary)) {
            //if equality matches, vastly increase the chance of a 100% similarity being returned
            $similarity += $itemsCompared;
        }
        //give the isEqual call above the proper impact, and avoid returning values > 100%
        //by doubling the factor
        $itemsCompared *=2;
        return round(($similarity/$itemsCompared)*100, 2);
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasIndex($name)
    {
        return array_key_exists($name, $this->indexes);
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasConstraint($name)
    {
        return array_key_exists($name, $this->constraints);
    }

    /**
     * @return array
     */
    public function getConstraints()
    {
        return $this->constraints;
    }

    /**
     * @param string $name
     * @return null|ForeignKey
     */
    public function getConstraintByName($name)
    {
        if ($this->hasConstraint($name)) {
            return $this->constraints[$name];
        }
        return null;
    }

    /**
     * @return bool
     */
    public function hasPrimary()
    {
        return $this->primary instanceof Primary;
    }

    protected function findIndexesContaining($fieldName)
    {
        $alterIdx = [];
        /** @var Index $idx */
        foreach ($this->indexes as $name => $idx) {
            if ($idx->containsField($fieldName)) {
                $alterIdx[] = $name;
            }
        }
        return $alterIdx;
    }

    /**
     * @param array $parts
     * @return string
     */
    protected function wrapAlterParts(array $parts)
    {
        if (!$parts) {
            return '';//nothing changed, so no empty alter query
        }
        $query = [
            sprintf(
                'ALTER TABLE `%s`',
                $this->name
            ),
            implode(',' . PHP_EOL, $parts),
        ];
        return implode(PHP_EOL, $query) . ';';
    }

    /**
     * @param string $name
     * @param bool $namesOnly = false
     * @return array
     */
    protected function getIndexesWithField($name, $namesOnly = false)
    {
        $relevantIdx = [];
        /** @var Index $idx */
        foreach ($this->indexes as $idxName => $idx) {
            if ($idx->containsField($name)) {
                $relevantIdx[$idxName] = $idx;
            }
        }
        if ($namesOnly === true) {
            return array_keys($relevantIdx);
        }
        return $relevantIdx;
    }

    /**
     * @param string $new
     * @param string $old
     * @return array
     */
    protected function getRenameParts($new, $old)
    {
        $parts =[];
        /** @var Field $field */
        $field = $this->fields[$old];
        $field->setName($new);
        $this->fields[$new] = $field;
        //apply rename to this object
        unset($this->fields[$old]);

        $indexes = $this->getIndexesWithField($old);
        /** @var Index $idx */
        foreach ($indexes as $name => $idx) {
            $parts[] = sprintf(
                'DROP INDEX `%s`',
                $idx->getName()
            );
            //remove index name
            unset($this->indexes[$name]);
        }

        if ($this->hasPrimary() && $this->primary->containsField($old)) {
            $parts[] = 'DROP PRIMARY KEY';
            //remove PK
            $this->primary = null;
        }
        $parts[] = sprintf(
            'CHANGE COLUMN `%s` %s',
            $old,
            $field->getDefinitionString()
        );
        return $parts;
    }

    protected function addForeignKeyField(Table $target, ForeignKey $fk)
    {
        $field = $target->getField($fk->getKey());
        $parts = [
            sprintf('ADD COLUMN %s', $field->getDefinitionString()),
        ];
        $this->fields[$fk->getKey()] = $field;//add field
        /** @var Index $idx */
        foreach ($target->getIndexesWithField($field->getName()) as $name => $idx) {
            /*if ($this->hasIndex($name)) {
                //perhaps redefine indexes
                $parts[] = sprintf(
                    'DROP INDEX `%s`',
                    $name
                );
            }*/
            if (!$this->hasIndex($name)) {
                $parts[] = 'ADD ' . $idx->getDefinitionString();
                $this->indexes[$name] = $idx;//add new index
            }
        }
        return $parts;
    }

    /**
     * @param Table $target
     * @param array $parts = []
     * @return array
     */
    protected function compareFields(Table $target, array $parts = [])
    {
        /** @var Field $def */
        foreach ($target->fields as $name => $def) {
            if (!$this->hasField($name)) {
                $parts[] = 'ADD COLUMN ' . $def->getDefinitionString();
                $this->fields[$name] = $def;
            } else {
                /** @var Field $current */
                $current = $this->fields[$name];
                if ($current->getDefinitionString() != $def->getDefinitionString()) {
                    $parts[] = sprintf(
                        'CHANGE COLUMN `%s` %s',
                        $name,
                        $def->getDefinitionString()
                    );
                    //alter field definition object
                    $this->fields[$name] = $def;
                }
            }
        }
        return $parts;
    }

    /**
     * @param Table $target
     * @param array $parts
     * @return array
     */
    protected function purgeFields(Table $target, array $parts = [])
    {
        $fieldNames = array_keys($this->fields);
        foreach ($fieldNames as $name) {
            if (!$target->hasField($name)) {
                $parts[] = sprintf(
                    'DROP COLUMN `%s`',
                    $name
                );
                //remove field
                unset($this->fields[$name]);
                //drop all affected indexes
                $indexes = $this->getIndexesWithField($name, true);
                foreach ($indexes as $idx) {
                    unset($this->indexes[$idx]);
                    $parts[] = sprintf(
                        'DROP INDEX `%s`',
                        $idx
                    );
                }
            }
        }
        return $parts;
    }

    /**
     * @param Table $target
     * @param array $parts
     * @return array
     */
    protected function comparePrimary(Table $target, array $parts = [])
    {
        $targetPrimary = $target->primary;
        if ($this->hasPrimary()) {
            if (!$targetPrimary || $this->primary->getDefinitionString() !== $targetPrimary->getDefinitionString()) {
                $parts[] = 'DROP PRIMARY KEY';
                if ($targetPrimary) {
                    $parts[] = 'ADD ' . $targetPrimary->getDefinitionString();
                }
                $this->primary = $targetPrimary;
            }
        } elseif ($targetPrimary) {
            $parts[] = 'ADD ' . $targetPrimary->getDefinitionString();
            $this->primary = $targetPrimary;
        }
        return $parts;
    }

    /**
     * @param Table $target
     * @param array $parts
     * @return array
     */
    protected function compareIndexes(Table $target, array $parts = [])
    {
        /** @var Index $idx */
        foreach ($target->indexes as $name => $idx) {
            if (!$this->hasIndex($name)) {
                $parts[] = 'ADD ' . $idx->getDefinitionString();
                $this->indexes[$name] = $idx;
            } else {
                /** @var Index $current */
                $current = $this->indexes[$name];
                if ($current->getDefinitionString() != $idx->getDefinitionString()) {
                    $parts[] = sprintf(
                        'DROP INDEX `%s`',
                        $name
                    );
                    $parts[] = 'ADD ' . $idx->getDefinitionString();
                    $this->indexes[$name] = $idx;
                }
            }
        }
        return $parts;
    }

    /**
     * @param Table $target
     * @return array
     */
    public function compareConstraints(Table $target)
    {
        $fks = [];
        /** @var ForeignKey $fk */
        foreach ($target->constraints as $name => $fk) {
            if ($this->hasConstraint($name)) {
                /** @var ForeignKey $current */
                $current = $this->constraints[$name];
                if ($current->getDefinitionString() != $fk->getDefinitionString()) {
                    if ($current->getReferences() === $fk->getReferences()) {
                        //they both reference the same field, but the key's changed
                        $rename = $this->getRenameParts($fk->getKey(), $current->getKey());
                        $rename[] = sprintf('DROP FOREIGN KEY `%s`', $name);
                        $rename[] = 'ADD ' . $fk->getDefinitionString();
                        $fks[] = $this->wrapAlterParts($rename);
                    } else {
                        if ($this->hasField($fk->getName())) {
                            $redefine = [];
                        } else {
                            $redefine = $this->addForeignKeyField($target, $fk);
                        }
                        $redefine[] = sprintf('DROP FOREIGN KEY `%s`', $name);
                        $redefine[] = 'ADD ' . $fk->getDefinitionString();
                        $fks[] = $this->wrapAlterParts($redefine);
                    }
                    //update FK definition
                    $this->constraints[$name] = $fk;
                }
            } else {
                if (!$this->hasField($fk->getName())) {
                    $add = $this->addForeignKeyField($target, $fk);
                } else {
                    $add = [];
                }
                $add[] = 'ADD ' . $fk->getDefinitionString();
                $this->constraints[$name] = $fk;
                $fks[] = $this->wrapAlterParts($add);
            }
        }
        return $fks;
    }

    /**
     * @param Table $target
     * @param bool $purge = false
     * @param bool $includeFKs = false
     *
     * @return string
     */
    public function getChangeToQuery(Table $target, $purge = false, $includeFKs = false)
    {
        if ($this->isEqual($target, true)) {
            return '';
        }
        if ($includeFKs === true) {
            $fks = $this->compareConstraints($target);
        } else {
            $fks = [];
        }
        $parts = $this->compareFields($target);
        if ($purge === true) {
            $parts = $this->purgeFields($target, $parts);
        }
        $parts = $this->comparePrimary($target, $parts);
        $parts = $this->compareIndexes($target, $parts);

        //no real changes detected =>
        if (!$parts && !$fks) {
            return '';
        }

        return implode(PHP_EOL, $fks) . PHP_EOL . $this->wrapAlterParts($parts);
    }

    /**
     * @param array $parts = []
     * @return array
     */
    protected function getFieldDefinitions(array $parts = [])
    {
        /** @var Field $field */
        foreach ($this->fields as $field) {
            $parts[] = $field->getDefinitionString();
        }
        return $parts;
    }

    /**
     * @param array $parts = []
     * @return array
     */
    protected function getPrimaryKeyDefinition(array $parts = [])
    {
        if ($this->primary) {
            $parts[] = $this->primary->getDefinitionString();
        }
        return $parts;
    }

    /**
     * @param array $parts
     * @return array
     */
    protected function getIndexDefinitions(array $parts = [])
    {
        /** @var Index $index */
        foreach ($this->indexes as $index) {
            $parts[] = $index->getDefinitionString();
        }
        return $parts;
    }

    /**
     * @param array $parts
     * @return array
     */
    protected function getForeignKeyDefinitions(array $parts = [])
    {
        /** @var ForeignKey $fk */
        foreach ($this->constraints as $fk) {
            $parts[] = $fk->getDefinitionString();
        }
        return $parts;
    }

    /**
     * get definition string, used by formCompare method
     * @return string
     */
    public function getDefinitionString()
    {
        $parts = $this->getFieldDefinitions();
        $parts = $this->getPrimaryKeyDefinition($parts);
        $parts = $this->getIndexDefinitions($parts);
        $parts = $this->getForeignKeyDefinitions($parts);
        $stmt = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (%s)%s;',
            $this->name,
            implode(', ', $parts),
            $this->last
        );
        return str_replace('))', ')', $stmt);//possible double closing brackets, remove them
    }

    /**
     * @param string $stmt
     * @return $this
     */
    public function setStatement($stmt)
    {
        if ($this->statement) {
            $this->unlinkTable();
            $this->fields = [];
            $this->primary = null;
            $this->last = $this->first = '';
            $this->constraints = [];
        }
        return parent::setStatement($stmt);
    }

    /**
     * @param string $stmt
     * @return $this
     */
    public function parse($stmt)
    {
        $lines = array_map('trim', explode(PHP_EOL, $stmt));
        $this->first = array_shift($lines);
        $last = array_pop($lines);
        //remove AUTO_INCREMENT bit from raw statement
        $this->last = preg_replace('/AUTO_INCREMENT=\d+ ?/', '', $last);
        $this->statement = str_replace(
            $last,
            $this->last,
            $stmt
        );
        if ($this->name === null) {
            if (!preg_match('/`([^`]+)/', $this->first, $match)) {
                throw new \RuntimeException(
                    sprintf(
                        'Unable to extract name from %s (looked at line %s)',
                        $stmt,
                        $lines[0]
                    )
                );
            }
            $this->name = $match[1];
        }

        foreach ($lines as $ln) {
            if (mb_substr($ln, -1) == ',') {
                $ln = mb_substr($ln, 0, -1);
            }
            //field lines start with back-tick
            switch ($ln{0}) {
                case '`':
                    $field = new Field($ln);
                    $this->fields[$field->getName()] = $field;
                    break;
                case 'P':
                    $this->primary = new Primary($ln);
                    break;
                case 'S'://spatial
                case 'U'://unique
                case 'F'://fulltext
                case 'I'://index
                case 'K'://Key
                    $idx = new Index($ln);
                    $this->indexes[$idx->getName()] = $idx;
                    break;
                case 'C':
                    $constraint = new ForeignKey($ln);
                    $this->constraints[$constraint->getName()] = $constraint;
                    break;
                default:
                    throw new \LogicException(
                        sprintf(
                            'Unable to parse line %s in %s',
                            $ln,
                            $stmt
                        )
                    );
            }
        }
        return $this;
    }

    /**
     * Unlink tables on destruct, ensuring this instance isn't referenced anywhere
     */
    public function __destruct()
    {
        $this->unlinkTable();
    }
}