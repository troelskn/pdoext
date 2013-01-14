<?php
/**
 * Generic interface for all query-like objects.
 */
interface pdoext_query_iExpression {
  /**
   * Compiles the object into a string representation, using a `pdoext_Connection`
   */
  function toSql($db = null);
}

/**
 * Represents a field (column) in a query.
 */
class pdoext_query_Field implements pdoext_query_iExpression {
  protected $name;
  protected $tablename;
  protected $columnname;
  function __construct($name) {
    $this->name = $name;
    if (preg_match('/^(.+)\.(.+)$/', $name, $reg)) {
      $this->tablename = $reg[1];
      $this->columnname = $reg[2];
    } else {
      $this->columnname = $name;
    }
  }
  function getTablename() {
    return $this->tablename;
  }
  function getColumnname() {
    return $this->columnname;
  }
  function toSql($db = null) {
    if (!$db) {
      $db = new pdoext_DummyConnection();
    }
    if ($this->columnname == '*') {
      if ($this->tablename) {
        return $db->quoteName($this->tablename).'.'.$this->columnname;
      }
      return $this->columnname;
    }
    return $db->quoteName($this->name);
  }
}

/**
 * Represents a value in a query.
 */
class pdoext_query_Value implements pdoext_query_iExpression {
  protected $value;
  function __construct($value) {
    $this->value = $value;
  }
  function isNull() {
    return is_null($this->value);
  }
  function isMany() {
    return is_array($this->value);
  }
  function toSql($db = null) {
    if (is_null($this->value)) {
      return 'NULL';
    }
    if (!$db) {
      $db = new pdoext_DummyConnection();
    }
    if (is_array($this->value)) {
      $a = array();
      foreach ($this->value as $value) {
        $a[] = $db->quote($value);
      }
      return implode(', ', $a);
    }
    return $db->quote($this->value);
  }
}

/**
 * Represent an SQL literal.
 */
class pdoext_query_Literal implements pdoext_query_iExpression {
  protected $sql;
  function __construct($sql) {
    $this->sql = $sql;
  }
  function isMany() {
    return is_array($this->sql);
  }
  function toSql($db = null) {
    return is_array($this->sql) ? implode(', ', $this->sql) : $this->sql;
  }
}

/**
 * A parameterised criterion has placeholders for values, that gets bound on execution.
 */
class pdoext_ParameterisedCriteron implements pdoext_query_iCriteron {
  protected $sql;
  protected $parameters;
  function __construct($sql, $parameters = array()) {
    $this->sql = $sql;
    $this->parameters = array();
    foreach ($parameters as $param) {
      if (is_object($param)) {
        $this->parameters[] = $param;
      } else {
        $this->parameters[] = new pdoext_query_Value($param);
      }
    }
  }
  function toSql($db = null) {
    if (!$db) {
      $db = new pdoext_DummyConnection();
    }
    $this->_params = $this->parameters;
    $this->_db = $db;
    $result = preg_replace_callback('/[?]/', array($this, '__callback'), $this->sql);
    $this->_params = null;
    $this->_db = null;
    if (count($this->_params) !== 0) {
      throw new Exception("Too many parameters");
    }
    return $result;
  }
  function __callback() {
    if (count($this->_params) === 0) {
      throw new Exception("Too few parameters");
    }
    $param = array_shift($this->_params);
    return $param->toSql($this->_db);
  }
}

interface pdoext_query_iCriteron {}

/**
 * Multiple criterion, strung together with a conjuntion (OR or AND).
 */
