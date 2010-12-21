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

class TestOfQuery extends UnitTestCase {
  protected function getConnection() {
    return new pdoext_Connection("sqlite::memory:");
  }

  function assertSqlEqual($sqlA, $sqlB) {
    $message = "\n------\n[" . $sqlA . "]\n\n    differs from\n\n[" . $sqlB . "]\n------\n";
    $pdo = new PDO("sqlite::memory:");
    $pdo->query("create table people ( first_name varchar(255), account_id integer )");
    $pdo->query("create table accounts ( account_id integer )");
    $pdo->query("create table content ( con_type int, con_posttime datetime, con_refresh int, con_hits int )");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $a = $pdo->query("EXPLAIN ".$sqlA)->fetchAll(PDO::FETCH_ASSOC);
    $b = $pdo->query("EXPLAIN ".$sqlB)->fetchAll(PDO::FETCH_ASSOC);
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
      pdoext_literal(array(':first_name_0', ':first_name_1', ':first_name_2')));
    $this->assertEqual($crit->toSQL($db), "\"first_name\" IN (  :first_name_0, :first_name_1, :first_name_2)");
  }

  function test_quote_styles() {
    $db = $this->getConnection();
    $crit = new pdoext_query_Criterion(pdoext_literal('foo'), pdoext_literal('bar'));
    $this->assertEqual($crit->toSQL($db), "foo = bar");
    $crit = new pdoext_query_Criterion(pdoext_field('foo'),pdoext_value('bar'));
    $this->assertEqual($crit->toSQL($db), "\"foo\" = 'bar'");
    $crit = new pdoext_query_Criterion(pdoext_field('foo'), pdoext_field('bar'));
    $this->assertEqual($crit->toSQL($db), "\"foo\" = \"bar\"");
    $crit = new pdoext_query_Criterion(pdoext_field('foo'), pdoext_literal('bar'));
    $this->assertEqual($crit->toSQL($db), "\"foo\" = bar");
  }

  function test_select_where() {
    $db = $this->getConnection();
    $q = pdoext_query('people');
    $q->addCriterion('first_name', "John");
    $this->assertSqlEqual($q->toSql($db), "select * from `people` where `first_name` = 'John'");
  }

  function test_select_where_in_array() {
    $db = $this->getConnection();
    $q = new pdoext_Query('people');
    $q->addCriterion('first_name', array("John", "Jim"));
    $this->assertSqlEqual($q->toSql($db), "select * from `people` where `first_name` in ('John', 'Jim')");
  }

  function test_select_where_not_null() {
    $db = $this->getConnection();
    $q = new pdoext_Query('people');
    $q->addCriterion('first_name', null, "!=");
    $this->assertSqlEqual($q->toSql($db), "select * from `people` where `first_name` is not null");
  }

  function test_select_left_join() {
    $db = $this->getConnection();
    $q = new pdoext_Query('people');
    $j = $q->addJoin('accounts', 'LEFT JOIN');
    $j->addConstraint('people.account_id', 'accounts.account_id');
    $this->assertSqlEqual($q->toSql($db), "select * from `people` left join accounts on `people`.`account_id` = `accounts`.`account_id`");
  }

  function test_select_specific_column() {
    $db = $this->getConnection();
    $q = new pdoext_Query('people');
    $q->addColumn("first_name");
    $q->addCriterion('first_name', "John");
    $this->assertSqlEqual($q->toSql($db), "select `first_name` from `people` where `first_name` = 'John'");
  }

  function test_select_specific_column_as_alias() {
    $db = $this->getConnection();
    $q = new pdoext_Query('people');
    $q->addColumn("first_name", "name");
    $q->addCriterion('first_name', "John");
    $this->assertSqlEqual($q->toSql($db), "select first_name as name from `people` where `first_name` = 'John'");
  }

  function test_select_where_composite_condition() {
    $db = $this->getConnection();
    $q = new pdoext_Query('people');
    $sub = new pdoext_query_Criteria("OR");
    $q->addCriterion($sub);
    $sub->addCriterion('first_name', "John");
    $sub->addCriterion('first_name', "Jim");
    $this->assertSqlEqual($q->toSql($db), "select * from `people` where (`first_name` = 'John' OR `first_name` = 'Jim')");
  }

  function test_select_group_by() {
    $db = $this->getConnection();
    $q = pdoext_query('people');
    $q->addGroupBy('first_name');
    $this->assertSqlEqual($q->toSql($db), "select * from `people` group by `first_name`");
  }

  function test_select_group_by_with_having() {
    $db = $this->getConnection();
    $q = pdoext_query('people');
    $q->addGroupBy('first_name');
    $q->setHaving('first_name', 'John');
    $this->assertSqlEqual($q->toSql($db), "select * from `people` group by `first_name` having `first_name` = 'John'");
  }

  function test_select_union() {
    $db = $this->getConnection();
    $q = pdoext_query('people');
    $q->addUnion(pdoext_query('people'));
    $this->assertSqlEqual($q->toSql($db), "select * from `people` union select * from `people`");
  }

  function test_select_union_all() {
    $db = $this->getConnection();
    $q = pdoext_query('people');
    $q->addUnionAll(pdoext_query('people'));
    $this->assertSqlEqual($q->toSql($db), "select * from `people` union all select * from `people`");
  }

  function test_select_complex_query() {
    $db = $this->getConnection();
    $q = new pdoext_Query('people');
    $q->addColumn("first_name");
    $q->setLimit(10);
    $q->setOffset(10);
    $j = $q->addJoin('accounts', 'LEFT JOIN');
    $sub = $j->addCriterion(new pdoext_query_Criteria("OR"));
    $sub->addConstraint('people.account_id', 'accounts.account_id');
    $sub->addCriterion('people.account_id', 28, '>');
    $q->addCriterion('first_name', "John");

    $this->assertSqlEqual($q->toSql($db), "
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
    $q = new pdoext_Query('people');
    $q->addCriterion('first_name', array("John", "Jim"));
    $this->assertSqlEqual($q->toSql($db), $q->toSql($db));
  }

  function test_select_with_subselect_in_where_clause() {
    $db = $this->getConnection();
    $query = pdoext_query("people");
    $query->addCriterion(pdoext_field("first_name"), pdoext_value("John"));
    $sub = pdoext_query("accounts");
    $sub->addCriterion(pdoext_field("first_name"), pdoext_value("John"));
    $query->addCriterion(pdoext_literal("account_id"), $sub);
    $this->assertSqlEqual($query->toSql($db), "
SELECT *
FROM `people`
WHERE
  `first_name` = 'John'
  OR account_id IN (
    SELECT *
    FROM `accounts`
    WHERE
      `first_name` = 'John')
");
  }

  function test_select_with_subselect_in_from_clause() {
    $db = $this->getConnection();
    $from = pdoext_query("content");
    $from->addColumn('con_hits');
    $from->setConjunctionAnd();
    $from->addCriterion('con_type', 1);
    $from->addCriterion('con_posttime', pdoext_literal('date_add(current_date(), interval -12 hour)'), '<');
    $from->addCriterion('con_refresh', 0);
    $from->setOrder('con_posttime', 'DESC');
    $from->setLimit(100);
    $query = pdoext_query($from, 'x');
    $query->addColumn(pdoext_literal('avg(con_hits)'), 'avg_hits');
    // NOTE: Can't use assertSqlEqual here, since sqlite doesn't support the syntax
    $this->assertEqual($query->toSql($db), 'SELECT avg(con_hits) AS "avg_hits"
FROM (
  SELECT "con_hits"
  FROM "content"
  WHERE
    "con_type" = \'1\'
    AND "con_posttime" < date_add(current_date(), interval -12 hour)
    AND "con_refresh" = \'0\'
  ORDER BY "con_posttime" DESC
  LIMIT 100) AS "x"');
  }
}
