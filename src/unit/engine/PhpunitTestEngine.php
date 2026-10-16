<?php

/**
 * PHPUnit wrapper.
 */
final class PhpunitTestEngine extends ArcanistUnitTestEngine {

  private $configFile;
  private $phpunitBinary = 'phpunit';
  private $affectedTests;
  private $projectRoot;
  private $testFileIndex;

  public function run() {
    $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();
    $this->prepareConfigFile();
    $this->collectAffectedTests();

    // Use single process for coverage collection to speed up things.
    if ($this->getEnableCoverage() !== false) {
      return $this->runTestsSequentially();
    }

    return $this->runTestsInParallel();
  }

  /**
   * Collects affected tests.
   *
   * @return void
   * @throws ArcanistNoEffectException When no tests were found.
   */
  private function collectAffectedTests() {
    $this->affectedTests = array();
    foreach ($this->getPaths() as $path) {

      $path = Filesystem::resolvePath($path, $this->projectRoot);

      // TODO: add support for directories
      // Users can call phpunit on the directory themselves
      if (is_dir($path)) {
        continue;
      }

      // Not sure if it would make sense to go further if
      // it is not a .php file
      if (substr($path, -4) !== '.php') {
        continue;
      }

      if (substr($path, -8) === 'Test.php') {
        // Looks like a valid test file name.
        $this->affectedTests[$path] = $path;
        continue;
      }

      $tests = $this->findTestFiles($path);
      if ($tests) {
        $this->affectedTests[$path] = array_shift($tests);
        foreach ($tests as $test) {
          $this->affectedTests[$test] = $test;
        }
      }

    }

    $this->affectedTests = array_filter($this->affectedTests,
      array('Filesystem', 'pathExists'));

    if (empty($this->affectedTests)) {
      throw new ArcanistNoEffectException(pht('No tests to run.'));
    }
  }

  /**
   * Runs tests in parallel.
   *
   * @return ArcanistUnitTestResult[]
   */
  private function runTestsInParallel() {
    $futures = array();
    $tmpfiles = array();
    foreach ($this->affectedTests as $class_path => $test_path) {
      $json_tmp = new TempFile();
      $clover_tmp = null;
      $clover = null;
      if ($this->getEnableCoverage() !== false) {
        $clover_tmp = new TempFile();
        $clover = csprintf('--coverage-clover %s', $clover_tmp);
      }

      $config = $this->configFile ? csprintf('-c %s', $this->configFile) : null;

      $stderr = '-d display_errors=stderr';

      $futures[$test_path] = new ExecFuture('%C %C %C --log-junit %s %C %s',
        $this->phpunitBinary, $config, $stderr, $json_tmp, $clover, $test_path);

      // Required for xDebug 3.x.
      if ($this->getEnableCoverage() !== false) {
        $futures[$test_path]->setEnv(array('XDEBUG_MODE' => 'coverage'));
      }

      $tmpfiles[$test_path] = array(
        'json' => $json_tmp,
        'clover' => $clover_tmp,
      );
    }

    $results = array();
    $futures = id(new FutureIterator($futures))
      ->limit(4);
    foreach ($futures as $test => $future) {
      list($err, $stdout, $stderr) = $future->resolve();

      $results[] = $this->parseTestResults(
        $test,
        $tmpfiles[$test]['json'],
        $tmpfiles[$test]['clover'],
        $stderr);
    }

    return array_mergev($results);
  }

  /**
   * Runs tests sequentially.
   *
   * @return ArcanistUnitTestResult[]
   */
  private function runTestsSequentially() {
    $this->patchConfigFile();

    $json_tmp = new TempFile();
    $clover_tmp = null;
    $clover = null;

    if ($this->getEnableCoverage() !== false) {
      $clover_tmp = new TempFile();
      $clover = csprintf('--coverage-clover %s', $clover_tmp);
    }

    $stderr = '-d display_errors=stderr';

    $exec_future = new ExecFuture('%C -c %s %C --log-junit %s %C --testsuite arc',
      $this->phpunitBinary, $this->configFile, $stderr, $json_tmp, $clover);

    // Required for xDebug 3.x.
    if ($this->getEnableCoverage() !== false) {
      $exec_future->setEnv(array('XDEBUG_MODE' => 'coverage'));
    }

    list($err, $stdout, $stderr) = $exec_future->resolve();

    Filesystem::remove($this->configFile);

    return $this->parseTestResults(
      'arc',
      $json_tmp,
      $clover_tmp,
      $stderr);
  }

