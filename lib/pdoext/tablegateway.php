<?php
  /**
   * A generic table gateway.
   */
class pdoext_TableGateway
{
  protected $tableName;
  protected $pkey;

  protected $db;
  protected $columns = NULL;

  /**
   *
   * @param  $tableName  string  Name of the table
   * @param  $db         pdoext_Connection  The database connection
   */
  function __construct($tableName, pdoext_Connection $db) {
    $this->tableName = $tableName;
    $this->db = $db;
    $this->pkey = $this->getPKey();
  }

  /**
   * Introspects the schema, and returns an array of the table's columns.
   * @return [] hash
   */
  function reflect() {
    if (!$this->columns) {
      $this->columns = $this->db->getTableMeta($this->tableName);
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
    return $this->tableName;
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
    $query = "SELECT * FROM ".$this->db->quoteName($this->tableName);
    $where = Array();
    $values = Array();
    foreach ($condition as $column => $value) {
      $where[] = $this->db->quoteName($column)." = :".$column;
      $values[$column] = $value;
    }
    if (count($where) == 0) {
      throw new Exception("No conditions given for fetch");
    }
    $query .= "\nWHERE\n    ".implode("\n    AND ", $where);
    $result = $this->db->pexecute($query, $values);
    return $result->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Inserts a row to the table.
   * @param  $data       array  Associative array of column => value to insert.
   * @return boolean
   */
  function insert($data) {
    $query = "INSERT INTO ".$this->db->quoteName($this->tableName);
    $columns = Array();
    $values = Array();
    if (is_object($data)) {
      $data = $data->getArrayCopy();
    }
    foreach ($this->getColumns() as $column) {
      if (array_key_exists($column, $data)) {
        $columns[] = $column;
        $values[$column] = $data[$column];
      }
    }
    $query .= " (".implode(",", array_map(Array($this->db, 'quoteName'), $columns)).")";
    $query .= " VALUES (:".implode(", :", $columns).")";
    return $this->db->pexecute($query, $values);
  }

  /**
   * Updates one or more rows.
   * @param  $data       array  Associative array of column => value to update the found columns with.
   * @param  $condition  array  Associative array of column => value to serve as conditions for the query.
   * @return boolean
   */
  function update($data, $condition) {
    $query = "UPDATE ".$this->db->quoteName($this->tableName)." SET";
    $columns = Array();
    $values = Array();
    $pk = $this->getPKey();
    if (is_object($data)) {
      $data = $data->getArrayCopy();
    }
    foreach ($this->getColumns() as $column) {
      if (array_key_exists($column, $data) && $column != $pk) {
        $columns[] = $this->db->quoteName($column)." = :".$column;
        $values[$column] = $data[$column];
      }
    }
    $query .= "\n    ".implode(",\n    ", $columns);
    $where = Array();
    foreach ($condition as $column => $value) {
      $where[] = $this->db->quoteName($column)." = :where_".$column;
      $values["where_".$column] = $value;
    }
    if (count($where) == 0) {
      throw new Exception("No conditions given for update");
    }
    $query .= "\nWHERE\n    ".implode("\n    AND ", $where);
    return $this->db->pexecute($query, $values);
  }

  /**
   * Deletes one or more rows.
   * @param  $condition  array  Associative array of column => value to serve as conditions for the query.
   * @return boolean
   */
  function delete($condition) {
    $query = "DELETE FROM ".$this->db->quoteName($this->tableName);
    $where = Array();
    $values = Array();
    foreach ($condition as $column => $value) {
      $where[] = $this->db->quoteName($column)." = :".$column;
      $values[$column] = $value;
    }
    if (count($where) == 0) {
      throw new Exception("No conditions given for delete");
    }
    $query .= "\nWHERE\n    ".implode("\n    AND ", $where);
    $result = $this->db->pexecute($query, $values);
    return $result->rowCount() > 0;
  }
}
