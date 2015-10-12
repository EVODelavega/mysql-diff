<?php
namespace Diff\Model;


class Field extends AbstractModel
{
    /**
     * @var string
     */
    protected $type = null;

    /**
     * @var string
     */
    protected $attrString = '';

    /**
     * @var bool
     */
    protected $nullable = false;

    /**
     * @var bool
     */
    protected $unsigned = false;

    /**
     * @var bool
     */
    protected $autoIncrement = false;

    /**
     * @var null|string
     */
    protected $defaultValue = null;

    /**
     * @var null|string
     */
    protected $extra = null;

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return string
     */
    public function getAttrString()
    {
        return $this->attrString;
    }

    /**
     * @param string $attrString
     * @return $this
     */
    public function setAttrString($attrString)
    {
        $this->attrString = $attrString;
    }

    /**
     * @param string $extra
     * @return $this
     */
    public function setExtraString($extra)
    {
        $this->extra = $extra;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasExtra()
    {
        return $this->extra !== null;
    }

    /**
     * @return null|string
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * @return boolean
     */
    public function isUnsigned()
    {
        return $this->unsigned;
    }

    /**
     * @param boolean $unsigned
     * @return $this
     */
    public function setUnsigned($unsigned)
    {
        $this->unsigned = $unsigned;
        return $this;
    }

    /**
     * @return boolean
     */
    public function isAutoIncrement()
    {
        return $this->autoIncrement;
    }

    /**
     * @param boolean $autoIncrement
     * @return $this
     */
    public function setAutoIncrement($autoIncrement)
    {
        $this->autoIncrement = $autoIncrement;
        return $this;
    }

    /**
     * @return string
     */
    public function getDefaultDefstring()
    {
        if ($this->defaultValue === null) {
            return '';
        }
        return 'DEFAULT ' . $this->defaultValue;
    }

    /**
     * @return null|string
     */
    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    /**
     * @param null|string $defaultValue
     * @return $this
     */
    public function setDefaultValue($defaultValue)
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    /**
     * @return bool
     */
    public function isNullable()
    {
        return $this->nullable;
    }

    /**
     * @param bool $nullable
     * @return $this
     */
    public function setNullable($nullable)
    {
        $this->nullable = $nullable;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasDefaultValue()
    {
        return $this->defaultValue !== null;
    }

    /**
     * @return bool
     */
    public function hasAutoIncrement()
    {
        return $this->autoIncrement;
    }

    /**
     * @param string $stmt
     * @return $this
     */
    public function parse($stmt)
    {
        //definitions like "`field` text," cause issues here:
        if (preg_match('/^`([^`]+)`\s+([^\s,]+),$/', $stmt, $match)) {
            $this->name = $match[1];
            $this->type = $match[2];
            return $this;
        }
        if (!preg_match('/^`([^`]+)[`\s]*([^\s]+(?!\()|[^)]+\))\s*([^,]*?)(DEFAULT\s+([^,]+)|AUTO_INCREMENT)?,?$/', $stmt, $match)) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to parse field-definition %s',
                    $stmt
                )
            );
        }
        if (!$this->name) {
            $this->name = $match[1];
        }
        $this->type = trim($match[2]);
        //either NOT NULL or empty string
        $this->attrString = trim($match[3]);
        if (!$match[3]) {
            $this->nullable = true;
        } else {
            if (mb_strstr($match[3], 'unsigned')) {
                $this->unsigned = true;
            }
            if (mb_strstr($match[3], 'NOT NULL')) {
                $this->nullable = false;
            }
        }
        if (isset($match[4]) && $match[4] === 'AUTO_INCREMENT') {
            $this->attrString = $this->attrString . ' AUTO_INCREMENT';
            $this->autoIncrement = true;
        } elseif (mb_strstr($this->attrString, 'AUTO_INCREMENT') !== false) {
            $this->autoIncrement = true;
        }
        if (array_key_exists(5, $match)) {
            $this->defaultValue = $match[5];
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getDefinitionString()
    {
        $extra = $this->extra ? : '';
        return sprintf(
            '`%s` %s %s %s',
            $this->name,
            $this->type,
            $this->attrString,
            $this->getDefaultDefstring() . ' ' . $extra
        );
    }
}