  /**
   * Parse test results from phpunit json report.
   *
   * @param string $path Path to test
   * @param string $json_tmp Path to phpunit json report
   * @param string $clover_tmp Path to phpunit clover report
   * @param string $stderr Data written to stderr
   *
   * @return array
   */
  private function parseTestResults($path, $json_tmp, $clover_tmp, $stderr) {
    $test_results = Filesystem::readFile($json_tmp);
    return id(new ArcanistXUnitTestResultParser())
      ->setEnableCoverage($this->getEnableCoverage())
      ->setProjectRoot($this->projectRoot)
      ->setCoverageFile($clover_tmp)
      ->setAffectedTests($this->affectedTests)
      ->setStderr($stderr)
      ->parseTestResults($path, $test_results);
  }


  /**
   * Search for test cases for a given file among test files of test suites
   * from the PHPUnit configuration file. Without test suites, search in a
   * large number of "reasonable" locations instead. See
   * @{method:getSearchLocationsForTests} for specifics.
   *
   * @param   string        PHP file to locate test cases for.
   * @return  list<string>  Paths to test cases.
   */
  private function findTestFiles($path) {
    $root = $this->projectRoot;
    $path = Filesystem::resolvePath($path, $root);

    $possible_files = self::getTestFileNames(
      basename($path),
      self::getDeclaredClassNames(Filesystem::readFile($path)));

    if ($this->testFileIndex !== null) {
      $found = array();
      foreach ($possible_files as $possible_file) {
        if (empty($this->testFileIndex[$possible_file])) {
          continue;
        }
        foreach ($this->testFileIndex[$possible_file] as $test_file) {
          if (0 == strcasecmp($test_file, $path)) {
            // Don't return the original file.
            continue;
          }
          $found[$test_file] = $test_file;
        }
      }

      return array_values($found);
    }

    $search = self::getSearchLocationsForTests($path, $root);

    $found = array();
    foreach ($search as $search_path) {
      foreach ($possible_files as $possible_file) {
        $full_path = $search_path.$possible_file;
        if (!Filesystem::pathExists($full_path)) {
          // If the file doesn't exist, it's clearly a miss.
          continue;
        }
        if (!Filesystem::isDescendant($full_path, $root)) {
          // Don't look above the project root.
          continue;
        }
        if (0 == strcasecmp(Filesystem::resolvePath($full_path), $path)) {
          // Don't return the original file.
          continue;
        }
        $found[Filesystem::resolvePath($full_path)] = $full_path;
      }
    }

    return array_values($found);
  }

  /**
   * Get test file names for a given file: named after the file itself
   * (e.g. "file_name.php" -> "file_nameTest.php") and after each class,
   * interface or trait declared in it (e.g. "ClassNameTest.php").
   *
   * @param   string        File name of the PHP file to locate test cases for.
   * @param   list<string>  Names of classes declared in that file.
   * @return  list<string>  Test file names.
   */
  public static function getTestFileNames($file, array $class_names) {
    $names = array(
      $file,
      substr($file, 0, -4).'Test.php',
    );

    foreach ($class_names as $class_name) {
      $names[] = $class_name.'Test.php';
    }

    return array_values(array_unique($names));
  }

  /**
   * Get names of classes, interfaces and traits declared in PHP code.
   *
   * @param   string        PHP code.
   * @return  list<string>  Declared class names.
   */
  public static function getDeclaredClassNames($source) {
    // Declarations start a line, unlike "Foo::class", "new class" or
    // mentions in comments and strings.
    $matches = null;
    preg_match_all(
      '/^[ \t]*(?:(?:abstract|final|readonly)[ \t]+)*'.
      '(?:class|interface|trait)[ \t]+([a-z_\x80-\xff][a-z0-9_\x80-\xff]*)/im',
      $source,
      $matches);

    return array_values(array_unique($matches[1]));
  }


