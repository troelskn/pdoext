<?php
require_once 'simpletest.inc.php';

require_once '../lib/pdoext/iconnection.php';
require_once '../lib/pdoext/connection.php';
require_once '../lib/pdoext/tablegateway.php';
require_once '../lib/pdoext/query/icriterion.php';
require_once '../lib/pdoext/query/criterion.php';
require_once '../lib/pdoext/query/criteria.php';
require_once '../lib/pdoext/query/join.php';
require_once '../lib/pdoext/query.php';

class TestOfTableGatewayBasicUsecases extends UnitTestCase
{
  function test_insert_record() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER,
         name VARCHAR(255)
       )'
    );
    $gateway = new pdoext_TableGateway('users', $connection);
    $gateway->insert(Array('id' => 42, 'name' => 'John'));

    $result = $connection->pexecute("SELECT COUNT(*) FROM users WHERE id = '42'");
    $row = $result->fetch();
    $this->assertEqual($row[0], 1);

    $result = $connection->pexecute("SELECT * FROM users WHERE id = '42'");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $this->assertEqual($row, Array('id' => 42, 'name' => 'John'));
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
    $gateway->insert(Array('name' => 'John'));
    $gateway->insert(Array('name' => 'Jim'));
    $all_users = $connection->pexecute("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);

    $expected = Array(
      Array('id' => 1, 'name' => 'John'),
      Array('id' => 2, 'name' => 'Jim')
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
    $gateway->delete(Array('id' => 42));

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
    $gateway->update(Array('name' => 'Jim'), Array('id' => 42));

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
    $john = $gateway->fetch(Array('id' => 42));

    $this->assertEqual($john, Array('id' => 42, 'name' => 'John'));
  }
}

class TestOfTableGateway extends UnitTestCase
{
  function test_arrayobject_is_marshalled_to_hash() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER,
         name VARCHAR(255)
       )'
    );
    $john = new ArrayObject(Array('id' => 42, 'name' => 'John'));
    $gateway = new pdoext_TableGateway('users', $connection);
    $gateway->insert($john);

    $result = $connection->pexecute("SELECT * FROM users WHERE id = '42'");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $this->assertEqual($row, Array('id' => 42, 'name' => 'John'));
  }
}

simpletest_autorun(__FILE__);
