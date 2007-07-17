<?php
class pdoext_Query extends pdoext_query_Criteria
{
  protected $connection;

  protected $table;
  protected $columns = Array();
  protected $joins = Array();
  protected $sql_calc_found_rows = FALSE;

  protected $order = null;
  protected $direction = null;
  protected $limit = null;
  protected $offset = null;

  protected $groupby = Array();

  public function __construct(pdoext_Connection $connection, $table, $alias = NULL, $quote_name = TRUE, $sql_calc_found_rows = FALSE) {
    parent::__construct('AND');
    $this->connection = $connection;
    if (is_null($alias)) {
      if ($quote_name) {
        $this->table = $this->connection->quoteName($table);
      } else {
        $this->table = $table;
      }
    } else {
      if ($quote_name) {
        $this->table = $this->connection->quoteName($table) . " AS " . $this->connection->quoteName($alias);
      } else {
        $this->table = $table . " AS " . $alias;
      }
    }
    $this->setSqlCalcFoundRows($sql_calc_found_rows);
  }

  public function addColumn($column, $quote = true) {
    if ($quote) {
      $this->columns[] = $this->connection->quoteName($column);
    } else {
      $this->columns[] = $column;
    }
  }

  public function addGroupBy($column, $quote = true) {
    if ($quote) {
      $this->groupby[] = $this->connection->quoteName($column);
    } else {
      $this->groupby[] = $column;
    }
  }

  protected function addJoinObject(pdoext_query_Join $join) {
    $this->joins[] = $join;
    return $join;
  }

  public function addJoin($mixed /* string or instance of pdoext_query_Join */, $type = 'JOIN', $alias = NULL) {
    if ($mixed instanceOf pdoext_query_Join) {
      return $this->addJoinObject($mixed);
    } else {
      return $this->addJoinObject(new pdoext_query_Join($mixed, $type, $alias));
    }
  }

  public function setOrder($order, $quote = true) {
    if ($order == "") {
      $this->order = NULL;
    } else if ($quote) {
      $this->order = $this->connection->quoteName($order);
    } else {
      $this->order = $order;
    }
  }

  public function setDirection($direction) {
    $this->direction = strtoupper($direction);
  }

  public function setLimit($limit) {
    $this->limit = $limit;
  }

  public function setOffset($offset) {
    $this->offset = $offset;
  }

  public function setSqlCalcFoundRows($value) {
    $this->sql_calc_found_rows = $value;
  }

  public function fetchAll($fetchMode = PDO::FETCH_ASSOC) {
    $result = $this->connection->query($this->toSQL());
    return $result->fetchAll($fetchMode);
  }

  public function fetchResult() {
    $result = $this->connection->query($this->toSQL());
    return $result->fetchColumn(0);
  }

  public function toSQL() {
    $sql = "SELECT";
    if ($this->sql_calc_found_rows) {
      $sql .= " SQL_CALC_FOUND_ROWS";
    }
    if (count($this->columns) == 0) {
      $sql .= " * ";
    } else {
      $sql .= " ".implode(",", $this->columns)." ";
    }

    $sql .= "\nFROM ".$this->table;
    foreach ($this->joins as $jay) {
      $sql .= "\n".$jay->toSQL($this->connection);
    }

    if (count($this->criteria) > 0) {
      $sql .= "\nWHERE ".parent::toSQL($this->connection)." ";
    }

    if (count($this->groupby) > 0) {
      $sql .= "\nGROUP BY ".implode(", ", $this->groupby);
    }

    if ($this->order != "") {
      $sql .= "\nORDER BY ".$this->order;
      if (in_array($this->direction, Array('ASC', 'DESC'))) {
        $sql .= " ".$this->direction;
      }
    }

    if ($this->limit != "") {
      $sql .= "\nLIMIT ".((int) $this->limit);
    }
    if ($this->offset != "") {
      $sql .= "\nOFFSET ".((int) $this->offset);
    }
    return $sql;
  }
}
