<?php

/**
 * Parser for JUnit, NUnit, etc results format
 */
final class ArcanistXUnitTestResultParser extends ArcanistTestResultParser {

  public function __construct() {
    // Disable coverage to prevent BC break.
    $this->enableCoverage = false;
  }

  /**
   * Parse test results from provided input and return an array
   * of @{class:ArcanistUnitTestResult}.
   *
   * @param string $test_results String containing test results
   *
   * @return array ArcanistUnitTestResult
   */
  public function parseTestResults($path, $test_results = null) {
    // To avoid BC break.
    if ($test_results === null) {
      $test_results = $path;
      $path = null;
    }

    if (!strlen($test_results)) {
      throw new Exception(
        pht(
          '%s argument to %s must not be empty',
          'test_results',
          'parseTestResults()'));
    }

    // xunit xsd: https://gist.github.com/959290
    $xunit_dom = new DOMDocument();
    $load_success = @$xunit_dom->loadXML($test_results);

    if (!$load_success) {
      $input_start = id(new PhutilUTF8StringTruncator())
        ->setMaximumGlyphs(150)
        ->truncateString($test_results);
      throw new Exception(
        sprintf(
          "%s\n\n%s",
          pht('Failed to load XUnit report; Input starts with:'),
          $input_start));
    }

    // coverage is for all testcases in the executed $path
    $coverage = array();
    if ($this->enableCoverage !== false) {
      $coverage = $this->readCoverage();
    }

    $results = array();
    $testcases = $xunit_dom->getElementsByTagName('testcase');
    foreach ($testcases as $testcase) {
      $classname = $testcase->getAttribute('classname');
      $name = $testcase->getAttribute('name');
      $time = $testcase->getAttribute('time');

      $status = ArcanistUnitTestResult::RESULT_PASS;
      $user_data = '';

      // A skipped test is a test which was ignored using framework
      // mechanisms (e.g. @skip decorator)
      $skipped = $testcase->getElementsByTagName('skipped');
      if ($skipped->length > 0) {
        $status = ArcanistUnitTestResult::RESULT_SKIP;
        $messages = array();
        for ($ii = 0; $ii < $skipped->length; $ii++) {
          $messages[] = trim($skipped->item($ii)->nodeValue, " \n");
        }

        $user_data .= implode("\n", $messages);
      }

      // Failure is a test which the code has explicitly failed by using
      // the mechanisms for that purpose. e.g., via an assertEquals
      $failures = $testcase->getElementsByTagName('failure');
      if ($failures->length > 0) {
        $status = ArcanistUnitTestResult::RESULT_FAIL;
        $messages = array();
        for ($ii = 0; $ii < $failures->length; $ii++) {
          $messages[] = trim($failures->item($ii)->nodeValue, " \n");
        }

        $user_data .= implode("\n", $messages)."\n";
      }

      // An errored test is one that had an unanticipated problem. e.g., an
      // unchecked throwable, or a problem with an implementation of the test.
      $errors = $testcase->getElementsByTagName('error');
      if ($errors->length > 0) {
        $status = ArcanistUnitTestResult::RESULT_BROKEN;
        $messages = array();
        for ($ii = 0; $ii < $errors->length; $ii++) {
          $messages[] = trim($errors->item($ii)->nodeValue, " \n");
        }

        $user_data .= implode("\n", $messages)."\n";
      }

      $result = new ArcanistUnitTestResult();
      $result->setName($classname.'.'.$name);
      $result->setResult($status);
      $result->setDuration((float)$time);
      $result->setCoverage($coverage);
      $result->setUserData($user_data);

      $results[] = $result;
    }

    return $results;
  }

  /**
   * Read the coverage from phpunit generated clover report
   *
   * @return array
   */
  private function readCoverage() {
    $test_results = Filesystem::readFile($this->coverageFile);
    if (empty($test_results)) {
      return array();
    }

    $coverage_dom = new DOMDocument();
    $coverage_dom->loadXML($test_results);

    $reports = array();
    $files = $coverage_dom->getElementsByTagName('file');

    foreach ($files as $file) {
      $class_path = $file->getAttribute('name');
      if (empty($this->affectedTests[$class_path])) {
        continue;
      }
      $test_path = $this->affectedTests[$file->getAttribute('name')];
      // get total line count in file
      $line_count = count(file($class_path));

      $coverage = '';
      $any_line_covered = false;
      $start_line = 1;
      $lines = $file->getElementsByTagName('line');

      $coverage = str_repeat('N', $line_count);
      foreach ($lines as $line) {
        if ($line->getAttribute('type') != 'stmt') {
          continue;
        }
        if ((int)$line->getAttribute('count') > 0) {
          $is_covered = 'C';
          $any_line_covered = true;
        } else {
          $is_covered = 'U';
        }
        $line_no = (int)$line->getAttribute('num');
        $coverage[$line_no - 1] = $is_covered;
      }

      // Sometimes the Clover coverage gives false positives on uncovered lines
      // when the file wasn't actually part of the test. This filters out files
      // with no coverage which helps give more accurate overall results.
      if ($any_line_covered) {
        $len = strlen($this->projectRoot.DIRECTORY_SEPARATOR);
        $class_path = substr($class_path, $len);
        $reports[$class_path] = $coverage;
      }
    }

    return $reports;
  }

}
