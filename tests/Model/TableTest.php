<?php

namespace Model;

use Diff\Model\Table;
use Diff\Model\Field;

class TableTest extends AbstractModel
{
    /**
     * @expectedException RuntimeException
     */
    public function testTableParse()
    {
        $query = $this->readInputFile('table.sql');
        $table = new Table($query);
        $this->assertEquals(
            'foobar',
            $table->getName()
        );
        $this->assertTrue(
            $table->hasField('foobar_id')
        );
        $this->assertTrue($table->hasPrimary());
        $field = new Field('', 'foobar_id');
        $table->addField($field);
    }
}