class pdoext_query_Criteria implements pdoext_query_iCriteron {
  protected $conjunction;
  protected $criteria = array();
  function __construct($conjunction = 'OR') {
    $this->conjunction = $conjunction;
  }
  function addCriterion($left, $right = null, $comparator = '=') {
    return $this->addCriterionObject($left instanceof pdoext_query_iCriteron ? $left : new pdoext_query_Criterion($left, $right, $comparator));
  }
  function addConstraint($left, $right, $comparator = '=') {
    return $this->addCriterionObject(new pdoext_query_Criterion(new pdoext_query_Field($left), new pdoext_query_Field($right), $comparator));
  }
  function addCriterionObject(pdoext_query_iCriteron $criterion) {
    $this->criteria[] = $criterion;
    return $criterion;
  }
  /**
   * Removes criteria that looks similar to the given criterion.
   */
  function removeCriterion($left, $right = null, $comparator = '=') {
    return $this->removeCriterionObject($left instanceof pdoext_query_iCriteron ? $left : new pdoext_query_Criterion($left, $right, $comparator));
  }
  function removeCriterionObject(pdoext_query_iCriteron $criterion) {
    $conn = new pdoext_DummyConnection();
    $tmp = array();
    foreach ($this->criteria as $crit) {
      if (!$crit->toSql($conn) == $criterion->toSql($conn)) {
	$tmp[] = $crit;
      }
    }
    $this->criteria = $tmp;
  }
  /**
   * Adds a condition to the WHERE part of the query.
   * If you pass a string with one or more placeholders (`?`-marks), a bound parameterised expression is assumed, where remaining arguments will be bound by position. See `pdoext_ParameterisedCriteron`.
   * Otherwise, a plain comparison is assumed, as per `addCriterion`
   * @returns self
   */
  function where($left, $right = null, $comparator = '=') {
    if (is_string($left) && strpos($left, '?') !== false) {
      // it's a parameterised criterion
      $get_func_args = func_get_args();
      $sql = array_shift($get_func_args);
      $this->addCriterionObject(new pdoext_ParameterisedCriteron($sql, $get_func_args));
    } else {
      // it's a an expression
      $this->addCriterion($left, $right, $comparator);
    }
    return $this;
  }
  function setConjunctionAnd() {
    $this->conjunction = 'AND';
  }
  function setConjunctionOr() {
    $this->conjunction = 'OR';
  }
  function toSql($db = null) {
    if (count($this->criteria) === 0) {
      return '';
    }
    if (!$db) {
      $db = new pdoext_DummyConnection();
    }
    $criteria = array();
    foreach ($this->criteria as $criterion) {
      $is_many = method_exists($criterion, 'isMany') && $criterion->isMany();
      if ($is_many) {
        $criteria[] = "(" . $criterion->toSQL($db) . ")";
      } else {
        $criteria[] = $criterion->toSQL($db);
      }
    }
    return implode("\n" . $this->conjunction . ' ', $criteria);
  }
  function isMany() {
    return count($this->criteria) > 1;
  }
}

/**
 * A single criterion. The main building block of queries.
 */
class pdoext_query_Criterion implements pdoext_query_iCriteron {
  protected $left;
  protected $right;
  protected $comparator;
  function __construct($left, $right, $comparator = '=') {
    $this->left = $left instanceof pdoext_query_iExpression ? $left : new pdoext_query_Field($left);
    $this->right = $right instanceof pdoext_query_iExpression ? $right : new pdoext_query_Value($right);
    $this->comparator = trim($comparator);
  }
  function toSql($db = null) {
    if (!$db) {
      $db = new pdoext_DummyConnection();
    }
    $is_null = method_exists($this->right, 'isNull') && $this->right->isNull();
    $is_many = method_exists($this->right, 'isMany') && $this->right->isMany();
    if ($is_null) {
      if ($this->comparator === '=') {
        return $this->left->toSql($db) . ' IS NULL';
      } elseif ($this->comparator === '!=') {
        return $this->left->toSql($db) . ' IS NOT NULL';
      }
    } elseif ($is_many) {
      if ($this->comparator === '=' || $this->comparator === '!=') {
        $right = pdoext_string_indent($this->right->toSql($db), true);
        if ($this->comparator === '=') {
          return $this->left->toSql($db) . ' IN (' . $right . ')';
        }
        return $this->left->toSql($db) . ' NOT IN (' . $right . ')';
      }
    }
    return $this->left->toSql($db) . ' ' . $this->comparator . ' ' . $this->right->toSql($db);
  }
}

/**
 * Represents a join on a query.
 */
