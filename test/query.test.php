<?php
require_once 'simpletest.inc.php';

require_once '../lib/pdoext/connection.php';
require_once '../lib/pdoext/tablegateway.php';
require_once '../lib/pdoext/query/icriterion.php';
require_once '../lib/pdoext/query/criterion.php';
require_once '../lib/pdoext/query/criteria.php';
require_once '../lib/pdoext/query/join.php';
require_once '../lib/pdoext/query.php';

class TestOfQuery extends UnitTestCase
{
  protected function getConnection() {
    return new pdoext_Connection("sqlite::memory:");
  }

  function assertSqlEqual($sqlA, $sqlB) {
    $pdo = new PDO("sqlite::memory:");
    $pdo->query("create table people ( first_name varchar(255), account_id integer )");
    $pdo->query("create table accounts ( account_id integer )");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $this->assertEqual(
      $pdo->query("EXPLAIN ".$sqlA)->fetchAll(PDO::FETCH_ASSOC),
      $pdo->query("EXPLAIN ".$sqlB)->fetchAll(PDO::FETCH_ASSOC));
  }

  function test_sql_equal_assertion() {
    $this->assertSqlEqual(
      "select * from people where first_name = 'John'",
      "select * from people where first_name = 'John'");
  }

  function test_select_where() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $q->addCriterion('first_name', "John");
    $this->assertSqlEqual($q->toSql(), "select * from `people` where `first_name` = 'John'");
  }

  function test_select_where_in_array() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $q->addCriterion('first_name', Array("John", "Jim"));
    $this->assertSqlEqual($q->toSql(), "select * from `people` where `first_name` in ('John', 'Jim')");
  }

  function test_select_where_not_null() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $q->addCriterion('first_name', NULL, "!=");
    $this->assertSqlEqual($q->toSql(), "select * from `people` where `first_name` is not null");
  }

  function test_select_left_join() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $j = $q->addJoin('accounts', 'LEFT JOIN');
    $j->addConstraint('people.account_id', 'accounts.account_id');
    $this->assertSqlEqual($q->toSql(), "select * from `people` left join accounts on `people`.`account_id` = `accounts`.`account_id`");
  }

  function test_select_specific_column() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $q->addColumn("first_name");
    $q->addCriterion('first_name', "John");
    $this->assertSqlEqual($q->toSql(), "select `first_name` from `people` where `first_name` = 'John'");
  }

  function test_select_specific_column_as_alias() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $q->addColumn("first_name as name", FALSE);
    $q->addCriterion('first_name', "John");
    $this->assertSqlEqual($q->toSql(), "select first_name as name from `people` where `first_name` = 'John'");
  }

  function test_select_where_composite_condition() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $sub = $q->addCriterion(new pdoext_query_Criteria("OR"));
    $sub->addCriterion('first_name', "John");
    $sub->addCriterion('first_name', "Jim");
    $this->assertSqlEqual($q->toSql(), "select * from `people` where (`first_name` = 'John' OR `first_name` = 'Jim')");
  }

  function test_quote_styles() {
    $db = $this->getConnection();
    $crit = new pdoext_query_Criterion('foo', 'bar', ' = ', pdoext_query_Criterion::QUOTE_NONE);
    $this->assertEqual($crit->toSQL($db), "foo = bar");
    $crit = new pdoext_query_Criterion('foo', 'bar', ' = ', pdoext_query_Criterion::QUOTE_VALUE);
    $this->assertEqual($crit->toSQL($db), "\"foo\" = 'bar'");
    $crit = new pdoext_query_Criterion('foo', 'bar', ' = ', pdoext_query_Criterion::QUOTE_FIELD);
    $this->assertEqual($crit->toSQL($db), "\"foo\" = \"bar\"");
    $crit = new pdoext_query_Criterion('foo', 'bar', ' = ', pdoext_query_Criterion::QUOTE_LITTERAL);
    $this->assertEqual($crit->toSQL($db), "\"foo\" = bar");
  }
}

simpletest_autorun(__FILE__);