<?php
/**
  */
class pdoext_query_Criterion implements pdoext_query_ICriterion
{
  /** Compare literal to literal */
  const QUOTE_NONE = 0;
  /** Compare field to constant value */
  const QUOTE_VALUE = 1;
  /** Compare field to field */
  const QUOTE_FIELD = 2;
  /** Compare field to literal */
  const QUOTE_LITERAL = 4;

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

  public function toSQL(pdoext_Connection $connection) {
    $sql = "";
    $comparator = trim($this->comparator);
    if ($this->quoteType > 0) {
      $sql .= $connection->quoteName($this->column);
    } else {
      $sql .= $this->column;
    }

    if ($this->quoteType == self::QUOTE_FIELD) {
      return "$sql $comparator ".$connection->quoteName($this->value);
    } elseif ($this->quoteType == self::QUOTE_VALUE) {
      if ($comparator == "=") {
        if (is_null($this->value)) {
          return $sql." IS NULL";
        } elseif (is_array($this->value)) {
          $sql .= " IN ";
        } else {
          $sql .= " $comparator";
        }
      } elseif ($comparator == "!=") {
        if (is_null($this->value)) {
          return $sql." IS NOT NULL";
        } elseif (is_array($this->value)) {
          $sql .= " NOT IN ";
        } else {
          $sql .= " $comparator";
        }
      } elseif ($comparator == "LIKE") {
        return "$sql $comparator ".$connection->escapeLike($this->value);
      } else {
        $sql .= " $comparator";
      }
      if (is_array($this->value)) {
        $a = Array();
        foreach ($this->value as $this->value) {
          $a[] = $connection->quote($this->value);
        }
        return $sql."(".implode(',', $a).")";
      }
      return "$sql ".$connection->quote($this->value);
    } elseif ($this->quoteType == self::QUOTE_LITERAL && is_array($this->value)) {
      if ($comparator == "=") {
        $sql .= " IN ";
      } elseif ($comparator == "!") {
        $sql .= " NOT IN ";
      } else {
        $sql .= " $comparator ";
      }
      return $sql."(".implode(',', $this->value).")";
    }
    return "$sql $comparator ".$this->value;
  }
}