class pdoext_query_Join extends pdoext_query_Criteria {
  protected $type;
  protected $table;
  protected $alias;
  protected $force_index;
  public function __construct($table, $type = 'JOIN', $alias = null) {
    parent::__construct('AND');
    $this->table = $table; // @TODO Can a query be added as the join target?
    $this->type = strtoupper(trim($type))." ";
    $this->alias = $alias;
  }
  function forceIndex($index_name) {
    $this->force_index = $index_name;
  }
  function toSql($db = null) {
    if (!$db) {
      $db = new pdoext_DummyConnection();
    }
    if ($this->force_index) {
      $indexes = "\nFORCE INDEX (" . $db->quoteName($this->force_index) . ")\n";
    } else {
      $indexes = "";
    }
    if (count($this->criteria) > 0) {
      $on = "\nON\n" . pdoext_string_indent(parent::toSql($db));
    } else {
      $on = "";
    }
    $joinTarget = $this->table instanceof pdoext_query_iExpression ? ("(" . $this->table->toSql($db) . ")") : $db->quoteName($this->table);
    if ($this->alias) {
      return $this->type . $joinTarget . " AS " . $db->quoteName($this->alias) . $indexes . $on;
    }
    return $this->type . $joinTarget . $indexes . $on;
  }
}

/**
 * Represents a full *select* query.
 */
class pdoext_Query extends pdoext_query_Criteria implements pdoext_query_iExpression {
  protected $tablename;
  protected $alias;
  protected $columns = array();
  protected $joins = array();
  protected $unions = array();
  protected $order = array();
  protected $limit = null;
  protected $offset = null;
  protected $groupby = array();
  protected $having = null;
  protected $sql_calc_found_rows = false;
  protected $straight_join = false;

