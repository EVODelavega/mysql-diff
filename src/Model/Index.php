<?php
namespace Diff\Model;

class Index extends AbstractModel
{
    /**
     * @var bool
     */
    protected $unique = false;

    /**
     * @var bool
     */
    protected $fullText = false;

    /**
     * @var string
     */
    protected $type = '';

    /**
     * @var array
     */
    protected $fields = [];

    /**
     * @var string
     */
    protected $definition = null;

    /**
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * @return bool
     */
    public function isUnique()
    {
        return $this->unique;
    }

    /**
     * @param string $fieldName
     * @return bool
     */
    public function containsField($fieldName)
    {
        return in_array($fieldName, $this->fields);
    }

    /**
     * @param string $stmt
     * @return $this
     */
    public function parse($stmt)
    {
        if ($stmt{0} === 'U') {
            $this->unique = true;
            $this->type .= 'UNIQUE ';
        } elseif ($stmt{0} === 'F') {
            $this->fullText = true;
            $this->type .= 'FULLTEXT ';
        }
        if (!preg_match('/`([^`]+)[^(]*\((.+)\)/', $stmt, $match)) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to parse IDX definition %s',
                    $stmt
                )
            );
        }
        $this->name = $match[1];
        $this->definition = $match[2];
        if (!preg_match_all('/`([^`]+)`/', $match[2], $fields)) {
            throw new \LogicException(
                sprintf(
                    'Failed to extract field names from index definition %s (%s) (raw: %s)',
                    $this->name,
                    $this->definition,
                    $stmt
                )
            );
        }
        sort($fields[1]);
        $this->fields = $fields[1];
        return $this;
    }

    /**
     * @return string
     */
    public function getDefinitionString()
    {
        return sprintf(
            '%sKEY `%s` (%s)',
            $this->type,
            $this->name,
            $this->definition
        );
    }
}