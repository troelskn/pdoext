<?php
/**
  * A few extensions to the core PDO class.
  * Adds some helpers and patches differences between sqlite and mysql.
  * @license LGPL
  */
class pdoext_Connection extends PDO {
  protected $_logTarget = null;
  protected $_slowLogOffset = null;
  protected $_inTransaction = false;

  protected $_nameOpening;
  protected $_nameClosing;

  protected $_tableGatewayCache;
  protected $_informationSchema;

  /**
   * Creates a new database connection.
   * Set `$failSafe` to true to have execution exit on error. Otherwise you'll get an exception and the stacktrace will contain your database password. Since such traces are usually logged somewhere, it is an unsafe thing to allow.
   */
  public function __construct($dsn, $user = null, $password = null, $attributes = array(), $failSafe = true) {
    try {
      parent::__construct($dsn, $user, $password, $attributes);
      $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $ex) {
      if ($failSafe) {
        die("Database connection failed: " . $ex->getMessage() . " in file ".__FILE__." at line ".__LINE__);
      } else {
        throw $ex;
      }
    }
    switch ($this->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      case 'mysql':
        $this->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        $this->_nameOpening = $this->_nameClosing = '`';
        break;

      case 'mssql':
        $this->_nameOpening = '[';
        $this->_nameClosing = ']';
        break;

      case 'sqlite':
        $this->sqliteCreateAggregate(
          "group_concat",
          array($this, '__sqlite_group_concat_step'),
          array($this, '__sqlite_group_concat_finalize'),
          2
        );
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('pdoext_SQLiteStatement'));
        // fallthru

      default:
        $this->_nameOpening = $this->_nameClosing = '"';
        break;
    }
    $this->_informationSchema = new pdoext_InformationSchema($this);
  }

  /**
   * Returns the information schema for the database.
   * @returns pdoext_InformationSchema
   */
  public function getInformationSchema() {
    return $this->_informationSchema;
  }

  /**
   * Tells whether the rdbms supports `SQL_CALC_ROWS_FOUND` directive.
   * @returns boolean
   */
  public function supportsSqlCalcFoundRows() {
    return $this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  }

  /**
   * Enables logging.
   * You can pass a file name as first argument (`$logTarget`) - otherwise it will log to stdout.
   * You may also pass a number to `$slowLogOffset` - Then only queries that are slower than the offset will be logged.
   */
  public function setLogging($logTarget = 'php://stdout', $slowLogOffset = null) {
    $this->_logTarget = $logTarget;
    $this->_slowLogOffset = $slowLogOffset;
    if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
      $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('pdoext_LoggingStatement'));
    }
  }

  /**
   * Writes an entry to the log. This is an internal function and shouldn't be called outside of pdoext.
   * @internal
   */
  function log($sql, $t, $params = null) {
    if ($this->_logTarget) {
      if ($this->_slowLogOffset && $t < $this->_slowLogOffset) {
        return;
      }
      $more = $params ? ("\n---\n" . var_export($params, true)) : "";
      error_log("[" . date("Y-m-d H:i:s") . "] [" . number_format($t / 1000, 4) . " s] from " . pdoext_find_caller() . "\n---\n" . $sql . $more . "\n---\n", 3, $this->_logTarget);
    }
  }

  /**
   * Execute an SQL statement and return the number of affected rows.
   */
  public function exec($statement) {
    $sql = $statement instanceOf pdoext_Query ? $statement->toSql($this) : $statement;
    $t = microtime(true);
    $result = parent::exec($sql);
    $this->log($sql, microtime(true) - $t);
    return $result;
  }

  /**
   * Executes an SQL statement, returning a result set as a PDOStatement object
   */
  public function query($statement) {
    $sql = $statement instanceOf pdoext_Query ? $statement->toSql($this) : $statement;
    $t = microtime(true);
    $result = parent::query($sql);
    $this->log($sql, microtime(true) - $t);
    return $result;
  }

  /**
   * @internal
   */
  function __sqlite_group_concat_step($context, $idx, $string, $separator = ",") {
    return $context ? ($context . $separator . $string) : $string;
  }

  /**
   * @internal
   */
  function __sqlite_group_concat_finalize($context) {
    return $context;
  }

  /**
   * Workaround for bug in PDO:
   *   http://bugs.php.net/bug.php?id=41698
   * @internal
   */
  protected function castInputParams($input) {
    $safe = array();
    foreach ($input as $key => $value) {
      if (is_float($value)) {
        $safe[$key] = number_format($value, 2, '.', '');
      } else {
        $safe[$key] = $value;
      }
    }
    return $safe;
  }

  /**
   * Prepares a statement for execution and returns a statement object.
   */
  public function prepare($sql, $options = array()) {
    $stmt = parent::prepare($sql, $options);
    if ($this->_logTarget) {
      $stmt->setLogging($this, $sql);
    }
    return $stmt;
  }

  /**
   * Prepares a query, binds parameters, and executes it.
   * If you're going to run the query multiple times, it's faster to prepare once, and reuse the statement.
   */
  public function pexecute($sql, $input_params = null) {
    $stmt = $this->prepare($sql);
    if (is_array($input_params)) {
      $stmt->execute($this->castInputParams($input_params));
    } else {
      $stmt->execute();
    }
    return $stmt;
  }

  /**
   * Returns true if a transaction has been started, and not yet finished.
   * @returns boolean
   */
  public function _inTransaction() {
    return !! $this->_inTransaction;
  }

  /**
   * Throws an exception if a transaction hasn't been started
   */
  public function assertTransaction() {
    if (!$this->_inTransaction()) {
      throw new pdoext_NoTransactionStartedException();
    }
  }

  /**
   * Initiates a transaction.
   * Like PDO::beginTransaction(), but throws an exception, if a transaction is already started.
   */
  public function beginTransaction() {
    if ($this->_inTransaction) {
      throw new pdoext_AlreadyInTransactionException(sprintf("Already in transaction. Transaction started at line %s in file %s", $this->_inTransaction[0], $this->_inTransaction[1]));
    }
    $result = parent::beginTransaction();
    $stack = debug_backtrace();
    $this->_inTransaction = array($stack[0]['file'], $stack[0]['line']);
    return $result;
  }

  /**
   * Rolls back a transaction
   */
  public function rollback() {
    $result = parent::rollback();
    $this->_inTransaction = false;
    return $result;
  }

  /**
   * Commits a transaction
   */
  public function commit() {
    $result = parent::commit();
    $this->_inTransaction = false;
    return $result;
  }

  /**
    * Escapes names (tables, columns etc.)
    */
  public function quoteName($name) {
    $names = array();
    foreach (explode(".", $name) as $name) {
      $names[] = $this->_nameOpening
        .str_replace($this->_nameClosing, $this->_nameClosing.$this->_nameClosing, $name)
        .$this->_nameClosing;
    }
    return implode(".", $names);
  }

  /**
    * Returns reflection information about a table.
    * @deprecated
    */
  public function getTableMeta($table) {
    return $this->getInformationSchema()->getColumns($table);
  }

  /**
   * Returns a table gateway.
   * @returns pdoext_TableGateway
   */
  function table($tablename) {
    if (!isset($this->_tableGatewayCache[$tablename])) {
      if (isset($GLOBALS['PDOEXT_TABLE_GATEWAY_MAPPING'][$tablename])) {
        $gatewayclass = $GLOBALS['PDOEXT_TABLE_GATEWAY_MAPPING'][$tablename];
      } else {
        $gatewayclass = str_replace('_', '', $tablename);
        $gatewayclass .= 'gateway';
        if (!class_exists($gatewayclass)) {
          $gatewayclass = 'pdoext_TableGateway';
        }
      }
      $recordclass = preg_replace('/sgateway$/', '', strtolower($gatewayclass)); // @TODO use inflection here
      if (class_exists($recordclass)) {
        $this->_tableGatewayCache[$tablename] = new $gatewayclass($tablename, $this, $recordclass);
      } else {
        $this->_tableGatewayCache[$tablename] = new $gatewayclass($tablename, $this);
      }
    }
    return $this->_tableGatewayCache[$tablename];
  }

  /**
   * Magic property getter - alias for `table()`
   */
  function __get($name) {
    return $this->table($name);
  }
}

