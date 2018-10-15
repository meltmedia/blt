<?php

namespace Meltmedia\Blt\Composer;

use Composer\Composer;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Acquia\Hmac\Guzzle\HmacAuthMiddleware;
use Acquia\Hmac\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use Symfony\Component\Yaml\Yaml;
use Acquia\Blt\Robo\Common\YamlMunge;

use function is_string;
use function file_put_contents;
use function file_get_contents;
use function json_decode;
use function json_encode;

class Plugin implements PluginInterface, EventSubscriberInterface
{
  /**
   * @var Composer
   */
  protected $composer;
  /**
   * @var IOInterface
   */
  protected $io;
  /**
   * @var EventDispatcher
   */
  protected $eventDispatcher;
  /**
   * @var ProcessExecutor
   */
  protected $executor;

  /**
   * GuzzleHTTP
   *
   * @var GuzzleHttp\Client
   */
  protected $cloudApiClient;

  /**
   * Represents the BLT project during initial setup
   *
   * @var Meltmedia\Blt\Composer\Project
   */
  protected $project;

  /**
   * @var string
   */
  protected $cloudConfDir;
  /**
   * @var string
   */
  protected $cloudConfFileName;
  /**
   * @var string
   */
  protected $cloudConfFilePath;

  /**
   * Priority that plugin uses to register callbacks.
   */
  const CALLBACK_PRIORITY = 50000;

  public function activate(Composer $composer, IOInterface $io)
  {
    $this->composer = $composer;
    $this->io = $io;
    $this->eventDispatcher = $composer->getEventDispatcher();
    ProcessExecutor::setTimeout(3600);
    $this->executor = new ProcessExecutor($this->io);
  }

  public static function getSubscribedEvents()
  {
    return array(
      ScriptEvents::POST_UPDATE_CMD => [
        ['onPostUpdateCmd', self::CALLBACK_PRIORITY],
      ],
    );
  }

  
  /**
   * Retrieves an authenticated connection to Acquia cloud API.
   *
   * @return GuzzleHttp\Client
   */
  private function getCloudApiClient() {
    return $this->cloudApiClient;
  }

  public function onPostUpdateCmd(Event $event) {
    if ($this->isInitialInstall()) {
      $this->setupProject();
    }
  }

  /**
   * Sets up the BLT project for the first time.
   *
   * @return void
   */
  protected function setupProject() {
    $setupFile = $this->getRepoRoot() . '/.meltmedia';
    if (!file_exists($setupFile)) {
      $this->project = new Project();
      $this->gatherProjectInformation();
      $this->copyTemplateFiles();
      $this->generateBltConfig();
      $this->generateLandoConfig();
      $this->generateTravisConfig();

      $git_dir = $this->getRepoRoot() . '/.git';
      $command = "rm -rf $git_dir";
      $success = $this->executeCommand($command, [], TRUE);
      if (!$success) {
        $this->io->write("<error>Could not run $command</error>");
      }

      $command = "touch $setupFile";
      $success = $this->executeCommand($command, [], TRUE);
      if (!$success) {
        $this->io->write("<error>Could not run $command</error>");
      }
    }
  }

  protected function copyTemplateFiles() {
    $source = $this->getVendorPath() . '/meltmedia/blt/template';
    $destination = $this->getRepoRoot();
    $command = "rsync -a --no-g '$source/' '$destination/'";
    $success = $this->executeCommand($command, [], TRUE);
    if (!$success) {
      $this->io->write("<error>Unable to copy $source to $destination</error>");
    }
  }

  /**
   * Prompts the user for project specific questions
   *
   * @return void
   */
  protected function gatherProjectInformation() {
    // Ask for the project name...
    $name = $this->io->askAndValidate('What shall we name this cute project? <comment>(Example: Churroville)</comment> ', function($answer) {
      if (!$answer || ($answer !== '' && preg_match('/[^a-zA-Z0-9 ]/', $answer) === 1)) {
        throw new \Exception('Silly goose, try again! No special characters');
      }
      return trim($answer);
    }, NULL);
    $this->project->setName($name);
    
    // Verify the machine-name...
    $machine_name = $this->io->askAndValidate('Set the machine name for this cute lil project thang <comment>(Default '.$this->project->machineName.')</comment>: ', function($answer) {
      if (is_null($answer)) {
        throw new \Exception('Machine names should only contain letters, numbers, or underscores but don\'t go all crazy');
      }
      return $answer;
    }, NULL, $this->project->machineName);

    // @todo When we are ready to enforce project name, uncomment
    // Ask for the JIRA project name...
    /***
    $this->io->askAndValidate("Set the JIRA project name if you have one. <comment>(Leave empty if you don't know)</comment>: ", function($answer) {
      if (!is_null($answer)) {
        $this->project->setJiraProjectCode($answer);
      }
      return $answer;
    }, NULL);
     */

    // Ask the user for the Acquia UUID...
    $applications = $this->loadAcquiaCloudApplications();

    $application_options = array_map(function($item) {
      return "$item->name : ($item->uuid)";
    }, $applications);

    $site_index = $this->io->select('<question>Which acquia environment should we setup aliases for?</question> ', $application_options, FALSE);
    $application = $applications[$site_index];
    $this->project->setAppId($application->uuid);

    // Gather the Acquia git remote URL
    $environments = $this->loadAcquiaCloudApplicationEnvironments($application->uuid);
    $this->project->setGitRemoteUrl($environments[0]->vcs->url);
  }

