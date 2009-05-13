<?php
function pdoext_string_indent($s, $break_before_multiple_lines = false) {
  if ($break_before_multiple_lines && is_int(strpos($s, "\n"))) {
    return "\n  " . str_replace("\n", "\n  ", $s);
  }
  return "  " . str_replace("\n", "\n  ", $s);
}

class pdoext_DummyConnection {
  function quoteName($name) {
    return "`$name`";
  }
  function quote($value) {
    return "'$value'";
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
