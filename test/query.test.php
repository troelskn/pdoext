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
    $a = $pdo->query("EXPLAIN ".$sqlA)->fetchAll(PDO::FETCH_ASSOC);
    $b = $pdo->query("EXPLAIN ".$sqlB)->fetchAll(PDO::FETCH_ASSOC);
    $message = "\n------\n" . $a[0]['p4'] . "\n\n    differs from\n\n" . $b[0]['p4'] . "\n------\n";
    $a[0]['opcode'] == 'Trace' && $a[0]['p4'] = null;
    $b[0]['opcode'] == 'Trace' && $b[0]['p4'] = null;
    return $this->assertEqual($a, $b, $message);
  }

  function test_sql_equal_assertion() {
    $this->assertSqlEqual(
      "SELECT * FROM people WHERE first_name = 'John'",
      "select * from people where first_name = 'John'");
  }

  function test_literal_quote_array_of_symbols() {
    $db = $this->getConnection();
    $crit = new pdoext_query_Criterion(
      'first_name',
      array(':first_name_0', ':first_name_1', ':first_name_2'),
      '=',
      pdoext_query_Criterion::QUOTE_LITERAL);
    $this->assertEqual($crit->toSQL($db), "\"first_name\" IN (:first_name_0,:first_name_1,:first_name_2)");
  }

  function test_quote_styles() {
    $db = $this->getConnection();
    $crit = new pdoext_query_Criterion('foo', 'bar', ' = ', pdoext_query_Criterion::QUOTE_NONE);
    $this->assertEqual($crit->toSQL($db), "foo = bar");
    $crit = new pdoext_query_Criterion('foo', 'bar', ' = ', pdoext_query_Criterion::QUOTE_VALUE);
    $this->assertEqual($crit->toSQL($db), "\"foo\" = 'bar'");
    $crit = new pdoext_query_Criterion('foo', 'bar', ' = ', pdoext_query_Criterion::QUOTE_FIELD);
    $this->assertEqual($crit->toSQL($db), "\"foo\" = \"bar\"");
    $crit = new pdoext_query_Criterion('foo', 'bar', ' = ', pdoext_query_Criterion::QUOTE_LITERAL);
    $this->assertEqual($crit->toSQL($db), "\"foo\" = bar");
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
    $q->addCriterion('first_name', array("John", "Jim"));
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

  function test_select_complex_query() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $q->addColumn("first_name");
    $q->setLimit(10);
    $q->setOffset(10);
    $j = $q->addJoin('accounts', 'LEFT JOIN');
    $sub = $j->addCriterion(new pdoext_query_Criteria("OR"));
    $sub->addConstraint('people.account_id', 'accounts.account_id');
    $sub->addCriterion('people.account_id', 28, '>');
    $q->addCriterion('first_name', "John");

    $this->assertSqlEqual($q->toSql(), "
select `first_name`
from `people`
left join `accounts`
on `people`.`account_id` = `accounts`.`account_id` or `people`.`account_id` > '28'
where `first_name` = 'John'
limit 10
offset 10
");
  }

  function test_select_where_in_array_has_no_side_effects() {
    $db = $this->getConnection();
    $q = new pdoext_Query($db, 'people');
    $q->addCriterion('first_name', array("John", "Jim"));
    $this->assertSqlEqual($q->toSql(), $q->toSql());
  }

}

simpletest_autorun(__FILE__);