<?php

namespace Doctrine\Tests\DBAL\Functional\Schema;

use Doctrine\DBAL\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

require_once __DIR__ . '/../../../TestInit.php';
 
class PostgreSqlSchemaManagerTest extends SchemaManagerFunctionalTestCase
{
    /**
     * @group DBAL-21
     */
    public function testSupportDomainTypeFallback()
    {
        $createDomainTypeSQL = "CREATE DOMAIN MyMoney AS DECIMAL(18,2)";
        $this->_conn->exec($createDomainTypeSQL);

        $createTableSQL = "CREATE TABLE domain_type_test (id INT PRIMARY KEY, value MyMoney)";
        $this->_conn->exec($createTableSQL);

        $table = $this->_conn->getSchemaManager()->listTableDetails('domain_type_test');
        $this->assertType('Doctrine\DBAL\Types\DecimalType', $table->getColumn('value')->getType());

        Type::addType('MyMoney', 'Doctrine\Tests\DBAL\Functional\Schema\MoneyType');
        $this->_conn->getDatabasePlatform()->registerDoctrineTypeMapping('MyMoney', 'MyMoney');

        $table = $this->_conn->getSchemaManager()->listTableDetails('domain_type_test');
        $this->assertType('Doctrine\Tests\DBAL\Functional\Schema\MoneyType', $table->getColumn('value')->getType());
    }

    /**
     * @group DBAL-37
     */
    public function testDetectsAutoIncrement()
    {
        $autoincTable = new \Doctrine\DBAL\Schema\Table('autoinc_table');
        $column = $autoincTable->addColumn('id', 'integer');
        $column->setAutoincrement(true);
        $this->_sm->createTable($autoincTable);
        $autoincTable = $this->_sm->listTableDetails('autoinc_table');

        $this->assertTrue($autoincTable->getColumn('id')->getAutoincrement());
    }

    /**
     * @group DBAL-37
     */
    public function testAlterTableAutoIncrementAdd()
    {
        $tableFrom = new \Doctrine\DBAL\Schema\Table('autoinc_table_add');
        $column = $tableFrom->addColumn('id', 'integer');
        $this->_sm->createTable($tableFrom);
        $tableFrom = $this->_sm->listTableDetails('autoinc_table_add');
        $this->assertFalse($tableFrom->getColumn('id')->getAutoincrement());

        $tableTo = new \Doctrine\DBAL\Schema\Table('autoinc_table_add');
        $column = $tableTo->addColumn('id', 'integer');
        $column->setAutoincrement(true);

        $c = new \Doctrine\DBAL\Schema\Comparator();
        $diff = $c->diffTable($tableFrom, $tableTo);
        $sql = $this->_conn->getDatabasePlatform()->getAlterTableSQL($diff);
        $this->assertEquals(array(
            "CREATE SEQUENCE autoinc_table_add_id_seq",
            "SELECT setval('autoinc_table_add_id_seq', (SELECT MAX(id) FROM autoinc_table_add))",
            "ALTER TABLE autoinc_table_add ALTER id SET DEFAULT nextval('autoinc_table_add_id_seq')",
        ), $sql);

        $this->_sm->alterTable($diff);
        $tableFinal = $this->_sm->listTableDetails('autoinc_table_add');
        $this->assertTrue($tableFinal->getColumn('id')->getAutoincrement());
    }

    /**
     * @group DBAL-37
     */
    public function testAlterTableAutoIncrementDrop()
    {
        $tableFrom = new \Doctrine\DBAL\Schema\Table('autoinc_table_drop');
        $column = $tableFrom->addColumn('id', 'integer');
        $column->setAutoincrement(true);
        $this->_sm->createTable($tableFrom);
        $tableFrom = $this->_sm->listTableDetails('autoinc_table_drop');
        $this->assertTrue($tableFrom->getColumn('id')->getAutoincrement());

        $tableTo = new \Doctrine\DBAL\Schema\Table('autoinc_table_drop');
        $column = $tableTo->addColumn('id', 'integer');

        $c = new \Doctrine\DBAL\Schema\Comparator();
        $diff = $c->diffTable($tableFrom, $tableTo);
        $this->assertType('Doctrine\DBAL\Schema\TableDiff', $diff, "There should be a difference and not false being returned from the table comparison");
        $this->assertEquals(array("ALTER TABLE autoinc_table_drop ALTER id DROP DEFAULT"), $this->_conn->getDatabasePlatform()->getAlterTableSQL($diff));

        $this->_sm->alterTable($diff);
        $tableFinal = $this->_sm->listTableDetails('autoinc_table_drop');
        $this->assertFalse($tableFinal->getColumn('id')->getAutoincrement());
    }

    /**
     * @group DBAL-75
     */
    public function testTableWithSchema()
    {
        $this->_conn->exec('CREATE SCHEMA nested');

        $nestedRelatedTable = new \Doctrine\DBAL\Schema\Table('nested.schemarelated');
        $column = $nestedRelatedTable->addColumn('id', 'integer');
        $column->setAutoincrement(true);
        $nestedRelatedTable->setPrimaryKey(array('id'));

        $nestedSchemaTable = new \Doctrine\DBAL\Schema\Table('nested.schematable');
        $column = $nestedSchemaTable->addColumn('id', 'integer');
        $column->setAutoincrement(true);
        $nestedSchemaTable->setPrimaryKey(array('id'));
        $nestedSchemaTable->addUnnamedForeignKeyConstraint($nestedRelatedTable, array('id'), array('id'));
        
        $this->_sm->createTable($nestedRelatedTable);
        $this->_sm->createTable($nestedSchemaTable);

        $tables = $this->_sm->listTableNames();
        $this->assertContains('nested.schematable', $tables, "The table should be detected with its non-public schema.");

        $nestedSchemaTable = $this->_sm->listTableDetails('nested.schematable');
        $this->assertTrue($nestedSchemaTable->hasColumn('id'));
        $this->assertEquals(array('id'), $nestedSchemaTable->getPrimaryKey()->getColumns());

        $relatedFks = $nestedSchemaTable->getForeignKeys();
        $this->assertEquals(1, count($relatedFks));
        $relatedFk = array_pop($relatedFks);
        $this->assertEquals("nested.schemarelated", $relatedFk->getForeignTableName());
    }
}

class MoneyType extends Type
{

    public function getName()
    {
        return "MyMoney";
    }

    public function getSqlDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return 'MyMoney';
    }

}