<?php

namespace Meltmedia\Blt\Tests;

use PHPUnit\Framework\TestCase;
use Meltmedia\Blt\Composer\Project;

class ProjectTest extends TestCase {
  public function setUp() {
    parent::setUp();
  }

  /**
   * Tests project name is accurately set when using the setName() method.
   */
  public function testSetName() {
    $project = new Project();
    $project->setName('Sebi Land');
    $this->assertEquals('Sebi Land', $project->name);
  }

  /**
   * Tests project name is accurately set when using the setMachineName() method.
   */
  public function testSetMachineName() {
    $project = new Project();
    $project->setMachineName('ChurroVille');
    $this->assertEquals('churroville', $project->machineName);

    $project->setMachineName('Churro Ville');
    $this->assertEquals('churro_ville', $project->machineName);
  }

  /**
   * Tests project name and machine name are correctly set when passing the constructor argument.
   */
  public function testProjectConstructor() {
    $project = new Project('Constructor Project');
    $this->assertEquals('Constructor Project', $project->name);
    $this->assertEquals('constructor_project', $project->machineName);
  }

  public function testSetAppId() {
    $project = new Project();
    $project->setAppId('b7ba32f2-2793-466e-a369-e8a701965fba');
    $this->assertEquals('b7ba32f2-2793-466e-a369-e8a701965fba', $project->appId);
  }
}