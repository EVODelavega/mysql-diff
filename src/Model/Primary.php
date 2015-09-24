<?php
namespace Diff\Model;


class Primary extends AbstractModel
{
    /**
     * @var array
     */
    protected $fieldNames = [];

    /**
     * @var string
     */
    protected $fieldString = null;

    /**
     * @return array
     */
    public function getFieldNames()
    {
        return $this->fieldNames;
    }

    /**
     * @return string
     */
    protected function getFieldString()
    {
        if (!$this->fieldString) {
            $this->fieldString = $this->fieldNames;
            sort($this->fieldString);
            $this->fieldString = implode('', $this->fieldString);
        }
        return $this->fieldString;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function containsField($name)
    {
        return in_array($name, $this->fieldNames);
    }

    /**
     * @param string $stmt
     * @return $this
     */
    public function parse($stmt)
    {
        preg_match_all('/`([^`]+)`/', $stmt, $matches);
        if (!$matches || !$matches[1]) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to parse PK definition %s',
                    $stmt
                )
            );
        }
        $this->fieldNames = $matches[1];
        return $this;
    }

    /**
     * @return string
     */
    public function getDefinitionString()
    {
        return sprintf(
            'PRIMARY KEY (`%s`)',
            implode(
                '`, `',
                $this->fieldNames
            )
        );
    }
}