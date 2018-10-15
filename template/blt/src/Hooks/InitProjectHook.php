<?php

namespace Acquia\Blt\Custom\Hooks;

use Acquia\Blt\Robo\BltTasks;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Consolidation\AnnotatedCommand\CommandData;

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
   * This will be called before the `blt:init:settings` command.
   * 
   * @hook command-event blt:init:settings
   */
  public function preBltInitSettings(ConsoleCommandEvent $event) {
    
    $this->say('<comment>Updating root composer.json with melt dependencies...</comment>');
    $filePath = $this->getConfigValue('repo.root') . '/composer.json';

    $composer_json = \json_decode(file_get_contents($filePath), TRUE);
    $composer_json['extra']['merge-plugin']['require'][] = 'blt/composer.melt.json';
    $composer_json['extra']['merge-plugin']['require'] = array_unique($composer_json['extra']['merge-plugin']['require']);

    \file_put_contents($filePath, \json_encode($composer_json, JSON_PRETTY_PRINT));
    $this->say('<comment>Done.</comment>');
  }

}
