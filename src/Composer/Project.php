<?php

namespace Meltmedia\Blt\Composer;

use function preg_match;
use function preg_replace;

class Project
{
  protected $name;
  protected $machineName;
  protected $appId;
  protected $jiraProjectCode;

  public function __construct($project_name = NULL) {
    if ($project_name) {
      $this->setName($project_name);
    }
  }

  public function setName($name, $set_machine = TRUE) {
    $this->name = $name;
    if ($set_machine) {
      $this->setMachineName($name);
    }
  }

  public function setMachineName($name) {
    $this->machineName = $this->machineNameify($name);
  }

  public function setJiraProjectCode($code) {
    if (preg_match('/[A-Z]{3,4}/', $code) !== 1) {
      throw new \Exception('JIRA project code should be three or four characters long.');
    }
    $this->jiraProjectCode = $code;
  }

  public function setAppId($appId) {
    $this->appId = $appId;
  }

  private function machineNameify($label) {
    return preg_replace('/\s\S*?/', '_', str_replace('  ', ' ', strtolower($label)));
  }

  public function __get($name) {
    return $this->$name;
  }
}