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
  protected $_tableNameMapping = array();
  protected $_informationSchema;
  protected $_cacheEnabled = false;
  protected $_allowNestedTransaction = false;
  protected $_transactionLevel = 0;

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

  public function getNestedTransactionStatus() {
    return $this->_allowNestedTransaction;
  }

  /**
   * This feature is only supported for rdbms that supports SAVEPOINTs
   */
  public function enableNestedTransaction() {
    $this->_allowNestedTransaction = true;
  }

  public function disableNestedTransaction() {
    while ($this->_transactionLevel > 1) {
      $this->rollback();
    }
    $this->_allowNestedTransaction = false;
  }

  public function cacheEnabled() {
    return $this->_cacheEnabled;
  }

  public function enableCache() {
    $this->_cacheEnabled = true;
  }

  public function disableCache() {
    $this->_cacheEnabled = false;
  }

  public function purgeCache() {
    foreach ($this->_tableGatewayCache as $table) {
      $table->purgeCache();
    }
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
  function logBefore($sql, $params = null) {
    if ($this->_logTarget) {
      if ($this->_slowLogOffset) {
        return;
      }
      $hash = substr(md5($sql), 0, 8);
      $more = $params ? ("\n---\n" . var_export($params, true)) : "";
      error_log("*** $hash " . date("Y-m-d H:i:s") . " from " . pdoext_find_caller() . "\n---\n" . $sql . $more . "\n---\n", 3, $this->_logTarget);
    }
  }

  /**
   * Writes an entry to the log. This is an internal function and shouldn't be called outside of pdoext.
   * @internal
   */
  function logAfter($sql, $t, $params = null) {
    if ($this->_logTarget) {
      if ($this->_slowLogOffset && $t < $this->_slowLogOffset) {
        return;
      }
      $hash = substr(md5($sql), 0, 8);
      if ($this->_slowLogOffset) {
        $more = $params ? ("\n---\n" . var_export($params, true)) : "";
        error_log("*** $hash " . date("Y-m-d H:i:s") . " from " . pdoext_find_caller() . "\n---\n" . $sql . $more . "\n---\n", 3, $this->_logTarget);
      }
      error_log("*** $hash query completed in " . number_format($t, 4) . " s\n---\n", 3, $this->_logTarget);
    }
  }

  /**
   * Execute an SQL statement and return the number of affected rows.
   */
  public function exec($statement) {
    $sql = $statement instanceOf pdoext_Query ? $statement->toSql($this) : $statement;
    $t = microtime(true);
    $this->logBefore($sql);
    $result = parent::exec($sql);
    $this->logAfter($sql, microtime(true) - $t);
    return $result;
  }

  /**
   * Executes an SQL statement, returning a result set as a PDOStatement object
   */
  public function query($statement) {
    $sql = $statement instanceOf pdoext_Query ? $statement->toSql($this) : $statement;
    $t = microtime(true);
    $this->logBefore($sql);
    $result = parent::query($sql);
    $this->logAfter($sql, microtime(true) - $t);
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
      $stmt->execute($input_params);
    } else {
      $stmt->execute();
    }
    return $stmt;
  }

  /**
   * Returns true if a transaction has been started, and not yet finished.
   * @returns boolean
   */
  public function inTransaction() {
    return !! $this->_inTransaction;
  }

  /**
   * Throws an exception if a transaction hasn't been started
   */
  public function assertTransaction() {
    if (!$this->inTransaction()) {
      throw new pdoext_NoTransactionStartedException();
    }
  }

  /**
   * Initiates a transaction.
   * Like PDO::beginTransaction(), but throws an exception, if a transaction is already started.
   */
  public function beginTransaction() {
    if (!$this->_allowNestedTransaction && $this->_inTransaction) {
      throw new pdoext_AlreadyInTransactionException(sprintf("Already in transaction. Transaction started at line %s in file %s", $this->_inTransaction[0], $this->_inTransaction[1]));
    }

    if ($this->_transactionLevel > 0) {
      $result = $this->exec("SAVEPOINT LEVEL".$this->_transactionLevel);
    } else {
      $result = parent::beginTransaction();
    }
    $stack = debug_backtrace();
    if ($this->_transactionLevel == 0) {
      $this->_inTransaction = array($stack[0]['file'], $stack[0]['line']);
    }

    $this->_transactionLevel++;
    return $result;
  }


  /**
   * Rolls back a transaction
   */
  public function rollback() {
    $this->_transactionLevel--;
    if ($this->_transactionLevel > 0) {
      $result = $this->exec("ROLLBACK TO SAVEPOINT LEVEL".$this->_transactionLevel);
      $this->exec("RELEASE SAVEPOINT LEVEL".$this->_transactionLevel);
    } else {
      $result = parent::rollback();
      $this->_inTransaction = false;
    }
    return $result;
  }

  /**
   * Commits a transaction
   */
  public function commit() {
    $this->_transactionLevel--;
    if ($this->_transactionLevel > 0) {
      $result = $this->exec("RELEASE SAVEPOINT LEVEL".$this->_transactionLevel);
    } else {
      $result = parent::commit();
      $this->_inTransaction = false;
    }
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

  public function setTableNameMapping($mapping) {
    $this->_tableNameMapping = $mapping;
  }

  /**
   * Returns a table gateway.
   * @returns pdoext_TableGateway
   */
  public function table($tablename) {
    /*
      cases:

      mapping:
        tbl_name -> TableNamesGateway
      reverse:
        TableNamesGateway -> tbl_name

      input          output
      table_names -> TableNamesGateway
      tbl_name    -> TableNamesGateway
      tableNames  -> TableNamesGateway

      no mapping:

      input          output
      table_names -> TableNamesGateway
      tableNames  -> TableNamesGateway

     */
    $underscored_name = strtolower(
      implode('_', preg_split('/([A-Z]{1}[^A-Z]*)/', $tablename, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)));

    if (isset($this->_tableNameMapping[$underscored_name])) {
      $real_tablename = $this->_tableNameMapping[$underscored_name];
    } else {
      $real_tablename = $underscored_name;
    }
    $camelized_name = implode("", array_map('ucfirst', explode('_', $underscored_name)));
    $gatewayclass = $camelized_name . "Gateway";
    if (!isset($this->_tableGatewayCache[$real_tablename])) {
      $reverse_mapping = array_flip($this->_tableNameMapping);
      if (isset($reverse_mapping[$real_tablename])) {
        $camelized_name = implode("", array_map('ucfirst', explode('_', $reverse_mapping[$real_tablename])));
        $gatewayclass = $camelized_name . "Gateway";
      }
      if (!class_exists($gatewayclass)) {
        $gatewayclass = 'pdoext_TableGateway';
      }
      $this->_tableGatewayCache[$real_tablename] = new $gatewayclass($real_tablename, $this);
    }
    return $this->_tableGatewayCache[$real_tablename];
  }

  /**
   * Takes an array and generates an escaped (quoted) SQL string that fit with the IN statement
   * e.g.
   *   $ids = [1, 2, 3, 4];
   *   $stmt = $db->query("SELECT * FROM table WHERE id IN ".$db->safeIn($ids));
   */
  public function safeIn(array $elements) {
    $inList = "(";
    $first = true;
    foreach($elements as $element) {
      if (!$first) {
        $inList .= ", ";
      }
      $inList .= $this->quote($element);
      $first = false;
    }
    $inList .= ")";
    return $inList;
  }

  /**
   * Pretty prints a result queried from database in ASCII
   */
  static public function dumpTable(/* PDOStatement or array */ $stmt) {
    $buffer = "";
    $printColumn = true;
    foreach ($stmt as $row) {
      if ($printColumn) {
        foreach ($row as $column) {
          $buffer .= str_pad("", 20, "-")."-+-";
        }
        $buffer .= "\n";
        foreach ($row as $key => $column) {
          $buffer .= str_pad(substr($key, 0, 20), 20)." | ";
        }
        $buffer .= "\n";
        foreach ($row as $column) {
          $buffer .= str_pad("", 20, "-")."-+-";
        }
        $buffer .= "\n";
        $printColumn = false;
      }
      foreach ($row as $column) {
        $buffer .= str_pad(substr($column, 0, 20), 20)." | ";
      }
      $buffer .= "\n";
    }
    if (isset($row)) {
      foreach ($row as $column) {
        $buffer .= str_pad("", 20, "-")."-+-";
      }
      $buffer .= "\n";
    }
    return $buffer;
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
      $this->logger->logBefore($this->sql, $input_parameters);
      if (count($input_parameters) > 0) {
        $result = parent::execute($input_parameters);
      } else {
        $result = parent::execute();
      }
      $this->logger->logAfter($this->sql, microtime(true) - $t, $input_parameters);
      return $result;
    }
    if (count($input_parameters) > 0) {
      return parent::execute($input_parameters);
    } else {
      return parent::execute();
    }
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
      case 'pgsql':
        $sql = "SELECT CONCAT(table_schema,'.',table_name) AS name FROM information_schema.tables
          WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog','information_schema')";
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
      case 'pgsql':
        /** method for finding primary key see http://wiki.postgresql.org/wiki/Retrieve_primary_key_columns */
        $result = $this->connection->pexecute(
          "SELECT attname as column_name,
                  format_type(pg_attribute.atttypid, pg_attribute.atttypmod) as data_type,
                  pg_index.indisprimary,
                  pg_attrdef.adsrc as column_default
             FROM pg_attribute
        LEFT JOIN pg_index
               ON pg_index.indrelid = pg_attribute.attrelid
              AND pg_attribute.attnum = any (pg_index.indkey)
              AND pg_index.indisprimary = true
        LEFT JOIN pg_attrdef
               ON pg_attrdef.adrelid = pg_attribute.attrelid
              AND pg_attrdef.adnum = pg_attribute.attnum
            WHERE attrelid = :tableName::regclass
              AND attnum > 0
              AND attisdropped = false", array(":tableName" => $table));
        $result->setFetchMode(PDO::FETCH_ASSOC);
        $meta = array();
        foreach ($result as $row) {
          $meta[$row['column_name']] = array(
            'pk' => $row['indisprimary'] == 't',
            'type' => $row['data_type'],
            'blob' => preg_match('/(text|bytea)/', $row['data_type']),
            'default' => $row['column_default']
          );
        }
        return $meta;
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
      case 'pgsql':
        if (strpos(".", $table) === false) {
          $table = $this->lookupQualifiedTablename($table);
        }
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
        break;
      default:
        throw new pdoext_MetaNotSupportedException();
    }
  }
  /**
    * Returns a list of foreign keys that refer a table.
    */
  public function getReferencingKeys($table) {
    switch ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      case 'pgsql':
        if (strpos(".", $table) === false) {
          $table = $this->lookupQualifiedTablename($table);
        }
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

  protected function lookupQualifiedTablename($tableName) {
    $stmt = $this->connection->pexecute(
      "SELECT concat(nspname, '.', relname) FROM pg_class JOIN pg_namespace ON pg_class.relnamespace = pg_namespace.oid WHERE pg_class.oid = :tablename::regclass",
      array(":tablename" => $tableName)
    );
    return $stmt->fetchColumn();
  }

  /**
   * @internal
   */
  protected function loadKeys() {
    if (!isset($this->keys)) {
      switch ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
        case 'mysql':
          $sql = "SELECT TABLE_NAME AS `table_name`, COLUMN_NAME AS `column_name`, REFERENCED_COLUMN_NAME AS `referenced_column_name`, REFERENCED_TABLE_NAME AS `referenced_table_name`
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND REFERENCED_TABLE_SCHEMA = DATABASE()";
          break;
        case 'pgsql':
          $sql = "SELECT conname as foreign_key_name,
                         concat(pg_namespace_con.nspname, '.', pg_class_con.relname) as table_name,
                         conatt.attname as column_name,
                         concat(pg_namespace_con.nspname, '.', pg_class.relname) as referenced_table_name,
                         pg_attribute.attname as referenced_column_name
                    FROM pg_constraint
              CROSS JOIN generate_series(1, array_length(conkey,1))
                    JOIN pg_class as pg_class_con
                      ON pg_class_con.oid = pg_constraint.conrelid
                    JOIN pg_namespace AS pg_namespace_con
                      ON pg_namespace_con.oid = pg_class_con.relnamespace
                    JOIN pg_class
                      ON pg_class.oid = pg_constraint.confrelid
                    JOIN pg_namespace AS pg_namespace
                      ON pg_namespace.oid = pg_class.relnamespace
                    JOIN pg_attribute
                      ON pg_attribute.attrelid = pg_constraint.confrelid
                     AND pg_attribute.attnum = pg_constraint.confkey[generate_series]
                    JOIN pg_attribute conatt
                      ON conatt.attrelid = pg_constraint.conrelid
                     AND conatt.attnum = pg_constraint.conkey[generate_series]
                   WHERE contype = 'f'
                     AND pg_attribute.attisdropped = false
                ORDER BY 1, 2, 3, 4";
          break;
        default:
          throw new pdoext_MetaNotSupportedException();
      }
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
