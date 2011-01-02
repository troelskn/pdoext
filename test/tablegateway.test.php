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

    $this->assertEqual($john->getArrayCopy(), array('id' => 42, 'name' => 'John'));
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
  function scopeWithNameLength($selection) {
    $selection->addColumn(pdoext_literal('*'));
    $selection->addColumn(pdoext_literal('length(name)'), 'name_length');
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
    $gateway->insert(array('name' => 'Anna'));
    $gateway->insert(array('name' => 'Betty'));
    $gateway->insert(array('name' => 'Charlotte'));
    $gateway->insert(array('name' => 'Donna'));
    $gateway->insert(array('name' => 'Elisabeth'));
    $gateway->insert(array('name' => 'Francesca'));
    $gateway->insert(array('name' => 'Gabriella'));
    $gateway->insert(array('name' => 'Hannah'));
    $gateway->insert(array('name' => 'Isabel'));
    $gateway->insert(array('name' => 'Jacqueline'));
    $gateway->insert(array('name' => 'Kimberley'));
    $gateway->insert(array('name' => 'Laila'));
    $gateway->insert(array('name' => 'Madeleine'));
    $gateway->insert(array('name' => 'Nancy'));

    $a = array();
    foreach ($gateway->select() as $row) {
      $a[] = $row;
    }
    $this->assertTrue($a[0] instanceOf StdClass);
    $this->assertEqual("Anna", $a[0]->name);
  }
  function test_can_select_paginated() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         name VARCHAR(255)
       )'
    );
    $gateway = new test_UsersGateway($connection);
    $gateway->insert(array('name' => 'Anna'));
    $gateway->insert(array('name' => 'Betty'));
    $gateway->insert(array('name' => 'Charlotte'));
    $gateway->insert(array('name' => 'Donna'));
    $gateway->insert(array('name' => 'Elisabeth'));
    $gateway->insert(array('name' => 'Francesca'));
    $gateway->insert(array('name' => 'Gabriella'));
    $gateway->insert(array('name' => 'Hannah'));
    $gateway->insert(array('name' => 'Isabel'));
    $gateway->insert(array('name' => 'Jacqueline'));
    $gateway->insert(array('name' => 'Kimberley'));
    $gateway->insert(array('name' => 'Laila'));
    $gateway->insert(array('name' => 'Madeleine'));
    $gateway->insert(array('name' => 'Nancy'));

    $q = $gateway->select()->paginate(2);
    $a = array();
    foreach ($q as $row) {
      $a[] = $row;
    }
    $this->assertTrue($a[0] instanceOf StdClass);
    $this->assertEqual("Kimberley", $a[0]->name);
    $this->assertEqual(14, $q->totalCount());
    $this->assertEqual(2, $q->totalPages());
  }
  function test_named_scopes_are_callable() {
    $connection = new pdoext_Connection("sqlite::memory:");
    $connection->exec(
      'CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         name VARCHAR(255)
       )'
    );
    $gateway = new test_UsersGateway($connection);
    $gateway->insert(array('name' => 'Betty'));
    $q = $gateway->withNameLength();
    $a = array();
    foreach ($q as $row) {
      $a[] = $row;
    }
    $this->assertTrue($a[0] instanceOf StdClass);
    $this->assertEqual("Betty", $a[0]->name);
    $this->assertEqual(5, $a[0]->name_length);
  }
}

class TestOfRecordRelations extends UnitTestCase {
  function setUp() {
    $this->connection = new pdoext_Connection("sqlite::memory:");
    $this->connection->exec(
      'CREATE TABLE artists(
         id    INTEGER PRIMARY KEY,
         name  TEXT
       )'
    );
    $this->connection->exec(
      'CREATE TABLE tracks(
         id     INTEGER PRIMARY KEY,
         name   TEXT,
         artist_id INTEGER,
         FOREIGN KEY(artist_id) REFERENCES artists(id)
       )'
    );
    $this->connection->exec('insert into artists values (1, "Bob Dylan")');
    $this->connection->exec('insert into tracks values (1, "Blowing in the wind", 1)');
    $this->connection->exec('insert into tracks values (2, "House of the rising sun", 1)');
    $GLOBALS['pdoext_connection']['instance'] = $this->connection;
  }
  function tearDown() {
    $GLOBALS['pdoext_connection']['instance'] = null;
  }
  function test_has_many() {
    $artist = $this->connection->artists->find(1);
    $tmp = array();
    foreach ($artist->tracks as $track) {
      $tmp[] = $track->name;
    }
    $this->assertEqual($tmp, array("Blowing in the wind", "House of the rising sun"));
  }
  function test_belongs_to() {
    $track = $this->connection->tracks->find(1);
    $this->assertEqual("Bob Dylan", $track->artist->name);
  }
}
