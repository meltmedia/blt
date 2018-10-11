<?php

namespace Acquia\Blt\Custom\Hooks;

use Acquia\Blt\Robo\BltTasks;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

/**
 * This class defines example hooks.
 */
class InitProjectHook extends BltTasks {

  /**
   * This will be called before the `recipes:aliases:init:acquia` command.
   *
   * @hook command-event recipes:aliases:init:acquia
   */
  public function preRecipesAliasesInitAcquia(ConsoleCommandEvent $event) {

    $command = $event->getCommand();
    $this->say("preCommandMessage hook: The {$command->getName()} command is about to run!");
  }

  /**
   * This will be called after the `internal:add-to-project` command.
   *
   * @hook post-command-event internal:add-to-project
   */
  public function postInternalAddToProject(ConsoleCommandEvent $event) {
    $command = $event->getCommand();
    $this->say("postCommandMessage hook: The {$command->getName()} command is about to run!");
  }

}
