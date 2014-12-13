<?php
error_reporting(E_ALL | E_STRICT);
require_once 'simpletest/unit_tester.php';
if (realpath($_SERVER['PHP_SELF']) == __FILE__) {
  require_once 'simpletest/autorun.php';
}
set_include_path(
  dirname(__FILE__) . '/../lib/' . PATH_SEPARATOR . get_include_path());

require_once 'pdoext.inc.php';
require_once 'pdoext/connection.inc.php';
require_once 'pdoext/query.inc.php';

class TestOfNestedTransactions extends UnitTestCase {
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