/**
 * An extended PDOStatement that logs when executing.
 */
class pdoext_LoggingStatement extends PDOStatement {
  protected $logger;
  protected $sql;
  public function setLogging($logger, $sql) {
    $this->logger = $logger;
    $this->sql = $sql;
  }
  public function execute($input_parameters = array()) {
    if ($this->logger) {
      $t = microtime(true);
      $result = parent::execute($input_parameters);
      $this->logger->log($this->sql, microtime(true) - $t, $input_parameters);
      return $result;
    }
    return parent::execute($input_parameters);
  }
}

/**
  * Workaround for a bug in sqlite:
  *   http://www.sqlite.org/cvstrac/tktview?tn=2378
  */
class pdoext_SQLiteStatement extends pdoext_LoggingStatement {
  protected function fixQuoteBug($hash) {
    $result = array();
    foreach ($hash as $key => $value) {
      if (strpos($key, '"') === 0) {
        $result[substr($key, 1, -1)] = $value;
      } else {
        $result[$key] = $value;
      }
    }
    return $result;
  }

  function fetch($fetch_style = PDO::FETCH_BOTH, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 1) {
    $row = parent::fetch($fetch_style, $cursor_orientation, $cursor_offset);
    return $row ? $this->fixQuoteBug($row) : $row;
  }


