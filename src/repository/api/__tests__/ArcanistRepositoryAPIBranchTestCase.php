<?php

final class ArcanistRepositoryAPIBranchTestCase extends PhutilTestCase {

  public function testGitGetBranchName() {
    if (Filesystem::binaryExists('git')) {
      $this->assertBranchName('git_correct_branch.git.tgz', 'sample-branch');
      $this->assertBranchName('git_detached.git.tgz', null);
    } else {
      $this->assertSkipped(pht('Git is not installed'));
    }
  }

  public function testHgGetBranchName() {
    if (Filesystem::binaryExists('hg')) {
      $this->assertBranchName('hg_correct_branch.hg.tgz', 'sample-branch');
    } else {
      $this->assertSkipped(pht('Mercurial is not installed'));
    }
  }

  public function testSvnGetBranchName() {
    if (Filesystem::binaryExists('svn')) {
      $this->assertBranchName('svn_single_project_root.svn.tgz', '');
      $this->assertBranchName('svn_single_project_trunk.svn.tgz', 'trunk');
      $this->assertBranchName('svn_single_project_branch.svn.tgz', 'branches/sample-branch');
      $this->assertBranchName('svn_single_project_tag.svn.tgz', 'tags/sample-tag');

      $this->assertBranchName('svn_multi_project_root.svn.tgz', '');
      $this->assertBranchName('svn_multi_project_trunk.svn.tgz', 'trunk');
      $this->assertBranchName('svn_multi_project_branch.svn.tgz', 'branches/sample-branch');
      $this->assertBranchName('svn_multi_project_tag.svn.tgz', 'tags/sample-tag');
    } else {
      $this->assertSkipped(pht('Subversion is not installed'));
    }
  }

  private function assertBranchName($fixture_file, $expected) {
    $dir = __DIR__.'/branch';
    $fixture = PhutilDirectoryFixture::newFromArchive($dir.'/'.$fixture_file);

    $fixture_path = $fixture->getPath();
    $working_copy = ArcanistWorkingCopyIdentity::newFromPath($fixture_path);
    $configuration_manager = new ArcanistConfigurationManager();
    $configuration_manager->setWorkingCopyIdentity($working_copy);
    $api = ArcanistRepositoryAPI::newAPIFromConfigurationManager(
      $configuration_manager);

    $api->setBaseCommitArgumentRules('arc:this');

    if ($api instanceof ArcanistSubversionAPI) {
      // Upgrade the repository so that the test will still pass if the local
      // `svn` is newer than the `svn` which created the repository.

      // NOTE: Some versions of Subversion (1.7.x?) exit with an error code on
      // a no-op upgrade, although newer versions do not. We just ignore the
      // error here; if it's because of an actual problem we'll hit an error
      // shortly anyway.
      $api->execManualLocal('upgrade');
    }

    $this->assertEqual($expected, $api->getBranchName());
  }

}