  function __construct($tablename, $alias = null) {
    parent::__construct('AND');
    $this->tablename = $tablename;
    $this->alias = $alias;
  }
  function addJoin($mixed /* string or instance of pdoext_query_Join */, $type = 'JOIN', $alias = null) {
    if ($mixed instanceOf pdoext_query_Join) {
      return $this->addJoinObject($mixed);
    } else {
      return $this->addJoinObject(new pdoext_query_Join($mixed, $type, $alias));
    }
  }
  function addJoinObject(pdoext_query_Join $join) {
    $this->joins[] = $join;
    return $join;
  }
  /**
   * @tip
   *   Some times, WHERE clauses with OR can be optimised by creating two queries and UNION them together.
   *   See:
   *   http://www.techfounder.net/2008/10/15/optimizing-or-union-operations-in-mysql/
   *
   * @tip
   *   Generally, `UNION ALL` outperforms `UNION`
   *   See:
   *   http://www.mysqlperformanceblog.com/2007/10/05/union-vs-union-all-performance/
   */
  public function addUnion($mixed, $alias = null, $type = 'DISTINCT') {
    $union = $mixed instanceOf pdoext_Query ? $mixed : new pdoext_Query($mixed, $alias);
    $this->unions[] = array($union, $type);
    return $union;
  }
  public function addUnionDistinct($mixed, $alias = null) {
    return $this->addUnion($mixed, $alias, 'DISTINCT');
  }
  public function addUnionAll($mixed, $alias = null) {
    return $this->addUnion($mixed, $alias, 'ALL');
  }
  function addGroupBy($column) {
    $groupby = $column instanceof pdoext_query_iExpression ? $column : new pdoext_query_Field($column);
    $this->groupby[] = $groupby;
    return $groupby;
  }
  function setHaving($left, $right = null, $comparator = '=') {
    return $this->having = $left instanceof pdoext_query_iExpression ? $left : new pdoext_query_Criterion($left, $right, $comparator);
  }
  /**
   * Will select tablename.*, which is more conservative than the default (*) when no columns are specified.
   */
  function selectTableColumns() {
    if (count($this->columns) === 0) {
      $this->columns[] = array(new pdoext_query_Field($this->tablename.'.*'), null);
    }
  }
  function addColumn($column, $alias = null) {
    $this->columns[] = array(
      $column instanceof pdoext_query_iExpression ? $column : new pdoext_query_Field($column),
      $alias);
  }
  function setOrder($order, $direction = null) {
    if ($order != "") {
      $this->addOrder($order, $direction);
    }
  }
  function addOrder($order, $direction = null) {
    $this->order[] = array(
      $order instanceof pdoext_query_iExpression ? $order : new pdoext_query_Field($order),
      in_array(strtoupper($direction), array('ASC', 'DESC')) ? $direction : null);
  }
  function setLimit($limit) {
    $this->limit = (int) $limit;
  }
  function setOffset($offset) {
    $this->offset = (int) $offset;
  }
  /**
   * @tip
   *   In most cases, a `select count(*)` query is faster, since it will use the index.
   *   However, if the query would cause a table scan, `sql_calc_found_rows` might perform better.
   *   See:
   *   http://www.mysqlperformanceblog.com/2007/08/28/to-sql_calc_found_rows-or-not-to-sql_calc_found_rows/
   */
  public function setSqlCalcFoundRows($value = true) {
    $this->sql_calc_found_rows = $value;
  }
  /**
   * @tip
   *   When `straight_join` is set, MySql will execute joins in the order they are defined.
   *   See:
   *   http://www.daylate.com/2004/05/mysql-straight_join/
   */
  public function setStraightJoin($value = true) {
    $this->straight_join = $value;
  }
  function toSql($db = null) {
    if (!$db) {
      $db = new pdoext_DummyConnection();
    }
    $sql = 'SELECT';
    if ($this->sql_calc_found_rows && $db->supportsSqlCalcFoundRows()) {
      $sql .= ' SQL_CALC_FOUND_ROWS';
    }
    if ($this->straight_join) {
      $sql .= ' STRAIGHT_JOIN';
    }
    $alias = $this->alias;
    if (count($this->columns) === 0) {
      $columns = ' *';
    } else {
      $columns = array();
      foreach ($this->columns as $column) {
        $columns[] = $column[0]->toSql($db) . ($column[1] ? (' AS ' . $db->quoteName($column[1])) : '');
      }
      $columns = (count($columns) === 1 ? ' ' : "\n") . implode(",\n", $columns);
    }
    if ($this->tablename instanceof pdoext_Query) {
      $sql .= sprintf("%s\nFROM (%s)", $columns, pdoext_string_indent($this->tablename->toSql($db), true));
      if (!$alias) {
        $alias = 'from_sub';
      }
    } else {
      $sql .= sprintf("%s\nFROM %s", $columns, $db->quoteName($this->tablename));
    }
    if ($alias) {
      $sql.= ' AS ' . $db->quoteName($alias);
    }
    foreach ($this->joins as $join) {
      $sql .= "\n" . $join->toSQL($db);
    }
    if (count($this->criteria) > 0) {
      $sql = $sql . "\nWHERE\n" . pdoext_string_indent(parent::toSql($db));
    }
    if (count($this->groupby) > 0) {
      $tmp = array();
      foreach ($this->groupby as $groupby) {
        $tmp[] = $groupby->toSql($db);
      }
      $sql .= "\nGROUP BY\n" . pdoext_string_indent(implode(",\n", $tmp));
    }
    if ($this->having) {
      $sql .= "\nHAVING\n" . pdoext_string_indent($this->having->toSql($db));
    }
    foreach ($this->unions as $union) {
      $sql .= "\nUNION " . ($union[1] === 'DISTINCT' ? '' : $union[1]) . "\n" . $union[0]->toSQL($db);
    }
    if (count($this->order) > 0) {
      $order = array();
      foreach ($this->order as $column) {
        $order[] = $column[0]->toSql($db) . ($column[1] ? (' ' . $column[1]) : '');
      }
      $sql .= "\nORDER BY" . (count($order) === 1 ? ' ' : "\n") . implode(",\n", $order);
    }
    if ($this->limit) {
      $sql .= "\nLIMIT " . $this->limit;
    }
    if ($this->offset) {
      $sql .= "\nOFFSET " . $this->offset;
    }
    return $sql;
  }
  function isMany() {
    return true;
  }
}
