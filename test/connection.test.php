<?php
error_reporting(E_ALL | E_STRICT);
require_once 'simpletest/unit_tester.php';
require_once 'config.inc.php';

if (realpath($_SERVER['PHP_SELF']) == __FILE__) {
  require_once 'simpletest/autorun.php';
}
set_include_path(
  dirname(__FILE__) . '/../lib/' . PATH_SEPARATOR . get_include_path());

require_once 'pdoext.inc.php';
require_once 'pdoext/connection.inc.php';
require_once 'pdoext/query.inc.php';

class TestOfNestedTransactions extends UnitTestCase {
  protected $db;

  protected function getConnection() {
    return new pdoext_Connection("sqlite::memory:");
  }

  function setUp() {
    $this->db = $this->getConnection();
    $this->db->pexecute("CREATE TABLE test (id INTEGER)");
  }

  function tearDown() {
    $this->db->pexecute("DROP TABLE test");
  }

  function test_default_nested_transaction_not_allowed() {
    $this->db->beginTransaction();
    try {
      $this->db->beginTransaction();
      $this->fail("Expected a 'pdoext_AlreadyInTransactionException' exception");
      $this->db->rollback();
    } catch (pdoext_AlreadyInTransactionException $ex) {
      $this->pass();
    }
    $this->db->rollback();
  }

  function test_nested_transaction_everyting_gets_committed() {
    $this->db->enableNestedTransaction();
    $this->db->beginTransaction();
    $this->db->pexecute("INSERT INTO test VALUES (0)");
    $this->db->beginTransaction();
    $this->db->pexecute("INSERT INTO test VALUES (1)");
    $this->db->commit();
    $this->db->commit();
    $stmt = $this->db->pexecute("SELECT id FROM test");
    $this->assertEqual(2, count($stmt->fetchAll()));
  }

  function test_nested_transaction_something_gets_committed() {
    $this->db->enableNestedTransaction();
    $this->db->beginTransaction();
    $this->db->pexecute("INSERT INTO test VALUES (0)");
    $this->db->beginTransaction();
    $this->db->pexecute("INSERT INTO test VALUES (1)");
    $this->db->rollback();
    $this->db->commit();
    $stmt = $this->db->pexecute("SELECT id FROM test");
    $this->assertEqual(1, count($stmt->fetchAll()));
  }

  function test_nested_transaction_nothing_gets_committed() {
    $this->db->enableNestedTransaction();
    $this->db->beginTransaction();
    $this->db->pexecute("INSERT INTO test VALUES (0)");
    $this->db->beginTransaction();
    $this->db->pexecute("INSERT INTO test VALUES (1)");
    $this->db->commit();
    $this->db->rollback();
    $stmt = $this->db->pexecute("SELECT id FROM test");
    $this->assertEqual(0, count($stmt->fetchAll()));
  }
}

class TestOfInformationSchemaGetColumnsPostgresql extends UnitTestCase {
  protected $db;

  function skip() {
    $this->skipIf($GLOBALS['config']['db']['pgsql'] === null, "Postgresql connection not configured in config.inc.php");
  }

  protected function getConnection() {
    return new pdoext_Connection($GLOBALS['config']['db']['pgsql']);
  }

  function setUp() {
    $this->db = $this->getConnection();
    $this->db->beginTransaction();
  }

  function tearDown() {
    $this->db->rollback();
  }

  function testCanListColumnOfTable() {
    $this->db->pexecute("CREATE TABLE test (column1 SERIAL, column2 INTEGER NOT NULL, column3 VARCHAR DEFAULT 'test3', column4 TIMESTAMP, column5 DATE, PRIMARY KEY (column1))");
    $columns = $this->db->getInformationSchema()->getColumns('test');
    $this->assertEqual(5, count($columns));
    $this->assertTrue($columns['column1']['pk']);
    $this->assertFalse($columns['column2']['pk']);
    $this->assertFalse($columns['column3']['pk']);
    $this->assertFalse($columns['column4']['pk']);
    $this->assertFalse($columns['column5']['pk']);
  }

