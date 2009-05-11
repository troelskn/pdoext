<?php
require_once 'simpletest.inc.php';
$files = array();
chdir(dirname(__FILE__));
foreach (glob("*.test.php") as $file) {
  if ($file != "all.test.php") {
    include $file;
    $files[] = realpath($file);
  }
}
simpletest_autorun($files);
