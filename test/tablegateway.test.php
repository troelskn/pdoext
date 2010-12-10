<?php
error_reporting(E_ALL | E_STRICT);
require_once 'simpletest/unit_tester.php';
if (realpath($_SERVER['PHP_SELF']) == __FILE__) {
  require_once 'simpletest/autorun.php';
}
set_include_path(
  get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/../lib/');

require_once 'pdoext.inc.php';
require_once 'pdoext/connection.inc.php';
require_once 'pdoext/query.inc.php';
require_once 'pdoext/tablegateway.inc.php';

class TestOfTableGatewayBasicUsecases extends UnitTestCase {
  function test_insert_record() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER,
         name VARCHAR(255)
       )'
    );
    $gateway = new pdoext_TableGateway('users', $connection);
    $gateway->insert(array('id' => 42, 'name' => 'John'));

    $result = $connection->pexecute("SELECT COUNT(*) FROM users WHERE id = '42'");
    $row = $result->fetch();
    $this->assertEqual($row[0], 1);

    $result = $connection->pexecute("SELECT * FROM users WHERE id = '42'");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $this->assertEqual($row, array('id' => 42, 'name' => 'John'));
  }

  function test_insert_record_using_sequence_pkeys() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         name VARCHAR(255)
       )'
    );
    $gateway = new pdoext_TableGateway('users', $connection);
    $gateway->insert(array('name' => 'John'));
    $gateway->insert(array('name' => 'Jim'));
    $all_users = $connection->pexecute("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);

    $expected = array(
      array('id' => 1, 'name' => 'John'),
      array('id' => 2, 'name' => 'Jim')
    );
    $this->assertEqual($all_users, $expected);
  }

  function test_delete_record() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER,
         name VARCHAR(255)
       )'
    );
    $connection->exec("INSERT INTO users VALUES (42, 'John')");
    $gateway = new pdoext_TableGateway('users', $connection);
    $gateway->delete(array('id' => 42));

    $result = $connection->pexecute("SELECT COUNT(*) FROM users WHERE name = 'John'");
    $row = $result->fetch();
    $this->assertEqual($row[0], 0);
  }

  function test_update_record() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER,
         name VARCHAR(255)
       )'
    );
    $connection->exec("INSERT INTO users VALUES (42, 'John')");
    $gateway = new pdoext_TableGateway('users', $connection);
    $gateway->update(array('name' => 'Jim'), array('id' => 42));

    $result = $connection->pexecute("SELECT COUNT(*) FROM users WHERE name = 'John'");
    $row = $result->fetch();
    $this->assertEqual($row[0], 0);

    $result = $connection->pexecute("SELECT COUNT(*) FROM users WHERE name = 'Jim'");
    $row = $result->fetch();
    $this->assertEqual($row[0], 1);
  }

  function test_fetch_record() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER,
         name VARCHAR(255)
       )'
    );
    $connection->exec("INSERT INTO users VALUES (42, 'John')");
    $gateway = new pdoext_TableGateway('users', $connection);
    $john = $gateway->fetch(array('id' => 42));

    $this->assertEqual($john, array('id' => 42, 'name' => 'John'));
  }
}

class test_UsersGateway extends pdoext_TableGateway {
  function __construct($connection) {
    parent::__construct('users', $connection);
  }
  function load($row) {
    $entity = new StdClass();
    foreach ($row as $key => $value) {
      $entity->$key = $value;
    }
    return $entity;
  }
}

class TestOfTableGateway extends UnitTestCase {
  function test_arrayobject_is_marshalled_to_hash() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER,
         name VARCHAR(255)
       )'
    );
    $john = new ArrayObject(array('id' => 42, 'name' => 'John'));
    $gateway = new pdoext_TableGateway('users', $connection);
    $gateway->insert($john);

    $result = $connection->pexecute("SELECT * FROM users WHERE id = '42'");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $this->assertEqual($row, array('id' => 42, 'name' => 'John'));
  }
  function test_can_select() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         name VARCHAR(255)
       )'
    );
    $gateway = new test_UsersGateway($connection);
    $gateway->insert(array('name' => 'John'));
    $gateway->insert(array('name' => 'Jim'));

    $a = array();
    foreach ($gateway->select() as $row) {
      $a[] = $row;
    }
    $this->assertTrue($a[0] instanceOf StdClass);
    $this->assertEqual("John", $a[0]->name);
  }
}