  function fetchAll($fetch_style = PDO::FETCH_BOTH, $class_name = 0, $ctor_args = array()) {
    return array_map(array($this, 'fixQuoteBug'), parent::fetchAll($fetch_style));
  }
}

/**
 * This exception is raised if you try to `rollback` or `commit` when not in transaction.
 */
class pdoext_NoTransactionStartedException extends Exception {
  function __construct($message = "No transaction started", $code = 0) {
    parent::__construct($message, $code);
  }
}

/**
 * This exception is raised if you try to begin a nested transaction.
 */
class pdoext_AlreadyInTransactionException extends Exception {}

/**
 * This exception is raised if the driver doesn't support introspection via information schema.
 */
class pdoext_MetaNotSupportedException extends Exception {
  function __construct($message = "Meta querying not available for driver type", $code = 0) {
    parent::__construct($message, $code);
  }
}

/**
 * Provides access to introspection of the database schema.
 */
class pdoext_InformationSchema {
  protected $connection;
  protected $has_many = array();
  protected $belongs_to = array();
  function __construct($connection) {
    $this->connection = $connection;
  }
  /**
    * Returns list of tables in database.
    */
  public function getTables() {
    switch ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      case 'mysql':
        $sql = "SHOW TABLES";
        break;
      case 'sqlite':
        $sql = 'SELECT name FROM sqlite_master WHERE type = "table"';
        break;
      default:
        throw new pdoext_MetaNotSupportedException();
    }
    $result = $this->connection->query($sql);
    $result->setFetchMode(PDO::FETCH_NUM);
    $meta = array();
    foreach ($result as $row) {
      $meta[] = $row[0];
    }
    return $meta;
  }
  /**
    * Returns reflection information about a table.
    */
  public function getColumns($table) {
    switch ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      // select TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, COLUMN_KEY from INFORMATION_SCHEMA.COLUMNS where TABLE_SCHEMA = DATABASE() and TABLE_NAME = 'device_registrations';
      case 'sqlite':
        $result = $this->connection->query("PRAGMA table_info(".$this->connection->quoteName($table).")");
        $result->setFetchMode(PDO::FETCH_ASSOC);
        $meta = array();
        foreach ($result as $row) {
          $meta[$row['name']] = array(
            'pk' => $row['pk'] == '1',
            'type' => $row['type'],
            'default' => null,
            'blob' => preg_match('/(TEXT|BLOB)/', $row['type']),
          );
        }
        return $meta;
      default:
        $result = $this->connection->pexecute(
          "select COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, COLUMN_KEY from INFORMATION_SCHEMA.COLUMNS where TABLE_SCHEMA = DATABASE() and TABLE_NAME = :table_name",
          array(':table_name' => $table));
        $result->setFetchMode(PDO::FETCH_ASSOC);
        $meta = array();
        foreach ($result as $row) {
          $meta[$row['COLUMN_NAME']] = array(
            'pk' => $row['COLUMN_KEY'] == 'PRI',
            'type' => $row['DATA_TYPE'],
            'default' => in_array($row['COLUMN_DEFAULT'], array('NULL', 'CURRENT_TIMESTAMP')) ? null : $row['COLUMN_DEFAULT'],
            'blob' => preg_match('/(TEXT|BLOB)/', $row['DATA_TYPE']),
          );
        }
        return $meta;
    }
  }
  /**
    * Returns a list of foreign keys for a table.
    */
  public function getForeignKeys($table) {
    switch ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      case 'mysql':
        $meta = array();
        foreach ($this->loadKeys() as $info) {
          if ($info['table_name'] === $table) {
            $meta[] = array(
              'table' => $info['table_name'],
              'column' => $info['column_name'],
              'referenced_table' => $info['referenced_table_name'],
              'referenced_column' => $info['referenced_column_name'],
            );
          }
        }
        return $meta;
      case 'sqlite':
        $sql = "PRAGMA foreign_key_list(".$this->connection->quoteName($table).")";
        break;
      default:
        throw new pdoext_MetaNotSupportedException();
    }
    $result = $this->connection->query($sql);
    $result->setFetchMode(PDO::FETCH_ASSOC);
    $meta = array();
    foreach ($result as $row) {
      $meta[] = array(
        'table' => $table,
        'column' => $row['from'],
        'referenced_table' => $row['table'],
        'referenced_column' => $row['to'],
      );
    }
    return $meta;
  }
  /**
    * Returns a list of foreign keys that refer a table.
    */
  public function getReferencingKeys($table) {
    switch ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      case 'mysql':
        $meta = array();
        foreach ($this->loadKeys() as $info) {
          if ($info['referenced_table_name'] === $table) {
            $meta[] = array(
              'table' => $info['table_name'],
              'column' => $info['column_name'],
              'referenced_table' => $info['referenced_table_name'],
              'referenced_column' => $info['referenced_column_name'],
            );
          }
        }
        return $meta;
      case 'sqlite':
        $meta = array();
        foreach ($this->getTables() as $tbl) {
          if ($tbl != $table) {
            foreach ($this->getForeignKeys($tbl) as $info) {
              if ($info['referenced_table'] == $table) {
                $meta[] = $info;
              }
            }
          }
        }
        return $meta;
      default:
        throw new pdoext_MetaNotSupportedException();
    }
  }
  function belongsTo($tablename) {
    if (!isset($this->belongs_to[$tablename])) {
      $this->belongs_to[$tablename] = array();
      foreach ($this->getForeignKeys($tablename) as $info) {
        $name = preg_replace('/_id$/', '', $info['column']);
        $this->belongs_to[$tablename][$name] = $info;
      }
    }
    return $this->belongs_to[$tablename];
  }
  function hasMany($tablename) {
    if (!isset($this->has_many[$tablename])) {
      $this->has_many[$tablename] = array();
      foreach ($this->getReferencingKeys($tablename) as $info) {
        $name = $info['table'];
        $this->has_many[$tablename][$name] = $info;
      }
    }
    return $this->has_many[$tablename];
  }

  /**
   * @internal
   */
  protected function loadKeys() {
    if (!isset($this->keys)) {
      $sql = "SELECT TABLE_NAME AS `table_name`, COLUMN_NAME AS `column_name`, REFERENCED_COLUMN_NAME AS `referenced_column_name`, REFERENCED_TABLE_NAME AS `referenced_table_name`
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND REFERENCED_TABLE_SCHEMA = DATABASE()";
      $result = $this->connection->query($sql);
      $result->setFetchMode(PDO::FETCH_ASSOC);
      $this->keys = array();
      foreach ($result as $row) {
        $this->keys[] = $row;
      }
    }
    return $this->keys;
  }
}
