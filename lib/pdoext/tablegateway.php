<?php
  /**
   * A generic table gateway.
   */
class pdoext_TableGateway implements IteratorAggregate, Countable {
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

  function getIterator() {
    return $this->select();
  }

  protected function marshal($object) {
    if (is_array($object)) {
      return $object;
    }
    if (is_object($object)) {
      if (method_exists($object, 'getArrayCopy')) {
        return $object->getArrayCopy();
      }
      return get_object_vars($object);
    }
    throw new Exception("Unable to marshal input into hash.");
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
   * Returns the column names of all columns that aren't TEXT/BLOB's
   * @return [] string
   */
  function getListableColumns() {
    $columns = array();
    foreach ($this->reflect() as $column => $info) {
      if (!$info['blob']) {
        $columns[] = $column;
      }
    }
    return $columns;
  }

  /**
   * Resets errors for an entity.
   * You can override this, if you want to report errors in a different way.
   */
  protected function clear_errors($entity) {
    $entity->errors = array();
  }

  /**
   * Determines if there are any errors for an entity.
   * You can override this, if you want to report errors in a different way.
   */
  protected function has_errors($entity) {
    return is_array($entity->errors) && count($entity->errors) > 0;
  }

  /**
   * Hook for validating before update or insert
   * Set errors on `$data->errors` to abort.
   */
  protected function validate($data) {}

  /**
   * Hook for validating before update.
   * Set errors on `$data->errors` to abort.
   */
  protected function validate_update($data) {}

  /**
   * Hook for validating before insert
   * Set errors on `$data->errors` to abort.
   */
  protected function validate_insert($data) {}

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
    $query .= "\nWHERE\n  " . implode("\n  AND ", $where);
    $result = $this->db->pexecute($query, $bind);
    if (method_exists($this, 'load')) {
      $row = $result->fetch(PDO::FETCH_ASSOC);
      return $row ? $this->load($row) : null;
    }
    return $result->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Return a selection of all records
   */
  function select($limit = null, $offset = 0, $order = null, $direction = null) {
    $query = "SELECT * FROM " . $this->db->quoteName($this->tablename);
    if ($order) {
      $query .= "\nORDER BY " . $this->db->quoteName($order);
      if ($direction) {
        $query .= strtolower($direction) === 'desc' ? 'desc' : 'asc';
      }
    }
    if ($limit) {
      $query .= "\nLIMIT " . ((integer) $limit);
    }
    if ($offset) {
      $query .= "\nOFFSET " . ((integer) $offset);
    }
    $result = $this->db->query($query);
    $result->setFetchMode(PDO::FETCH_ASSOC);
    if (method_exists($this, 'load')) {
      return new pdoext_Resultset($result, $this);
      // TODO: Replace with a lazy iterator, to take benefit of buffered queries
      // return new ArrayIterator(array_map(array($this, 'load'), $result->fetchAll(PDO::FETCH_ASSOC)));
    }
    return $result;
  }

  /**
   * Return a count of all records
   */
  function count() {
    $query = "SELECT count(*) FROM " . $this->db->quoteName($this->tablename);
    $result = $this->db->query($query);
    $row = $result->fetch(PDO::FETCH_NUM);
    return $row[0];
  }

  /**
   * Inserts a row to the table.
   * @param  $data       array  Associative array of column => value to insert.
   * @return boolean
   */
  function insert($entity) {
    if (is_object($entity)) {
      $this->clear_errors($entity);
    }
    $this->validate_insert($entity);
    $this->validate($entity);
    if (is_object($entity) && $this->has_errors($entity)) {
      return null;
    }
    $data = $this->marshal($entity);
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
   * If second parameter isn't set, the PK from first parameter is used instead.
   * @param  $data       array  Associative array of column => value to update the found columns with.
   * @param  $condition  array  Associative array of column => value to serve as conditions for the query.
   * @return boolean
   */
  function update($entity, $condition = null) {
    if (is_object($entity)) {
      $this->clear_errors($entity);
    }
    $this->validate_update($entity);
    $this->validate($entity);
    if (is_object($entity) && $this->has_errors($entity)) {
      return false;
    }
    $data = $this->marshal($entity);
    $pk = $this->getPKey();
    if (!is_null($condition)) {
      $condition = $this->marshal($condition);
    } elseif (isset($data[$pk])) {
      $condition = array($pk => $data[$pk]);
    } else {
      throw new Exception("No conditions given and PK is missing for update");
    }
    $query = "UPDATE " . $this->db->quoteName($this->tablename) . "\nSET";
    $columns = array();
    $bind = array();
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
    $query .= "\n  " . implode(",\n  ", $columns);
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
    $query .= "\nWHERE\n  " . implode("\n  AND ", $where);
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

class pdoext_Resultset implements Iterator {
  protected $cursor;
  protected $loader;
  protected $key = 0;
  protected $current = null;
  function __construct($cursor, $loader) {
    $this->cursor = $cursor;
    $this->loader = $loader;
  }
  protected function load($row) {
    return $row ? $this->loader->load($row) : $row;
  }
  function current() {
    return $this->current = $this->current === null ? $this->load($this->cursor->fetch(PDO::FETCH_ASSOC)) : $this->current;
  }
  function key() {
    return $this->key;
  }
  function next() {
    $this->key++;
    $this->current = null;
    return $this->current();
  }
  function rewind() {
    if ($this->current !== null) {
      throw new Exception("Can't rewind database resultset");
    }
  }
  function valid() {
    return $this->current() !== false;
  }
}