  function testMultiplePrimaryKey() {
    $this->db->pexecute("CREATE TABLE test (column1 SERIAL, column2 INTEGER NOT NULL, column3 VARCHAR DEFAULT 'test3', column4 TIMESTAMP, column5 DATE, PRIMARY KEY (column1, column2))");
    $columns = $this->db->getInformationSchema()->getColumns('test');
    $this->assertEqual(count($columns), 5);
    $this->assertTrue($columns['column1']['pk']);
    $this->assertTrue($columns['column2']['pk']);
    $this->assertFalse($columns['column3']['pk']);
    $this->assertFalse($columns['column4']['pk']);
    $this->assertFalse($columns['column5']['pk']);
  }

  function testCanListColumnOfTableInDifferentSchema() {
    $this->db->pexecute("CREATE SCHEMA test");
    $this->db->pexecute("CREATE TABLE test (column1 SERIAL, column2 INTEGER NOT NULL, column3 VARCHAR DEFAULT 'test3', column4 TIMESTAMP, column5 DATE)");
    $this->db->pexecute("CREATE TABLE test.test (id INTEGER);");
    $columns = $this->db->getInformationSchema()->getColumns('test.test');
    $this->assertEqual(count($columns), 1);
    $columns = $this->db->getInformationSchema()->getColumns('test');
    $this->assertEqual(count($columns), 5);
  }
}

class TestOfInformationSchemaGetForeignKeysPostgresql extends UnitTestCase {
  protected $db;

  function skip() {
    $this->skipIf($GLOBALS['config']['db']['pgsql'] === null, "Postgresql connection not configured in config.inc.php");
  }

  protected function getConnection() {
    return new pdoext_Connection($GLOBALS['config']['db']['pgsql']);
  }

  function setUp() {
    $this->db = $this->getConnection();
    $this->db->beginTransaction();
  }

  function tearDown() {
    $this->db->rollback();
  }

  function testCanGetForeignKeys() {
    $this->db->pexecute("CREATE TABLE table1 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE table2 (id SERIAL PRIMARY KEY, FOREIGN KEY (id) REFERENCES table1 (id))");

    $foreignKeys = $this->db->getInformationSchema()->getForeignKeys('table1');
    $this->assertEqual(0, count($foreignKeys));

    $foreignKeys = $this->db->getInformationSchema()->getForeignKeys('table2');
    $this->assertEqual(count($foreignKeys), 1);
    $this->assertEqual($foreignKeys[0]['table'], 'public.table2');
    $this->assertEqual($foreignKeys[0]['column'], 'id');
    $this->assertEqual($foreignKeys[0]['referenced_table'], 'public.table1');
    $this->assertEqual($foreignKeys[0]['referenced_column'], 'id');
  }

  function testCanGetForeignKeysInDifferentSchema() {
    $this->db->pexecute("CREATE SCHEMA test");
    $this->db->pexecute("CREATE TABLE test.table1 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE test.table2 (id SERIAL PRIMARY KEY, FOREIGN KEY (id) REFERENCES test.table1 (id))");

    $this->db->pexecute("CREATE TABLE table1 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE table2 (id SERIAL PRIMARY KEY)");

    $foreignKeys = $this->db->getInformationSchema()->getForeignKeys('table2');
    $this->assertEqual(0, count($foreignKeys));

    $foreignKeys = $this->db->getInformationSchema()->getForeignKeys('test.table2');
    $this->assertEqual(count($foreignKeys), 1);
    $this->assertEqual($foreignKeys[0]['table'], 'test.table2');
    $this->assertEqual($foreignKeys[0]['column'], 'id');
    $this->assertEqual($foreignKeys[0]['referenced_table'], 'test.table1');
    $this->assertEqual($foreignKeys[0]['referenced_column'], 'id');
  }

  function testCanGetMultipleForeignKeys() {
    $this->db->pexecute("CREATE TABLE table1 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE table2 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE table3 (id SERIAL PRIMARY KEY, FOREIGN KEY (id) REFERENCES table1 (id), FOREIGN KEY (id) REFERENCES table2 (id))");

    $foreignKeys = $this->db->getInformationSchema()->getForeignKeys('table3');
    $this->assertEqual(count($foreignKeys), 2);
    $this->assertEqual($foreignKeys[0]['table'], 'public.table3');
    $this->assertEqual($foreignKeys[0]['column'], 'id');
    $this->assertEqual($foreignKeys[0]['referenced_table'], 'public.table1');
    $this->assertEqual($foreignKeys[0]['referenced_column'], 'id');

    $this->assertEqual($foreignKeys[1]['table'], 'public.table3');
    $this->assertEqual($foreignKeys[1]['column'], 'id');
    $this->assertEqual($foreignKeys[1]['referenced_table'], 'public.table2');
    $this->assertEqual($foreignKeys[1]['referenced_column'], 'id');
  }
}