  protected function loadAcquiaCloudApplicationEnvironments($appId) {
    // attempt to gather acquia site information
    try {
      $response = $this->cloudApiClient->get("https://cloud.acquia.com/api/applications/$appId/environments");
    } catch (ClientException $e) {
      $this->io->write('<error>' . $e->getMessage() . '</error>');
    }

    $data = json_decode($response->getBody()->getContents());
    return $data->_embedded->items;
  }

  protected function generateBltConfig() {
    $filePath = $this->getRepoRoot() . '/blt/local.blt.yml';
    $local_blt_config = YamlMunge::parseFile($filePath);
    $local_blt_config['project']['human_name'] = $this->project->name;
    $local_blt_config['project']['machine_name'] = $this->project->machineName;
    $local_blt_config['cloud']['appId'] = $this->project->appId;

    // add git remotes
    $local_blt_config['git']['remotes'] = [
      'cloud' => $this->project->gitRemoteUrl
    ];

    if ($this->project->jiraProjectCode) {
      $local_blt_config['project']['prefix'] = $this->project->jiraProjectCode;
    }
    else {
      unset($local_blt_config['project']['prefix']);
    }

    try {
      YamlMunge::writeFile($filePath, $local_blt_config);
    } catch (\Exception $e) {
      throw new \Exception("Could not update $filePath.");
    }
  }

  protected function generateLandoConfig() {
    $filePath = $this->getRepoRoot() . '/.lando.yml';
    $lando_config = YamlMunge::parseFile($filePath);

    $lando_config['name'] = $this->project->machineName;

    try {
      YamlMunge::writeFile($filePath, $lando_config);
    } catch (\Exception $e) {
      throw new \Exception("Could not update $filePath.");
    }
  }

  /**
   * Adds travis CI support
   *
   * @return void
   */
  protected function generateTravisConfig() {

    $command = "blt recipes:ci:travis:init";
    $success = $this->executeCommand($command, [], TRUE);
    if (!$success) {
      $this->io->write("<error>Could not run $command</error>");
      return FALSE;
    }

    $filePath = $this->getRepoRoot() . '/.travis.yml';
    $travis_config = YamlMunge::parseFile($filePath);

    preg_match('/@(.+)?:/', $this->project->gitRemoteUrl, $matches);
    if (!empty($matches) && isset($matches[1])) {
      $acquia_host = $matches[1];
      $travis_config['addons']['ssh_known_hosts'][] = $acquia_host;
      $travis_config['addons']['ssh_known_hosts'] = array_unique($travis_config['addons']['ssh_known_hosts']);
    }

    try {
      YamlMunge::writeFile($filePath, $travis_config);
    } catch (\Exception $e) {
      throw new \Exception("Could not update $filePath.");
    }
  }

  /**
   * Queries the Acquia Cloud API for applications the user has access to.
   *
   * @return void
   */
  protected function loadAcquiaCloudApplications() {
    // Check to see if we have acquia cloud api credentials already...
    $cloudApiConfig = $this->loadCloudApiConfig();
    $this->setCloudApiClient($cloudApiConfig['key'], $cloudApiConfig['secret']);

    // attempt to gather acquia site information
    try {
      $response = $this->cloudApiClient->get('https://cloud.acquia.com/api/applications');
    } catch (ClientException $e) {
      $this->io->write('<error>' . $e->getMessage() . '</error>');
    }

    $data = json_decode($response->getBody()->getContents());
    return $data->_embedded->items;
  }

  /**
   * Loads CloudAPI token from an user input if it doesn't exist on disk.
   *
   * @return array
   *   An array of CloudAPI token configuration.
   */
  protected function loadCloudApiConfig() {
    $this->cloudConfDir = $_SERVER['HOME'] . '/.acquia';
    $this->cloudConfFileName = 'cloud_api.conf';
    $this->cloudConfFilePath = $this->cloudConfDir . '/' . $this->cloudConfFileName;

    if (!$config = $this->loadCloudApiConfigFile()) {
      $config = $this->askForCloudApiCredentials();
    }
    return $config;
  }

  /**
   * Load existing credentials from disk.
   *
   * @return bool|array
   *   Returns credentials as array on success, or FALSE on failure.
   */
  protected function loadCloudApiConfigFile() {
    if (file_exists($this->cloudConfFilePath)) {
      return (array) json_decode(file_get_contents($this->cloudConfFilePath));
    }
    else {
      return FALSE;
    }
  }

