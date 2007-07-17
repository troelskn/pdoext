<?php
  /**
   * Simpletest bootstrap
   * Just include and put simpletest_autorun(__FILE__) at the end of the test-file
   */
require_once 'simpletest/unit_tester.php';
require_once 'simpletest/reporter.php';
require_once 'simpletest/mock_objects.php';

function simpletest_autorun($filename) {
  if (realpath($_SERVER['SCRIPT_FILENAME']) == realpath($filename)) {
//     set_time_limit(0);
    error_reporting(E_ALL);
    $test = new GroupTest("Automatic Test Runner");
    $testKlass = new ReflectionClass("SimpleTestCase");
    foreach (get_declared_classes() as $classname) {
      $klass = new ReflectionClass($classname);
      if ($klass->isSubclassOf($testKlass) && $klass->getFileName() == $filename) {
        $test->addTestCase(new $classname());
      }
    }
    if (SimpleReporter::inCli()) {
      $result = $test->run(new SelectiveReporter(new TextReporter(), @$argv[1], @$argv[2]));
      exit($result ? 0 : 1);
    }
    $test->run(new SelectiveReporter(new HtmlReporter(), @$_GET['c'], @$_GET['t']));
  }
}
