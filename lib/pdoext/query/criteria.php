<?php
class pdoext_query_Criteria implements pdoext_query_ICriterion
{
  protected $conjunction;
  protected $criteria = Array();

  public function __construct($conjunction = 'OR') {
    $this->conjunction = " ".trim($conjunction)." ";
  }

  public function addCriterion($column /* or instance of pdoext_query_ICriterion */, $value = NULL, $comparator = "=", $quoteType = pdoext_query_Criterion::QUOTE_VALUE) {
    if ($column instanceOf pdoext_query_ICriterion) {
      return $this->addCriterionObject($column);
    } else {
      return $this->addCriterionObject(new pdoext_query_Criterion($column, $value, $comparator, $quoteType));
    }
  }

  public function addConstraint($column /* or instance of pdoext_query_ICriterion */, $value = NULL, $comparator = "=", $quoteType = pdoext_query_Criterion::QUOTE_FIELD) {
    if ($column instanceOf pdoext_query_ICriterion) {
      $this->addCriterionObject($column);
    } else {
      $this->addCriterionObject(new pdoext_query_Criterion($column, $value, $comparator, $quoteType));
    }
  }

  protected function addCriterionObject(pdoext_query_ICriterion $criterion) {
    $this->criteria[] = $criterion;
    return $criterion;
  }

  public function toSQL(pdoext_Connection $connection = null) {
    if (count($this->criteria) == 0) {
      return "";
    }
    $criteria = Array();
    foreach ($this->criteria as $criterion) {
      $criteria[] = $criterion->toSQL($connection);
    }
    return "(".implode($this->conjunction, $criteria).")";
  }
}
