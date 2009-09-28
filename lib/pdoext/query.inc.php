<?php
interface pdoext_query_iExpression {
  function toSql($db);
}

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
    $this->tablename;
  }
  function getColumnname() {
    $this->columnname;
  }
  function toSql($db) {
    return $db->quoteName($this->name);
  }
}

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
  function toSql($db) {
    if (is_null($this->value)) {
      return 'NULL';
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

class pdoext_query_Literal implements pdoext_query_iExpression {
  protected $sql;
  function __construct($sql) {
    $this->sql = $sql;
  }
  function isMany() {
    return is_array($this->sql);
  }
  function toSql($db) {
    return is_array($this->sql) ? implode(', ', $this->sql) : $this->sql;
  }
}

interface pdoext_query_iCriteron {}

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
  function setConjunctionAnd() {
    $this->conjunction = 'AND';
  }
  function setConjunctionOr() {
    $this->conjunction = 'OR';
  }
  function toSql($db) {
    if (count($this->criteria) === 0) {
      return '';
    }
    $criteria = array();
    foreach ($this->criteria as $criterion) {
      $criteria[] = $criterion->toSQL($db);
    }
    return implode("\n" . $this->conjunction . ' ', $criteria);
  }
}

class pdoext_query_Criterion implements pdoext_query_iCriteron {
  protected $left;
  protected $right;
  protected $comparator;
  function __construct($left, $right, $comparator = '=') {
    $this->left = $left instanceof pdoext_query_iExpression ? $left : new pdoext_query_Field($left);
    $this->right = $right instanceof pdoext_query_iExpression ? $right : new pdoext_query_Value($right);
    $this->comparator = trim($comparator);
  }
  function toSql($db) {
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

class pdoext_query_Join extends pdoext_query_Criteria {
  protected $type;
  protected $table;
  protected $alias;
  public function __construct($table, $type = 'JOIN', $alias = null) {
    parent::__construct('AND');
    $this->table = $table; // TODO: Can a query be added as the join target?
    $this->type = " ".trim($type)." ";
    $this->alias = $alias;
  }
  function toSql($db) {
    if (count($this->criteria) > 0) {
      $on = "\nON\n" . pdoext_string_indent(parent::toSql($db));
    } else {
      $on = "";
    }
    if ($this->alias) {
      return $this->type . $db->quoteName($this->table) . " AS " . $db->quoteName($this->alias) . $on;
    }
    return $this->type . $db->quoteName($this->table) . $on;
  }
}

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
    parent::__construct();
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
   *   Some times, WHERE clauses with OR can be optimised, by creating two queries and UNION them together.
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
    return $this->having = $left instanceof pdoext_query_iCriteron ? $left : new pdoext_query_Criterion($left, $right, $comparator);
  }
  function addColumn($column, $alias = null) {
    $this->columns[] = array(
      $column instanceof pdoext_query_iExpression ? $column : new pdoext_query_Field($column),
      $alias);
  }
  function setOrder($order, $direction = null) {
    $this->order = array();
    if ($order != "") {
      $this->addOrder($order, $direction);
    }
  }
  function addOrder($order, $direction = null) {
    $this->order[] = array(
      $order instanceof pdoext_query_iExpression ? $order : new pdoext_query_Field($order),
      in_array($direction, array('ASC', 'DESC')) ? $direction : null);
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
  function toSql($db) {
    $sql = 'SELECT';
    if ($this->sql_calc_found_rows) {
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
      if ($this->having) {
        $sql .= "\nHAVING\n" . pdoext_string_indent($this->having->toSql($db));
      }
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