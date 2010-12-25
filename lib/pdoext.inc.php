<?php
function pdoext_string_indent($s, $break_before_multiple_lines = false) {
  if ($break_before_multiple_lines && is_int(strpos($s, "\n"))) {
    return "\n  " . str_replace("\n", "\n  ", $s);
  }
  return "  " . str_replace("\n", "\n  ", $s);
}

function pdoext_find_caller($skip = '/^pdoext_/i') {
  $last = null;
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
      if (isset($last['file'], $last['line'])) {
        return $name . " in [" . $last['file'] . " " . $last['line'] . "]";
      }
      return $name;
    }
    $last = $frame;
  }
  return '{unknown}';
}

class pdoext_DummyConnection {
  function quoteName($name) {
    return "`$name`";
  }
  function quote($value) {
    return "'$value'";
  }
  function getInformationSchema() {
    return new pdoext_DummyInformationSchema();
  }
}

class pdoext_DummyInformationSchema {
  public function getTables() {
    return array();
  }
  public function getColumns($table) {
    return array();
  }
  public function getForeignKeys($table) {
    return array();
  }
  public function getReferencingKeys($table) {
    return array();
  }
}

function pdoext_query($tablename, $alias = null) {
  return new pdoext_Query($tablename, $alias);
}

function pdoext_field($name) {
  return new pdoext_query_Field($name);
}

function pdoext_value($value) {
  return new pdoext_query_Value($value);
}

function pdoext_literal($sql) {
  return new pdoext_query_Literal($sql);
}

function pdoext_db() {
  if (!isset($GLOBALS['pdoext_connection']['instance'])) {
    $GLOBALS['pdoext_connection']['instance'] = call_user_func($GLOBALS['pdoext_connection']['callback']);
  }
  return $GLOBALS['pdoext_connection']['instance'];
}