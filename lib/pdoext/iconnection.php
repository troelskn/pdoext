<?php
/**
  * A few extensions to the core PDO class.
  * Adds a few helpers and patches differences between sqlite and mysql.
  * @license LGPL
  */
interface pdoext_iConnection {

  // Inherited from PDO
  // Since these methods are implemented internally, we can't declare their signature in an interface ...
  public function prepare();
  public function beginTransaction();
  public function commit();
  public function rollback();
  public function setAttribute();
  public function exec();
  public function query();
  public function lastInsertId();
  public function errorCode();
  public function errorInfo();
  public function getAttribute();
  public function quote();

  // New in pdoext
  public function pexecute($sql, $input_params = null);
  public function inTransaction();
  public function assertTransaction();
  public function quoteName($name);
  public function escapeLike($value, $wildcart = "*");
  public function getTableMeta($table);
}