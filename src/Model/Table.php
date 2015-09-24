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
        $this->fields[$new] = $field->setName($new);
        //apply rename to this object
        unset($this->fields[$old]);

        $indexes = $this->getIndexesWithField($old);
        foreach ($indexes as $name => $idx) {
            $parts[] = sprintf(
                'DROP INDEX `%s`',
                $idx
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
            sprintf('ADD COLUMN %s', $field->getDefaultDefstring()),
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
                        $rename = $this->getRenameParts($fk->getKey(), $current->getName());
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
     * get definition string, used by formCompare method
     * @return string
     */
    public function getDefinitionString()
    {
        return $this->statement;
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
            //field lines start with back-tick
            switch ($ln{0}) {
                case '`':
                    $field = new Field($ln);
                    $this->fields[$field->getName()] = $field;
                    break;
                case 'P':
                    $this->primary = new Primary($ln);
                    break;
                case 'U':
                case 'F':
                case 'K':
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
}