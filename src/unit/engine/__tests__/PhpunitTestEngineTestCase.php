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
        '/root/path/to/some/file/Tests/',
        '/root/path/to/some/tests/',
        '/root/path/to/some/Tests/',
        '/root/path/to/tests/',
        '/root/path/to/Tests/',
        '/root/path/tests/',
        '/root/path/Tests/',
        '/root/tests/',
        '/root/Tests/',
        '/root/path/to/tests/file/',
        '/root/path/to/Tests/file/',
        '/root/path/tests/some/file/',
        '/root/path/Tests/some/file/',
        '/root/tests/to/some/file/',
        '/root/Tests/to/some/file/',
        '/root/path/to/some/tests/file/',
        '/root/path/to/some/Tests/file/',
        '/root/path/to/tests/some/file/',
        '/root/path/to/Tests/some/file/',
        '/root/path/tests/to/some/file/',
        '/root/path/Tests/to/some/file/',
        '/root/tests/path/to/some/file/',
        '/root/Tests/path/to/some/file/',
      ),
      PhpunitTestEngine::getSearchLocationsForTests($path, '/root'));
  }

  public function testParseTestSuites() {
    $config = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
  <testsuites>
    <testsuite name="Default">
      <directory>../../tests/Unit</directory>
      <directory>../../modules/*/tests/Unit</directory>
    </testsuite>
    <testsuite name="Unit">
      <directory>../../tests/Unit</directory>
      <directory suffix=".phpt" prefix="check_">/abs/tests/Other</directory>
      <file>./single/SomeTest.php</file>
      <exclude>../../tests/Unit/fixtures</exclude>
    </testsuite>
  </testsuites>
</phpunit>
XML;

    $this->assertEqual(
      array(
        'directories' => array(
          array(
            'path' => '/root/tests/Unit',
            'prefix' => '',
            'suffix' => 'Test.php',
          ),
          array(
            'path' => '/root/modules/*/tests/Unit',
            'prefix' => '',
            'suffix' => 'Test.php',
          ),
          array(
            'path' => '/abs/tests/Other',
            'prefix' => 'check_',
            'suffix' => '.phpt',
          ),
        ),
        'files' => array('/root/build/config/single/SomeTest.php'),
        'excludes' => array('/root/tests/Unit/fixtures'),
      ),
      PhpunitTestEngine::parseTestSuites($config, '/root/build/config'));

    $this->assertEqual(
      array(
        'directories' => array(),
        'files' => array(),
        'excludes' => array(),
      ),
      PhpunitTestEngine::parseTestSuites('<phpunit/>', '/root'),
      pht('Configuration without test suites.'));
  }

  public function testTestFileNames() {
    $this->assertEqual(
      array('file_name.php', 'file_nameTest.php', 'ClassNameTest.php'),
      PhpunitTestEngine::getTestFileNames(
        'file_name.php',
        array('ClassName')),
      pht('Legacy file name differs from class name.'));

    $this->assertEqual(
      array('ClassName.php', 'ClassNameTest.php'),
      PhpunitTestEngine::getTestFileNames(
        'ClassName.php',
        array('ClassName')),
      pht('File name matches class name.'));

    $this->assertEqual(
      array('file.php', 'fileTest.php', 'ATest.php', 'BTest.php'),
      PhpunitTestEngine::getTestFileNames('file.php', array('A', 'B')),
      pht('Several classes in one file.'));

    $this->assertEqual(
      array('file.php', 'fileTest.php'),
      PhpunitTestEngine::getTestFileNames('file.php', array()),
      pht('No classes in a file.'));
  }

  public function testDeclaredClassNames() {
    $source = <<<'PHP'
<?php

/**
 * Docblock mentioning class Fake.
 */
abstract class FirstClass extends Base implements SomeInterface
{
  public function run()
  {
    $name = FirstClass::class;
    $object = new class {};
    $text = 'class AlsoFake';
    // class CommentFake
  }
}

final class SecondClass {}

interface SomeInterface {}

trait SomeTrait {}

	class TabIndentedClass {}

final readonly class ModifiedClass {}
PHP;

    $this->assertEqual(
      array(
        'FirstClass',
        'SecondClass',
        'SomeInterface',
        'SomeTrait',
        'TabIndentedClass',
        'ModifiedClass',
      ),
      PhpunitTestEngine::getDeclaredClassNames($source));

    $this->assertEqual(
      array(),
      PhpunitTestEngine::getDeclaredClassNames('<?php function f() {}'),
      pht('File without classes.'));
  }

}
