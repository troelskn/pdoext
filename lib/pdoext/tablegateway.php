<?php
  /**
   * A generic table gateway.
   */
class pdoext_TableGateway {
  protected $tablename;
  protected $pkey;

  protected $db;
  protected $columns = null;

  /**
   *
   * @param  $tablename  string  Name of the table
   * @param  $db         pdoext_Connection  The database connection
   */
  function __construct($tablename, pdoext_Connection $db) {
    $this->tablename = $tablename;
    $this->db = $db;
    $this->pkey = $this->getPKey();
  }

  protected function marshal($object) {
    if (is_array($object)) {
      return $object;
    }
    if ($object instanceOf ArrayAccess) {
      return $object->getArrayCopy();
    }
    throw new Exception("Unable to marshal object into hash.");
  }

  /**
   * Introspects the schema, and returns an array of the table's columns.
   * @return [] hash
   */
  function reflect() {
    if (!$this->columns) {
      $this->columns = $this->db->getTableMeta($this->tablename);
    }
    return $this->columns;
  }

  /**
   * Returns the PK column.
   * Note that this pre-supposes that the primary key is a single column, which may not always be the case.
   * @return hash
   */
  function getPKey() {
    foreach ($this->reflect() as $column => $info) {
      if ($info['pk']) {
        return $column;
      }
    }
  }

  /**
   * @return string
   */
  function getTable() {
    return $this->tablename;
  }

  /**
   * Returns the column names
   * @return [] string
   */
  function getColumns() {
    return array_keys($this->reflect());
  }

  /**
   * Selects a single row from the table.
   * If multiple rows are matched, only the first result is returned.
   * @param  $condition  array  Associative array of column => value to serve as conditions for the query.
   * @return array
   */
  function fetch($condition) {
    $condition = $this->marshal($condition);
    $query = "SELECT * FROM " . $this->db->quoteName($this->tablename);
    $where = array();
    $bind = array();
    foreach ($condition as $column => $value) {
      if ($value instanceOf pdoext_query_iExpression) {
        $where[] = $this->db->quoteName($column) . " = " . $value->toSql($this->db);
      } else {
        $where[] = $this->db->quoteName($column) . " = :" . $column;
        $bind[":" . $column] = $value;
      }
    }
    if (count($where) === 0) {
      throw new Exception("No conditions given for fetch");
    }
    $query .= "\nWHERE\n    " . implode("\n    AND ", $where);
    $result = $this->db->pexecute($query, $bind);
    return $result->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Inserts a row to the table.
   * @param  $data       array  Associative array of column => value to insert.
   * @return boolean
   */
  function insert($data) {
    $data = $this->marshal($data);
    $query = "INSERT INTO " . $this->db->quoteName($this->tablename);
    $columns = array();
    $values = array();
    $bind = array();
    foreach ($this->getColumns() as $column) {
      if (array_key_exists($column, $data)) {
        $value = $data[$column];
        $columns[] = $this->db->quoteName($column);
        if ($value instanceOf pdoext_query_iExpression) {
          $values[] = $value->toSql($this->db);
        } else {
          $values[] = ":" . $column;
          $bind[":" . $column] = $value;
        }
      }
    }
    $query .= " (" . implode(", ", $columns) . ")";
    $query .= " VALUES (" . implode(", ", $values) . ")";
    $this->db->pexecute($query, $bind);
    return $this->db->lastInsertId();
  }

  /**
   * Updates one or more rows.
   * @param  $data       array  Associative array of column => value to update the found columns with.
   * @param  $condition  array  Associative array of column => value to serve as conditions for the query.
   * @return boolean
   */
  function update($data, $condition) {
    $data = $this->marshal($data);
    $condition = $this->marshal($condition);
    $query = "UPDATE " . $this->db->quoteName($this->tablename) . " SET";
    $columns = array();
    $bind = array();
    $pk = $this->getPKey();
    foreach ($this->getColumns() as $column) {
      if (array_key_exists($column, $data) && $column != $pk) {
        $value = $data[$column];
        if ($value instanceOf pdoext_query_iExpression) {
          $columns[] = $this->db->quoteName($column) . " = " . $value->toSql($this->db);
        } else {
          $columns[] = $this->db->quoteName($column) . " = :" . $column;
          $bind[":" . $column] = $value;
        }
      }
    }
    $query .= "\n    " . implode(",\n    ", $columns);
    $where = array();
    foreach ($condition as $column => $value) {
      if ($value instanceOf pdoext_query_iExpression) {
        $where[] = $this->db->quoteName($column) . " = :where_" . $value->toSql($this->db);
      } else {
        $where[] = $this->db->quoteName($column) . " = :where_" . $column;
        $bind[":where_" . $column] = $value;
      }
    }
    if (count($where) === 0) {
      throw new Exception("No conditions given for update");
    }
    $query .= "\nWHERE\n    " . implode("\n    AND ", $where);
    return $this->db->pexecute($query, $bind);
  }

  /**
   * Deletes one or more rows.
   * @param  $condition  array  Associative array of column => value to serve as conditions for the query.
   * @return boolean
   */
  function delete($condition) {
    $condition = $this->marshal($condition);
    $query = "DELETE FROM " . $this->db->quoteName($this->tablename);
    $where = array();
    $bind = array();
    foreach ($condition as $column => $value) {
      if ($value instanceOf pdoext_query_iExpression) {
        $where[] = $this->db->quoteName($column) . " = " . $value->toSql($this->db);
      } else {
        $where[] = $this->db->quoteName($column) . " = :" . $column;
        $bind[":" . $column] = $value;
      }
    }
    if (count($where) === 0) {
      throw new Exception("No conditions given for delete");
    }
    $query .= "\nWHERE\n    " . implode("\n    AND ", $where);
    $result = $this->db->pexecute($query, $bind);
    return $result->rowCount() > 0;
  }
}