class TestOfInformationSchemaGetReferencingKeysPostgresql extends UnitTestCase {
  protected $db;

  function skip() {
    $this->skipIf($GLOBALS['config']['db']['pgsql'] === null, "Postgresql connection not configured in config.inc.php");
  }

  protected function getConnection() {
    return new pdoext_Connection($GLOBALS['config']['db']['pgsql']);
  }

  function setUp() {
    $this->db = $this->getConnection();
    $this->db->beginTransaction();
  }

  function tearDown() {
    $this->db->rollback();
  }

  function testCanGetReferencingKeys() {
    $this->db->pexecute("CREATE TABLE table1 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE table2 (id SERIAL PRIMARY KEY, FOREIGN KEY (id) REFERENCES table1 (id))");

    $foreignKeys = $this->db->getInformationSchema()->getReferencingKeys('table1');
    $this->assertEqual(count($foreignKeys), 1);
    $this->assertEqual($foreignKeys[0]['table'], 'public.table2');
    $this->assertEqual($foreignKeys[0]['column'], 'id');
    $this->assertEqual($foreignKeys[0]['referenced_table'], 'public.table1');
    $this->assertEqual($foreignKeys[0]['referenced_column'], 'id');

    $foreignKeys = $this->db->getInformationSchema()->getReferencingKeys('table2');
    $this->assertEqual(0, count($foreignKeys));
  }

  function testCanGetReferencingKeysInDifferentSchema() {
    $this->db->pexecute("CREATE SCHEMA test");
    $this->db->pexecute("CREATE TABLE test.table1 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE test.table2 (id SERIAL PRIMARY KEY, FOREIGN KEY (id) REFERENCES test.table1 (id))");

    $this->db->pexecute("CREATE TABLE table1 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE table2 (id SERIAL PRIMARY KEY)");

    $foreignKeys = $this->db->getInformationSchema()->getReferencingKeys('table1');
    $this->assertEqual(count($foreignKeys), 0);

    $foreignKeys = $this->db->getInformationSchema()->getReferencingKeys('test.table1');
    $this->assertEqual(count($foreignKeys), 1);
    $this->assertEqual($foreignKeys[0]['table'], 'test.table2');
    $this->assertEqual($foreignKeys[0]['column'], 'id');
    $this->assertEqual($foreignKeys[0]['referenced_table'], 'test.table1');
    $this->assertEqual($foreignKeys[0]['referenced_column'], 'id');
  }

  function testCanGetMultipleReferencingKeys() {
    $this->db->pexecute("CREATE TABLE table1 (id SERIAL PRIMARY KEY)");
    $this->db->pexecute("CREATE TABLE table2 (id SERIAL PRIMARY KEY, FOREIGN KEY (id) REFERENCES table1 (id))");
    $this->db->pexecute("CREATE TABLE table3 (id SERIAL PRIMARY KEY, FOREIGN KEY (id) REFERENCES table1 (id))");

    $foreignKeys = $this->db->getInformationSchema()->getReferencingKeys('table1');
    $this->assertEqual(count($foreignKeys), 2);
    $this->assertEqual($foreignKeys[0]['table'], 'public.table2');
    $this->assertEqual($foreignKeys[0]['referenced_table'], 'public.table1');
    $this->assertEqual($foreignKeys[0]['column'], 'id');
    $this->assertEqual($foreignKeys[0]['referenced_column'], 'id');

    $this->assertEqual($foreignKeys[1]['table'], 'public.table3');
    $this->assertEqual($foreignKeys[1]['referenced_table'], 'public.table1');
    $this->assertEqual($foreignKeys[1]['column'], 'id');
    $this->assertEqual($foreignKeys[1]['referenced_column'], 'id');
  }
}