  /**
   * Get places to look for PHP Unit tests that cover a given file. For some
   * file "/a/b/c/X.php", we look in the same directory:
   *
   *  /a/b/c/
   *
   * We then look in all parent directories for a directory named "tests/"
   * (or "Tests/"):
   *
   *  /a/b/c/tests/
   *  /a/b/tests/
   *  /a/tests/
   *  /tests/
   *
   * We also try to replace each directory component with "tests/":
   *
   *  /a/b/tests/
   *  /a/tests/c/
   *  /tests/b/c/
   *
   * We also try to add "tests/" at each directory level:
   *
   *  /a/b/c/tests/
   *  /a/b/tests/c/
   *  /a/tests/b/c/
   *  /tests/a/b/c/
   *
   * This finds tests with a layout like:
   *
   *  docs/
   *  src/
   *  tests/
   *
   * ...or similar. This list will be further pruned by the caller; it is
   * intentionally filesystem-agnostic to be unit testable.
   *
   * @param   string        PHP file to locate test cases for.
   * @param   string        The root directory.
   * @return  list<string>  List of directories to search for tests in.
   */
  public static function getSearchLocationsForTests($path, $root) {
    $file = basename($path);
    $dir  = dirname($path);

    $test_dir_names = array('tests', 'Tests');

    $try_directories = array();

    // Try in the current directory.
    $try_directories[] = array($dir);

    // Try in a tests/ directory anywhere in the ancestry.
    foreach (self::walkToRoot($dir, $root) as $parent_dir) {
      if ($parent_dir == '/') {
        // We'll restore this later.
        $parent_dir = '';
      }
      foreach ($test_dir_names as $test_dir_name) {
        $try_directories[] = array($parent_dir, $test_dir_name);
      }
    }

    // Try replacing each directory component with 'tests/'.
    $parts = preg_replace('/^'.preg_quote($root, '/').'/', '', $dir);
    $parts = trim($parts, DIRECTORY_SEPARATOR);
    $parts = explode(DIRECTORY_SEPARATOR, $parts);
    foreach (array_reverse(array_keys($parts)) as $key) {
      foreach ($test_dir_names as $test_dir_name) {
        $try = $parts;
        $try[$key] = $test_dir_name;
        array_unshift($try, $root);
        $try_directories[] = $try;
      }
    }

    // Try adding 'tests/' at each level.
    foreach (array_reverse(array_keys($parts)) as $key) {
      foreach ($test_dir_names as $test_dir_name) {
        $try = $parts;
        $try[$key] = $test_dir_name.DIRECTORY_SEPARATOR.$try[$key];
        array_unshift($try, $root);
        $try_directories[] = $try;
      }
    }

    $results = array();
    foreach ($try_directories as $parts) {
      $results[implode(DIRECTORY_SEPARATOR, $parts).DIRECTORY_SEPARATOR] = true;
    }

    return array_keys($results);
  }

  private static function walkToRoot($dir, $root) {
    $dir = preg_replace('/^'.preg_quote($root, '/').'/', '', $dir, 1);

    $ret = array();
    $parts = explode(DIRECTORY_SEPARATOR, $dir);

    while (true) {
      $ret[] = $root . implode(DIRECTORY_SEPARATOR, $parts);
      array_pop($parts);

      if (!$parts) {
        break;
      }
    }

    return $ret;
  }

  /**
   * Tries to find and update phpunit configuration file based on
   * `phpunit_config` option in `.arcconfig`.
   */
  private function prepareConfigFile() {
    $project_root = $this->projectRoot.DIRECTORY_SEPARATOR;
    $config = $this->getConfigurationManager()->getConfigFromAnySource(
      'phpunit_config');

    if ($config) {
      if (Filesystem::pathExists($project_root.$config)) {
        $this->configFile = $project_root.$config;
        $this->testFileIndex = self::buildTestFileIndex($this->configFile);
      } else {
        throw new Exception(
          pht(
            'PHPUnit configuration file was not found in %s',
            $project_root.$config));
      }
    }
    $bin = $this->getConfigurationManager()->getConfigFromAnySource(
      'unit.phpunit.binary');
    if ($bin) {
      if (Filesystem::binaryExists($bin)) {
        $this->phpunitBinary = $bin;
      } else {
        $this->phpunitBinary = Filesystem::resolvePath($bin, $project_root);
      }
    }
  }

  /**
   * Builds an index of test files from test suites of a PHPUnit
   * configuration file.
   *
   * @param   string  PHPUnit configuration file.
   * @return  map<string, list<string>>|null  Test file paths by file name,
   *                                          or null without test suites.
   */
  private static function buildTestFileIndex($config_file) {
    $suites = self::parseTestSuites(
      Filesystem::readFile($config_file),
      dirname($config_file));

    if (!$suites['directories'] && !$suites['files']) {
      return null;
    }

    $excludes = array();
    foreach (self::expandPaths($suites['excludes']) as $exclude) {
      $excludes[] = rtrim($exclude, DIRECTORY_SEPARATOR);
    }

    $index = array();
    foreach (self::expandPaths($suites['files']) as $file) {
      $index[basename($file)][$file] = true;
    }

    foreach ($suites['directories'] as $directory) {
      foreach (self::expandPaths(array($directory['path'])) as $dir) {
        $test_files = self::findDirectoryTestFiles(
          $dir,
          $directory['prefix'],
          $directory['suffix'],
          $excludes);
        foreach ($test_files as $file) {
          $index[basename($file)][$file] = true;
        }
      }
    }

    return array_map('array_keys', $index);
  }

