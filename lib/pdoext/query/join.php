<?php
class pdoext_query_Join extends pdoext_query_Criteria
{
  protected $type;
  protected $table;
  protected $alias;

  public function __construct($table, $type = 'JOIN', $alias = NULL) {
    parent::__construct('AND');
    $this->table = $table;
    $this->type = " ".trim($type)." ";
    $this->alias = $alias;
  }

  public function toSQL(PdoExt $connection) {
    if (count($this->criteria) > 0) {
      $_on = " ON ".parent::toSQL($connection);
    } else {
      $_on = "";
    }
    if ($this->alias) {
      return $this->type.$connection->quoteName($this->table)." AS ".$connection->quoteName($this->alias).$_on;
    }
    return $this->type.$connection->quoteName($this->table).$_on;
  }
}
?>