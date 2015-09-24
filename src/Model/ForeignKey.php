<?php
namespace Diff\Model;


class ForeignKey extends AbstractModel
{
    /**
     * @var string
     */
    protected $key = null;

    /**
     * @var string
     */
    protected $references = null;

    /**
     * @var string
     */
    protected $refField = null;

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @return string
     */
    public function getReferences()
    {
        return $this->references;
    }

    /**
     * @return string
     */
    public function getRefField()
    {
        return $this->refField;
    }

    /**
     * @param string $stmt
     * @return $this
     */
    public function parse($stmt)
    {
        if (!preg_match_all('/`([^`]+)`/', $stmt, $matches)) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to parse constraint %s',
                    $stmt
                )
            );
        }
        $names = $matches[1];
        $this->name = $names[0];
        $this->key = $names[1];
        $this->references = $names[2];
        $this->refField = $names[3];
        return $this;
    }

    /**
     * @return string
     */
    public function getDefinitionString()
    {
        return sprintf(
            'CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)',
            $this->name,
            $this->key,
            $this->references,
            $this->refField
        );
    }
}