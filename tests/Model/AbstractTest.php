<?php
namespace Model;

abstract class AbstractModel extends \PHPUnit_Framework_TestCase
{
    /**
     * @var string
     */
    private $path = null;

    /**
     * @param string $fname
     * @return $string
     */
    protected function readInputFile($fname)
    {
        return file_get_contents($this->getPath() . $fname);
    }

    /**
     * @return string
     */
    private function getPath()
    {
        if (!$this->path) {
            $this->path = realpath(
                dirname(__FILE__) .
                '/../_data/'
            ) . '/';
        }
        return $this->path;
    }
}
