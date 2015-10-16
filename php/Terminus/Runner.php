<?php

namespace Terminus;

use Terminus;
use Terminus\Utils;
use Terminus\Loggers\Logger;
use Terminus\Exceptions\TerminusException;

class Runner {
  public $config;
  public $extra_config;

  private $arguments;
  private $assoc_args;
  private $_early_invoke = array();
  private $global_config_path;
  private $project_config_path;

  /**
   * @var LoggerInterface
   */
  private $logger;

  /**
   * @var OutputterInterface
   */
  private $outputter;

  public function __construct() {
    $this->init_config();
    $this->init_colorization();
    $this->init_logger();
    $this->init_outputter();
  }

  public function __get($key) {
    if ('_' === $key[0])
      return null;

    return $this->$key;
  }

  public function find_command_to_run($args) {
    $command = Terminus::getRootCommand();

    $cmd_path = array();
    while (!empty($args) && $command->can_have_subcommands()) {
      $cmd_path[] = $args[0];
      $full_name  = implode(' ', $cmd_path);

      $subcommand = $command->find_subcommand($args);

      if (!$subcommand) {
        throw new TerminusException(
          "'{cmd}' is not a registered command. See 'terminus help'.",
          array('cmd' => $full_name)
       );
      }

      $command = $subcommand;
    }

    return array($command, $args, $cmd_path);
  }

  public function runCommand($args, $assoc_args = array()) {

    try {
      list($command, $final_args, $cmd_path) = $this->find_command_to_run($args);
      $name = implode(' ', $cmd_path);

      if (isset($this->extra_config[$name])) {
        $extra_args = $this->extra_config[$name];
      }
      else {
        $extra_args = array();
      }

      $command->invoke($final_args, $assoc_args, $extra_args);

    } catch (\Exception $e) {
      if (method_exists($e, 'getReplacements')) {
        $this->logger->error($e->getMessage(), $e->getReplacements());
      }
      else {
        $this->logger->error($e->getMessage());
      }
      exit(1);
    }
  }

  private function _runCommand() {
    $this->runCommand($this->arguments, $this->assoc_args);
  }

  public function in_color() {
    return $this->colorize;
  }

  private function init_colorization() {
    if ($this->config['colorize'] == 'auto') {
      $this->colorize = !\cli\Shell::isPiped();
    } else {
      $this->colorize = $this->config['colorize'];
    }
  }

  private function init_logger() {
    $this->logger = new Logger(array('config' => $this->config));
    Terminus::setLogger($this->logger);
  }

  private function init_outputter() {

    // Pick an output formatter
    if ($this->config['format'] == 'json') {
      $formatter = new Terminus\Outputters\JSONFormatter();
    }
    else if ($this->config['format'] == 'bash') {
      $formatter = new Terminus\Outputters\BashFormatter();
    }
    else {
      $formatter = new Terminus\Outputters\PrettyFormatter();
    }
    // @TODO: Implement BASH output formatter

    // Create an output service.
    $this->outputter = new Terminus\Outputters\Outputter(
      new Terminus\Outputters\StreamWriter('php://stdout'),
      $formatter
    );

    Terminus::setOutputter($this->outputter);
  }

  private function init_config() {
    $configurator = Terminus::getConfigurator();

    // Runtime config and args
    {
      list($args, $assoc_args, $runtime_config) = $configurator->parseArgs(
        array_slice($GLOBALS['argv'], 1));


      $this->arguments = $args;
      $this->assoc_args = $assoc_args;

      $configurator->mergeArray($runtime_config);
    }

    list($this->config, $this->extra_config) = $configurator->toArray();
  }

  public function run() {
    if (Terminus::isTest()) {
      return true;
    }

    if (empty($this->arguments))
      $this->arguments[] = 'help';

    // Load bundled commands early, so that they're forced to use the same
    // APIs as non-bundled commands.
    Utils\loadCommand($this->arguments[0]);

    if (isset($this->config['require'])) {
      foreach ($this->config['require'] as $path) {
        Utils\loadFile($path);
      }
    }

    try {
      // Show synopsis if it's a composite command.
      $r = $this->find_command_to_run($this->arguments);
      if (is_array($r)) {
        list($command) = $r;

        if ($command->can_have_subcommands()) {
          $command->show_usage();
          exit;
        }
      }
    } catch (TerminusException $e) {
      // Do nothing. Actual error handling will be done by _runCommand
    }

    // First try at showing man page
    if ('help' === $this->arguments[0] && (isset($this->arguments[1]))) {
      $this->_runCommand();
    }

    # Run the stinkin command!
    $this->_runCommand();
  }

}
