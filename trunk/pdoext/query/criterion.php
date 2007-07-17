<?php
/**
  */
class pdoext_query_Criterion implements pdoext_query_ICriterion
{
  const QUOTE_NONE = 0;
  const QUOTE_VALUE = 1;
  const QUOTE_FIELD = 2;

  protected $column;
  protected $value;

  protected $comparator;
  protected $quoteType;

  public function __construct($column, $value, $comparator = ' = ', $quoteType = self::QUOTE_VALUE) {
    $this->column = $column;
    $this->value = $value;
    $this->comparator = " ".trim($comparator)." ";
    $this->quoteType = $quoteType;
  }

  public function toSQL(PdoExt $connection) {
    $sql = "";
    $comparator = trim($this->comparator);
    if ($this->quoteType > 0) {
      $sql .= $connection->quoteName($this->column);
    } else {
      $sql .= $this->column;
    }

    if ($this->quoteType == self::QUOTE_FIELD) {
      return "$sql $comparator ".$connection->quoteName($this->value);
    } else if ($this->quoteType == self::QUOTE_VALUE) {
      if ($comparator == "=") {
        if (is_null($this->value)) {
          return $sql." IS NULL";
        } else if (is_array($this->value)) {
          $sql .= " IN ";
        } else {
          $sql .= $comparator;
        }
      } else if ($comparator == "!=") {
        if (is_null($this->value)) {
          return $sql." IS NOT NULL";
        } else if (is_array($this->value)) {
          $sql .= " NOT IN ";
        } else {
          $sql .= $comparator;
        }
      } else if ($comparator == "LIKE") {
        return "$sql $comparator ".$connection->escapeLike($this->value);
      } else {
        $sql .= $comparator;
      }
      if (is_array($this->value)) {
        $a = Array();
        foreach ($this->value as $this->value) {
          $a[] = $connection->quote($this->value);
        }
        return $sql."(".implode(',', $a).")";
      }
      return "$sql ".$connection->quote($this->value);
    }
    return "$sql $comparator ".$this->value;
  }
}
?>