  /**
   * Writes configuration to local file.
   *
   * @param array $config
   *   An array of CloudAPI configuraton.
   */
  protected function writeCloudApiConfig(array $config) {
    if (!is_dir($this->cloudConfDir)) {
      mkdir($this->cloudConfDir);
    }
    file_put_contents($this->cloudConfFilePath, json_encode($config));
    $this->io->write("<info>Credentials were written to {$this->cloudConfFilePath}.</info>");
  }

  /**
   * Interactive prompt to get Cloud API credentials.
   *
   * @return array
   *   Returns credentials as array on success.
   */
  protected function askForCloudApiCredentials() {
    $this->io->write("You may generate new API tokens at <comment>https://cloud.acquia.com/app/profile/tokens</comment>");

    do {
      $key = $this->io->askAndValidate('Please enter your Acquia cloud API key: ', function($answer) {
        if (!$answer) {
          throw new \Exception("Uh oh... this can't be blank homie.");
        }
        return $answer;
      }, NULL);
      $secret = $this->io->askAndHideAnswer('Please enter your Acquia cloud API secret: ');
    
      $this->setCloudApiClient($key, $secret);
      $cloud_api_client = $this->getCloudApiClient();
    } while (!$cloud_api_client);
    $config = array(
      'key' => $key,
      'secret' => $secret,
    );
    $this->writeCloudApiConfig($config);
    return $config;
  }

  /**
   * Sets up the Acquia Cloud API client and tests the connection.
   *
   * @param string $key
   * @param string $secret
   * @return void
   */
  protected function setCloudApiClient($key, $secret) {
    $this->requireGuzzleFunctions();

    try {
      $key = new Key($key, $secret);
      $middleware = new HmacAuthMiddleware($key);
      $stack = HandlerStack::create();
      $stack->push($middleware);

      $cloud_api = new Client([
        'handler' => $stack,
      ]);

      // We must call some method on the client to test authentication.
      $cloud_api->get('https://cloud.acquia.com/api/applications');

      $this->cloudApiClient = $cloud_api;
      return $cloud_api;
    }
    catch (\Exception $e) {
      // @todo this is being thrown after first auth. still works? check out.
      $this->io->error('Failed to authenticate with Acquia Cloud API.');
      $this->io->error('Exception was thrown: ' . $e->getMessage());
      return NULL;
    }
  }

  /**
   * Determine if this is being installed for the first time.
   *
   * @return boolean
   *   TRUE if this is the initial install of BLT
   */
  private function isInitialInstall() {
    if (!file_exists($this->getRepoRoot() . '/blt/.schema_version')) {
      return TRUE;
    }
    return FALSE;
  }
  

  /**
   * Create a new directory.
   *
   * @return bool
   *   TRUE if directory exists or is created.
   */
  protected function createDirectory($path) {
    return is_dir($path) || mkdir($path);
  }

  /**
   * Returns the repo root's filepath, assumed to be one dir above vendor dir.
   *
   * @return string
   *   The file path of the repository root.
   */
  public function getRepoRoot() {
    return dirname($this->getVendorPath());
  }

  /**
   * Get the path to the 'vendor' directory.
   *
   * @return string
   */
  public function getVendorPath() {
    $config = $this->composer->getConfig();
    $filesystem = new Filesystem();
    $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
    $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));
    return $vendorPath;
  }

  /**
   * Executes a shell command with escaping.
   *
   * Example usage: $this->executeCommand("test command %s", [ $value ]).
   *
   * @param string $cmd
   * @param array $args
   * @param bool $display_output
   *   Optional. Defaults to FALSE. If TRUE, command output will be displayed
   *   on screen.
   *
   * @return bool
   *   TRUE if command returns successfully with a 0 exit code.
   */
  protected function executeCommand($cmd, $args = [], $display_output = FALSE) {
    // Shell-escape all arguments.
    foreach ($args as $index => $arg) {
      $args[$index] = escapeshellarg($arg);
    }
    // Add command as first arg.
    array_unshift($args, $cmd);
    // And replace the arguments.
    $command = call_user_func_array('sprintf', $args);
    $output = '';
    if ($this->io->isVerbose() || $display_output) {
      $this->io->write('<comment> > ' . $command . '</comment>');
      $io = $this->io;
      $output = function ($type, $buffer) use ($io) {
        $io->write($buffer, FALSE);
      };
    }
    return ($this->executor->execute($command, $output) == 0);
  }

  /**
   * Required since the autoload function has not been fully loaded yet and won't detect GuzzleHttp's functions. Lame.
   *
   * @return void
   */
  private function requireGuzzleFunctions() {
    $vendor = $this->getVendorPath();
    require_once "$vendor/guzzlehttp/guzzle/src/functions_include.php";
    require_once "$vendor/guzzlehttp/psr7/src/functions_include.php";
    require_once "$vendor/guzzlehttp/promises/src/functions_include.php";
  }
}