  /**
   * Finds test files in a test suite directory and its subdirectories.
   *
   * @param   string        Test suite directory.
   * @param   string        Test file name prefix.
   * @param   string        Test file name suffix.
   * @param   list<string>  Excluded paths.
   * @return  list<string>  Test file paths.
   */
  private static function findDirectoryTestFiles(
    $dir,
    $prefix,
    $suffix,
    array $excludes) {

    if (!is_dir($dir)) {
      return array();
    }

    $ret = array();
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
      if (!$file->isFile() ||
          !self::hasAffixes($file->getFilename(), $prefix, $suffix)) {
        continue;
      }

      $file_path = $file->getPathname();
      foreach ($excludes as $exclude) {
        $exclude_dir = $exclude.DIRECTORY_SEPARATOR;
        if ($file_path === $exclude ||
            !strncmp($file_path, $exclude_dir, strlen($exclude_dir))) {
          continue 2;
        }
      }

      $ret[] = $file_path;
    }

    return $ret;
  }

  /**
   * Parses test suites of a PHPUnit configuration.
   *
   * @param   string  PHPUnit configuration (XML).
   * @param   string  Directory of the configuration file, which relative
   *                  paths are resolved against.
   * @return  dict    Test suite "directories" (with "path", "prefix" and
   *                  "suffix"), "files" and "excludes" (paths may contain
   *                  wildcards).
   */
  public static function parseTestSuites($config, $config_dir) {
    $xml = new SimpleXMLElement($config);

    $directories = array();
    $files = array();
    $excludes = array();
    foreach ($xml->xpath('//testsuites/testsuite') as $testsuite) {
      foreach ($testsuite->directory as $directory) {
        $path = self::resolveConfigPath((string)$directory, $config_dir);
        $prefix = isset($directory['prefix'])
          ? (string)$directory['prefix']
          : '';
        $suffix = isset($directory['suffix'])
          ? (string)$directory['suffix']
          : 'Test.php';

        $directories[$path.'|'.$prefix.'|'.$suffix] = array(
          'path' => $path,
          'prefix' => $prefix,
          'suffix' => $suffix,
        );
      }

      foreach ($testsuite->file as $file) {
        $path = self::resolveConfigPath((string)$file, $config_dir);
        $files[$path] = $path;
      }

      foreach ($testsuite->exclude as $exclude) {
        $path = self::resolveConfigPath((string)$exclude, $config_dir);
        $excludes[$path] = $path;
      }
    }

    return array(
      'directories' => array_values($directories),
      'files' => array_values($files),
      'excludes' => array_values($excludes),
    );
  }

  /**
   * Resolves a path from a PHPUnit configuration file without touching the
   * filesystem.
   *
   * @param   string  Path (absolute or relative).
   * @param   string  Directory of the configuration file.
   * @return  string  Absolute path.
   */
  private static function resolveConfigPath($path, $config_dir) {
    $path = trim($path);
    if (strncmp($path, DIRECTORY_SEPARATOR, 1) !== 0) {
      $path = $config_dir.DIRECTORY_SEPARATOR.$path;
    }

    $parts = array();
    foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..') {
        array_pop($parts);
        continue;
      }
      $parts[] = $part;
    }

    return DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
  }

  /**
   * Expands wildcards in paths.
   *
   * @param   list<string>  Paths (may contain wildcards).
   * @return  list<string>  Existing paths.
   */
  private static function expandPaths(array $paths) {
    $ret = array();
    foreach ($paths as $path) {
      $matches = glob($path);
      if ($matches) {
        foreach ($matches as $match) {
          $ret[] = $match;
        }
      }
    }

    return $ret;
  }

  /**
   * Checks, that a file name has given prefix and suffix.
   *
   * @param   string  File name.
   * @param   string  Prefix.
   * @param   string  Suffix.
   * @return  bool
   */
  private static function hasAffixes($name, $prefix, $suffix) {
    if ($prefix !== '' && strncmp($name, $prefix, strlen($prefix)) !== 0) {
      return false;
    }

    return $suffix === '' || substr($name, -strlen($suffix)) === $suffix;
  }

  /**
   * Patches a config file.
   *
   * @return void
   */
  private function patchConfigFile()
  {
    if (!$this->configFile) {
      $this->configFile = tempnam($this->projectRoot, 'arc-unit-'.getmypid().'-');

      $phpunit_config_file_data = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit beStrictAboutOutputDuringTests="true">
    <testsuites/>
</phpunit>
XML;
    } else {
      $phpunit_config_file_data = Filesystem::readFile($this->configFile);

      // Ensure, that relative paths in config will work.
      $this->configFile = tempnam(dirname($this->configFile), 'arc-unit-'.getmypid().'-');
    }

    $root = new SimpleXMLElement($phpunit_config_file_data);

    $testsuites = $root->testsuites;
    if (count($testsuites) === 0) {
      $testsuites = $root->addChild('testsuites');
    }

    $testsuite = $testsuites->addChild('testsuite');
    $testsuite->addAttribute('name', 'arc');

    foreach (array_unique($this->affectedTests) as $test_path) {
      $testsuite->addChild('file', $test_path);
    }

    Filesystem::writeFile($this->configFile, $root->asXML());
  }

}
