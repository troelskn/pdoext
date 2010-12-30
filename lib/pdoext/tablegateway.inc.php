<?php
  /**
   * A generic table gateway.
   */
class pdoext_TableGateway implements IteratorAggregate, Countable {
  protected $tablename;
  protected $pkey = null;

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
  }

  /**
   * Implements IteratorAggregate::getIterator()
   */
  function getIterator() {
    return $this->select();
  }

  /**
   * Return a count of all records
   *
   * Implements Countable::count()
   */
  function count() {
    $query = "SELECT count(*) FROM " . $this->db->quoteName($this->tablename);
    $result = $this->db->query($query);
    $row = $result->fetch(PDO::FETCH_NUM);
    return $row[0];
  }

  /**
   * Creates a record from an array
   */
  function load($row) {
    if (is_array($row)) {
      return new pdoext_DatabaseRecord($row, $this->tablename);
    }
  }

  /**
   * Creates a new record
   */
  function create() {
    return $this->load(array());
  }

  /**
   * Selects a single entity by pk
   */
  function find($id) {
    $func_get_args = func_get_args();
    return $this->fetch(array_combine($this->getPKey(), $func_get_args));
  }

  /**
   * Returns a selection query.
   */
  function select() {
    return new pdoext_Selection($this, $this->db);
  }

  /**
   * Executes an SQL statement, returning a result set as a pdoext_Resultset object
   */
  function query($statement) {
    return new pdoext_Resultset($this->db->query($statement), $this);
  }

  /**
   * Prepares a query, binds parameters, and executes it.
   */
  function pexecute($sql, $input_params = null) {
    return new pdoext_Resultset($this->db->pexecute($sql, $input_params), $this);
  }

  /**
   * Returns the PK columns.
   * @return array
   */
  function getPKey() {
    if ($this->pkey === null) {
      $this->pkey = array();
      $columns = $this->reflect();
      foreach ($columns as $column => $info) {
        if ($info['pk']) {
          $this->pkey[] = $column;
        }
      }
      if (count($this->pkey) == 0 && isset($columns['id'])) {
        $this->pkey[] = 'id';
      }
      if (count($this->pkey) == 0) {
        throw new Exception("Could not determine primary key");
      }
    }
    return $this->pkey;
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
   * Introspects the schema, and returns an array of the table's columns.
   * @return [] hash
   */
  protected function reflect() {
    if (!$this->columns) {
      $this->columns = $this->db->getInformationSchema()->getColumns($this->tablename);
    }
    return $this->columns;
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
   * Resets errors for an entity.
   * You can override this, if you want to report errors in a different way.
   */
  protected function clearErrors($entity) {
    if (is_object($entity)) {
      $entity->_errors = array();
    }
  }

  /**
   * Determines if there are any errors for an entity.
   * You can override this, if you want to report errors in a different way.
   */
  protected function hasErrors($entity) {
    return is_object($entity) && is_array($entity->_errors) && count($entity->_errors) > 0;
  }

  /**
   * Hook for validating before update or insert
   * Set errors on `$data->_errors` to abort.
   */
  protected function validate($data) {}

  /**
   * Hook for validating before update.
   * Set errors on `$data->_errors` to abort.
   */
  protected function validateUpdate($data) {}

  /**
   * Hook for validating before insert
   * Set errors on `$data->_errors` to abort.
   */
  protected function validateInsert($data) {}

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
      if (!$column) {
        throw new Exception("Illegal condition");
      }
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
   * Inserts a row to the table.
   * @param  $data       array  Associative array of column => value to insert.
   * @return boolean
   */
  function insert($entity) {
    $this->clearErrors($entity);
    $this->validateInsert($entity);
    $this->validate($entity);
    if ($this->hasErrors($entity)) {
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
    $this->clearErrors($entity);
    $this->validateUpdate($entity);
    $this->validate($entity);
    if ($this->hasErrors($entity)) {
      return false;
    }
    $data = $this->marshal($entity);
    $pk = $this->getPKey();
    if (!is_null($condition)) {
      $condition = $this->marshal($condition);
    } else {
      $condition = array();
      foreach ($pk as $column) {
        if (isset($data[$column])) {
          $condition[$column] = $data[$column];
        } else {
          throw new Exception("No conditions given and PK is missing for update");
        }
      }
    }
    $query = "UPDATE " . $this->db->quoteName($this->tablename) . "\nSET";
    $columns = array();
    $bind = array();
    foreach ($this->getColumns() as $column) {
      if (array_key_exists($column, $data) && !in_array($column, $pk)) {
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

/**
 * A single table query.
 *
 * Can be paginated and counted.
 */
class pdoext_Selection extends pdoext_Query implements IteratorAggregate {
  protected $db;
  protected $gateway;
  protected $result;
  protected $current_page;
  protected $page_size;
  function __construct(pdoext_TableGateway $gateway, pdoext_Connection $db) {
    parent::__construct($gateway->getTable());
    $this->gateway = $gateway;
    $this->db = $db;
  }
  function paginate($current_page, $page_size = 10) {
    $this->current_page = $current_page;
    $this->page_size = $page_size;
    return $this;
  }
  function orderBy($order, $direction = null) {
    $this->setOrder($order, $direction);
    return $this;
  }
  function currentPage() {
    return $this->current_page;
  }
  function pageSize() {
    return $this->page_size;
  }
  function totalPages() {
    if ($this->page_size) {
      return (int) ceil($this->totalCount() / $this->page_size);
    }
  }
  function totalCount() {
    $this->executeQuery();
    return $this->total_count;
  }
  function getIterator() {
    $this->executeQuery();
    return $this->result;
  }
  protected function executeQuery() {
    if ($this->result) {
      return;
    }
    if ($this->currentPage()) {
      $this->setSqlCalcFoundRows();
      $limit = $this->pageSize();
      $offset = max($this->currentPage() - 1, 0) * $this->pageSize();
      $this->setLimit($limit);
      $this->setOffset($offset);
    }
    $result = $this->db->query($this);
    $result->setFetchMode(PDO::FETCH_ASSOC);
    if (method_exists($this->gateway, 'load')) {
      $this->result = new pdoext_Resultset($result, $this->gateway);
    } else {
      $this->result = $result;
    }
    if ($this->currentPage()) {
      if ($this->db->supportsSqlCalcFoundRows()) { // MySql specific
        $result = $this->db->query("SELECT FOUND_ROWS()");
        $row = $result->fetch();
        $this->total_count = $row[0];
      } else { // fall back on select count(*)
        $this->setLimit(null);
        $this->setOffset(null);
        $q = new pdoext_Query($this);
        $q->addColumn(pdoext_literal('count(*)'), 'total_count');
        $result = $this->db->query($q);
        $row = $result->fetch();
        $this->total_count = $row[0];
        $this->setLimit($limit);
        $this->setOffset($offset);
      }
    }
  }
}

/**
 * A single table resultset.
 *
 * Will hydrate (load) rows.
 */
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

/**
 * An active record style wrapper around a database row.
 */
class pdoext_DatabaseRecord implements ArrayAccess {
  public $_errors = array();
  protected $_row;
  protected $_tablename;
  protected static $_belongs_to = array();
  protected static $_has_many = array();
  function __construct($row, $tablename) {
    $this->_row = array();
    foreach ($row as $key => $value) {
      if (is_callable(array($this, 'set'.$key))) {
        call_user_func(array($this, 'set'.$key), $value);
      } else {
        $this->_row[$key] = $value;
      }
    }
    $this->_tablename = $tablename;
  }
  protected static function belongsTo($tablename) {
    if (!isset(self::$_belongs_to[$tablename])) {
      self::$_belongs_to[$tablename] = array();
      foreach (pdoext_db()->getInformationSchema()->getForeignKeys($tablename) as $info) {
        $name = preg_replace('/_id$/', '', $info['column']);
        self::$_belongs_to[$tablename][$name] = $info;
      }
    }
    return self::$_belongs_to[$tablename];
  }
  protected static function hasMany($tablename) {
    if (!isset(self::$_has_many[$tablename])) {
      self::$_has_many[$tablename] = array();
      foreach (pdoext_db()->getInformationSchema()->getReferencingKeys($tablename) as $info) {
        $name = $info['table'];
        self::$_has_many[$tablename][$name] = $info;
      }
    }
    return self::$_has_many[$tablename];
  }
  function getArrayCopy() {
    return $this->_row;
  }
  function __get($name) {
    $internal_name = $this->underscore($name);
    if (is_callable(array($this, 'get'.$internal_name))) {
      return call_user_func(array($this, 'get'.$internal_name));
    }
    if (array_key_exists($internal_name, $this->_row)) {
      return $this->_row[$internal_name];
    }
    $belongs_to = self::belongsTo($this->_tablename);
    if (isset($belongs_to[$internal_name])) {
      $referenced_table = $belongs_to[$internal_name]['referenced_table'];
      $referenced_column = $belongs_to[$internal_name]['referenced_column'];
      $column = $belongs_to[$internal_name]['column'];
      if (isset($this->_row[$column])) {
        return pdoext_db()->table($referenced_table)->fetch(array($referenced_column => $this->_row[$column]));
      }
      return null;
    }
    $has_many = self::hasMany($this->_tablename);
    if (isset($has_many[$internal_name])) {
      $referenced_column = $has_many[$internal_name]['referenced_column'];
      $table = $has_many[$internal_name]['table'];
      $column = $has_many[$internal_name]['column'];
      return pdoext_db()->table($table)->select()->where($column, $this->_row[$referenced_column]);
    }
  }
  function __set($name, $value) {
    $internal_name = $this->underscore($name);
    if (is_callable(array($this, 'set'.$internal_name))) {
      return call_user_func(array($this, 'set'.$internal_name), $value);
    }
    if (array_key_exists($internal_name, $this->_row)) {
      $this->_row[$internal_name] = $value;
      return;
    }
    throw new Exception("Undefined property '$name'");
  }
  function offsetExists($name) {
    $internal_name = $this->underscore($name);
    if (is_callable(array($this, 'get'.$internal_name))) {
      return true;
    }
    if (array_key_exists($internal_name, $this->_row)) {
      return true;
    }
    $belongs_to = self::belongsTo($this->_tablename);
    if (isset($belongs_to[$internal_name])) {
      return true;
    }
    $has_many = self::hasMany($this->_tablename);
    if (isset($has_many[$internal_name])) {
      return true;
    }
    return false;
  }
  function offsetGet($key) {
    return $this->__get($key);
  }
  function offsetSet($key, $value) {
    $this->__set($key, $value);
  }
  function offsetUnset($key) {
    unset($this->_row[$key]);
  }
  protected function underscore($cameled) {
    return implode(
      '_',
      array_map(
        'strtolower',
        preg_split('/([A-Z]{1}[^A-Z]*)/', $cameled, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)));
  }
}

