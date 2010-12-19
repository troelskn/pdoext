<?php
/**
  * A few extensions to the core PDO class.
  * Adds a few helpers and patches differences between sqlite and mysql.
  * @license LGPL
  */
class pdoext_Connection extends PDO {
  protected $logTarget = null;
  protected $slowLogOffset = null;
  protected $inTransaction = false;

  protected $nameOpening;
  protected $nameClosing;

  public function __construct($dsn, $user = null, $password = null, $failSafe = true) {
    try {
       parent::__construct($dsn, $user, $password);
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
        $this->nameOpening = $this->nameClosing = '`';
        break;

      case 'mssql':
        $this->nameOpening = '[';
        $this->nameClosing = ']';
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
        $this->nameOpening = $this->nameClosing = '"';
        break;
    }
  }

  public function supportsSqlCalcFoundRows() {
    return $this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  }

  public function setLogTarget($logTarget = 'php://stdout', $slowLogOffset = null) {
    $this->logTarget = $logTarget;
    $this->slowLogOffset = $slowLogOffset;
    if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
      $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('pdoext_LoggingStatement'));
    }
  }

  function log($sql, $t, $params = null) {
    if ($this->logTarget) {
      if ($this->slowLogOffset && $t < $this->slowLogOffset) {
        return;
      }
      $more = $params ? ("\n---\n" . var_export($params, true)) : "";
      error_log("[" . date("Y-m-d H:i:s") . "] [" . number_format($t / 1000, 4) . " s] from " . pdoext_find_caller() . "\n---\n" . $sql . $more . "\n---\n", 3, $this->logTarget);
    }
  }

  public function exec($statement) {
    $sql = $statement instanceOf pdoext_Query ? $statement->toSql($this) : $statement;
    $t = microtime(true);
    $result = parent::exec($sql);
    $this->log($sql, microtime(true) - $t);
    return $result;
  }

  public function query($statement) {
    $sql = $statement instanceOf pdoext_Query ? $statement->toSql($this) : $statement;
    $t = microtime(true);
    $result = parent::query($sql);
    $this->log($sql, microtime(true) - $t);
    return $result;
  }

  function __sqlite_group_concat_step($context, $idx, $string, $separator = ",") {
    return ($context) ? ($context . $separator . $string) : $string;
  }

  function __sqlite_group_concat_finalize($context) {
    return $context;
  }

  /**
    * Workaround for bug in PDO:
    *   http://bugs.php.net/bug.php?id=41698
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

  public function prepare($sql, $options = array()) {
    $stmt = parent::prepare($sql, $options);
    if ($this->logTarget) {
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
  public function inTransaction() {
    return !! $this->inTransaction;
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
    * Like PDO::beginTransaction(), but throws an exception, if a transaction is already started.
    */
  public function beginTransaction() {
    if ($this->inTransaction) {
      throw new pdoext_AlreadyInTransactionException(sprintf("Already in transaction. Tansaction started at line %s in file %s", $this->inTransaction[0], $this->inTransaction[1]));
    }
    $result = parent::beginTransaction();
    $stack = debug_backtrace();
    $this->inTransaction = array($stack[0]['file'], $stack[0]['line']);
    return $result;
  }

  public function rollback() {
    $result = parent::rollback();
    $this->inTransaction = false;
    return $result;
  }

  public function commit() {
    $result = parent::commit();
    $this->inTransaction = false;
    return $result;
  }

  /**
    * Escapes names (tables, columns etc.)
    */
  public function quoteName($name) {
    $names = array();
    foreach (explode(".", $name) as $name) {
      $names[] = $this->nameOpening
        .str_replace($this->nameClosing, $this->nameClosing.$this->nameClosing, $name)
        .$this->nameClosing;
    }
    return implode(".", $names);
  }

  /**
    * Returns reflection information about a table.
    */
  public function getTableMeta($table) {
    switch ($this->getAttribute(PDO::ATTR_DRIVER_NAME)) {
      case 'mysql':
        $result = $this->query("SHOW COLUMNS FROM ".$this->quoteName($table));
        $result->setFetchMode(PDO::FETCH_ASSOC);
        $meta = array();
        foreach ($result as $row) {
          $meta[$row['Field']] = array(
            'pk' => $row['Key'] == 'PRI',
            'type' => $row['Type'],
            'blob' => preg_match('/(TEXT|BLOB)/', $row['Type']),
          );
        }
        return $meta;
      case 'sqlite':
        $result = $this->query("PRAGMA table_info(".$this->quoteName($table).")");
        $result->setFetchMode(PDO::FETCH_ASSOC);
        $meta = array();
        foreach ($result as $row) {
          $meta[$row['name']] = array(
            'pk' => $row['pk'] == '1',
            'type' => $row['type'],
            'blob' => preg_match('/(TEXT|BLOB)/', $row['type']),
          );
        }
        return $meta;
      default:
        throw new pdoext_MetaNotSupportedException();
    }
  }
}

function pdoext_find_caller($skip = '/^pdoext_/i') {
  foreach (debug_backtrace() as $frame) {
    if (isset($frame['object'])) {
      $name = get_class($frame['object']);
    } elseif (isset($frame['class'])) {
      $name = $frame['class'];
    } else {
      $name = '';
    }
    if (isset($frame['function'])) {
      $name = ($name ? "$name#" : $name) . $frame['function'];
    }
    if (!preg_match($skip, $name)) {
      if (isset($frame['file'], $frame['line'])) {
        return $name . " in [" . $frame['file'] . " " . $frame['line'] . "]";
      }
      return $name;
    }
  }
  return '{unknown}';
}

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

class pdoext_NoTransactionStartedException extends Exception {
  function __construct($message = "No transaction started", $code = 0) {
    parent::__construct($message, $code);
  }
}

class pdoext_AlreadyInTransactionException extends Exception {}

class pdoext_MetaNotSupportedException extends Exception {
  function __construct($message = "Meta querying not available for driver type", $code = 0) {
    parent::__construct($message, $code);
  }
}
