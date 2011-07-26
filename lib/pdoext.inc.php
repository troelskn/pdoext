<?php
require_once 'pdoext/connection.inc.php';
require_once 'pdoext/query.inc.php';
require_once 'pdoext/tablegateway.inc.php';

/**
 * Indents a string by two spaces, even if the string has linebreaks.
 */
function pdoext_string_indent($s, $break_before_multiple_lines = false) {
  if ($break_before_multiple_lines && is_int(strpos($s, "\n"))) {
    return "\n  " . str_replace("\n", "\n  ", $s);
  }
  return "  " . str_replace("\n", "\n  ", $s);
}

/**
 * Finds the first caller on the callstack that doesn't match the skip pattern.
 */
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

/**
 * Performs a fetch assoc on a statement, but will raise an exception if the result is ambiguous.
 */
function pdoext_fetch_assoc_safe($resultset) {
  $row = $resultset->fetch(PDO::FETCH_ASSOC);
  if ($row === false) {
    return false;
  }
  $expected = $resultset->columnCount();
  if (count($row) != $expected) {
    throw new Exception("Unexpected number of columns returned. Ambiguous resultset caused by join?");
  }
  return $row;
}

/**
 * Transforms CamelCase to underscore_case
 */
function pdoext_underscore($cameled) {
  return implode(
    '_',
    array_map(
      'strtolower',
      preg_split('/([A-Z]{1}[^A-Z]*)/', $cameled, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)));
}

class pdoext_DummyConnection {
  function quote($name) {
    return $name;
  }
  function quoteName($name) {
    return $name;
  }
  function supportsSqlCalcFoundRows() {
    return true;
  }
}
/**
 * Creates a new query object.
 * @returns pdoext_Query
 */
function pdoext_query($tablename, $alias = null) {
  return new pdoext_Query($tablename, $alias);
}

/**
 * Creates a field wrapper, used in queries
 * @returns pdoext_query_Field
 */
function pdoext_field($name) {
  return new pdoext_query_Field($name);
}

/**
 * Creates a value wrapper, used in queries
 * @returns pdoext_query_Value
 */
function pdoext_value($value) {
  return new pdoext_query_Value($value);
}

/**
 * Creates a literal wrapper, used in queries
 * @returns pdoext_query_Literal
 */
function pdoext_literal($sql) {
  return new pdoext_query_Literal($sql);
}
