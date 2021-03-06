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

    public function testFieldGetters()
    {
        $table = new Table(
            $this->readInputFile('table.sql')
        );
        $fieldNames = $table->getFieldNames();
        foreach($fieldNames as $field) {
            $this->assertTrue(
                $table->hasField($field)
            );
            $this->assertInstanceOf('Diff\Model\Field', $table->getField($field));
        }
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMsg Unable to parse field-definition
     */
    public function testBrokenTable()
    {
        $query = $this->readInputFile('bad_table.sql');
        $table = new Table($query);
        var_dump($table);
    }
}
