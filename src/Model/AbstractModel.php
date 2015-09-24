<?php
namespace Diff\Model;


abstract class AbstractModel
{
    /**
     * @var string
     */
    protected $statement = null;

    /**
     * @var null|string
     */
    protected $name = null;

    /**
     * @param string $stmt
     * @param null|string $name
     */
    public function __construct($stmt, $name = null)
    {
        $this->statement = trim($stmt);
        $this->name = $name;
        $this->parse($this->statement);
    }

    /**
     * @return null|string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getRawStatement()
    {
        return $this->statement;
    }

    /**
     * @param string $stmt
     * @return $this
     */
    abstract public function parse($stmt);

    /**
     * @param AbstractModel $to
     * @param bool|false $errOnNames
     * @return bool
     */
    public function isEqual(AbstractModel $to, $errOnNames = false)
    {
        if (!$to instanceof $this) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Cannot compare an instance of %s to an instance of %s',
                    get_class($this),
                    get_class($to)
                )
            );
        }
        if ($errOnNames && $this->getName() !== $to->getName()) {
            return $this->statement === $to->statement;
        }
    }

    /**
     * @param AbstractModel $to
     * @return bool
     */
    protected function formCompare(AbstractModel $to)
    {
        $requiredClass = get_class($this);
        $argClass = get_class($to);
        if ($requiredClass !== $argClass) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s::%s requires argument to be instance of %1$s, but was instance of %s',
                    $requiredClass,
                    __FUNCTION__,
                    $argClass
                )
            );
        }
        return ($this->getDefinitionString() === $to->getDefinitionString());
    }

    /**
     * @return string
     */
    abstract public function getDefinitionString();
}