<?php

/**
 * Tests for @{class:PhpunitTestEngine}.
 */
final class PhpunitTestEngineTestCase extends PhutilTestCase {

  public function testSearchLocations() {
    $path = '/root/path/to/some/file/X.php';

    $this->assertEqual(
      array(
        '/root/path/to/some/file/',
        '/root/path/to/some/file/tests/',
        '/root/path/to/some/file/tests/Unit/',
        '/root/path/to/some/file/tests/Functional/',
        '/root/path/to/some/file/tests/UI/',
        '/root/path/to/some/file/Tests/',
        '/root/path/to/some/tests/',
        '/root/path/to/some/tests/Unit/',
        '/root/path/to/some/tests/Functional/',
        '/root/path/to/some/tests/UI/',
        '/root/path/to/some/Tests/',
        '/root/path/to/tests/',
        '/root/path/to/tests/Unit/',
        '/root/path/to/tests/Functional/',
        '/root/path/to/tests/UI/',
        '/root/path/to/Tests/',
        '/root/path/tests/',
        '/root/path/tests/Unit/',
        '/root/path/tests/Functional/',
        '/root/path/tests/UI/',
        '/root/path/Tests/',
        '/root/tests/',
        '/root/tests/Unit/',
        '/root/tests/Functional/',
        '/root/tests/UI/',
        '/root/Tests/',
        '/root/path/to/tests/file/',
        '/root/path/to/tests/Unit/file/',
        '/root/path/to/tests/Functional/file/',
        '/root/path/to/tests/UI/file/',
        '/root/path/to/Tests/file/',
        '/root/path/tests/some/file/',
        '/root/path/tests/Unit/some/file/',
        '/root/path/tests/Functional/some/file/',
        '/root/path/tests/UI/some/file/',
        '/root/path/Tests/some/file/',
        '/root/tests/to/some/file/',
        '/root/tests/Unit/to/some/file/',
        '/root/tests/Functional/to/some/file/',
        '/root/tests/UI/to/some/file/',
        '/root/Tests/to/some/file/',
        '/root/path/to/some/tests/file/',
        '/root/path/to/some/tests/Unit/file/',
        '/root/path/to/some/tests/Functional/file/',
        '/root/path/to/some/tests/UI/file/',
        '/root/path/to/some/Tests/file/',
        '/root/path/to/tests/some/file/',
        '/root/path/to/tests/Unit/some/file/',
        '/root/path/to/tests/Functional/some/file/',
        '/root/path/to/tests/UI/some/file/',
        '/root/path/to/Tests/some/file/',
        '/root/path/tests/to/some/file/',
        '/root/path/tests/Unit/to/some/file/',
        '/root/path/tests/Functional/to/some/file/',
        '/root/path/tests/UI/to/some/file/',
        '/root/path/Tests/to/some/file/',
        '/root/tests/path/to/some/file/',
        '/root/tests/Unit/path/to/some/file/',
        '/root/tests/Functional/path/to/some/file/',
        '/root/tests/UI/path/to/some/file/',
        '/root/Tests/path/to/some/file/',
      ),
      PhpunitTestEngine::getSearchLocationsForTests($path, '/root'));
  }

}
