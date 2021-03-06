<?php

namespace Aegir\Provision;

use Aegir\Provision\Command\SaveCommand;
use Aegir\Provision\Command\ServicesCommand;
use Aegir\Provision\Command\ShellCommand;
use Aegir\Provision\Command\StatusCommand;
use Aegir\Provision\Command\VerifyCommand;
use Aegir\Provision\Console\Config;
use Aegir\Provision\Console\ConsoleOutput;
use Drupal\Console\Core\Style\DrupalStyle;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Application as BaseApplication;

//use Symfony\Component\DependencyInjection\ContainerInterface;
//use Drupal\Console\Annotations\DrupalCommandAnnotationReader;
//use Drupal\Console\Utils\AnnotationValidator;
//use Drupal\Console\Core\Application as BaseApplication;


/**
 * Class Application
 *
 * @package Aegir\Provision
 */
class Application extends BaseApplication
{

    /**
     * @var string
     */
    const NAME = 'Aegir Provision';

    /**
     * @var string
     */
    const VERSION = '4.x';

    /**
     * @var string
     */
    const CONSOLE_CONFIG = '.provision.yml';

    /**
     * @var string
     */
    const DEFAULT_TIMEZONE = 'America/New_York';
    
    /**
     * @var LoggerInterface
     */
    public $logger;
    
    /**
     * @var DrupalStyle
     */
    public $io;
    
    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    public $input;
    
    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    public $output;
    
    /**
     * @var ConsoleOutput
     */
    public $console;
    
    /**
     * Application constructor.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Aegir\Provision\Console\OutputInterface
     *
     * @throws \Exception
     */
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        // If no timezone is set, set Default.
        if (empty(ini_get('date.timezone'))) {
            date_default_timezone_set($this::DEFAULT_TIMEZONE);
        }

        // Load Configs
        try {
            $this->config = new Config();
        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        $this->console = $output;
        parent::__construct($this::NAME, $this::VERSION);
    }
    
    /**
     * Prepare input and output arguments. Use this to extend the Application object so that $input and $output is fully populated.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function configureIO(InputInterface $input, OutputInterface $output) {
        parent::configureIO($input, $output);
        
        $this->io = new DrupalStyle($input, $output);
        
        $this->input = $input;
        $this->output = $output;
        
        $this->logger = new ConsoleLogger($output,
            [LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL]
        );
    }

    /**
     * @var Config
     */
    private $config;

    /**
     * Getter for Configuration.
     *
     * @return Config
     *                Configuration object.
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Setter for Configuration.
     *
     * @param Config $config
     *                       Configuration object.
     */
    public function setConfig(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Initializes all the default commands.
     */
    protected function getDefaultCommands()
    {
        $commands[] = new HelpCommand();
        $commands[] = new ListCommand();
        $commands[] = new SaveCommand();
        $commands[] = new ServicesCommand();
//        $commands[] = new ShellCommand();
        $commands[] = new StatusCommand();
        $commands[] = new VerifyCommand();

        return $commands;
    }

    /**
     * {@inheritdoc}
     *
     * Adds "--target" option.
     */
    protected function getDefaultInputDefinition()
    {
        $inputDefinition = parent::getDefaultInputDefinition();
        $inputDefinition->addOption(
          new InputOption(
            '--target',
            '-t',
            InputOption::VALUE_NONE,
            'The target context to act on.'
          )
        );

        return $inputDefinition;
    }
    
    /**
     * Load all contexts into Context objects.
     *
     * @return array
     */
    static function getAllContexts($name = '', $application = NULL) {
        $contexts = [];
        $config = new Config();

        $context_files = [];
        $finder = new \Symfony\Component\Finder\Finder();
        $finder->files()->name('*' . $name . '.yml')->in($config->get('config_path') . '/provision');
        foreach ($finder as $file) {
            list($context_type, $context_name) = explode('.', $file->getFilename());
            $context_files[$context_name] = [
                'name' => $context_name,
                'type' => $context_type,
                'file' => $file,
            ];
        }

        foreach ($context_files as $context) {
            $class = Context::getClassName($context['type']);
            $contexts[$context['name']] = new $class($context['name'], $application);
        }

        if ($name && isset($contexts[$name])) {
            return $contexts[$name];
        }
        elseif ($name && !isset($contexts[$name])) {
            return NULL;
        }
        else {
            return $contexts;
        }
    }

    /**
     * Load all server contexts.
     *
     * @param null $service
     * @return mixed
     * @throws \Exception
     */
    static public function getAllServers($service = NULL) {
        $servers = [];
        $context_files = self::getAllContexts();
        if (empty($context_files)) {
            throw new \Exception('No server contexts found. Use `provision save` to create one.');
        }
        foreach ($context_files as $context) {
            if ($context->type == 'server') {
                $servers[$context->name] = $context;
            }
        }
        return $servers;
    }

    /**
     * Get a simple array of all contexts, for use in an options list.
     * @return array
     */
    public function getAllContextsOptions($type = NULL) {
        $options = [];
        foreach ($this->getAllContexts() as $name => $context) {
            if ($type) {
                if ($context->type == $type) {
                    $options[$name] = $context->name;
                }
            }
            else {
                $options[$name] = $context->type . ' ' . $context->name;
            }
        }
        return $options;
    }
    
    /**
     * Load the Aegir context with the specified name.
     *
     * @param $name
     *
     * @return \Aegir\Provision\Context
     * @throws \Exception
     */
    static public function getContext($name, Application $application = NULL) {
        if (empty($name)) {
            throw new \Exception('Context name must not be empty.');
        }
        if (empty(Application::getAllContexts($name, $application))) {
            throw new \Exception('Context not found with name: ' . $name);
        }
        return Application::getAllContexts($name, $application);
    }

    /**
     * Get a simple array of all servers, optionally specifying the the service_type to filter by ("http", "db" etc.)
     * @param string $service_type
     * @return array
     */
    public function getServerOptions($service_type = '') {
        $servers = [];
        foreach ($this->getAllServers() as $server) {
            if ($service_type && !empty($server->config['services'][$service_type])) {
                $servers[$server->name] = $server->name . ': ' . $server->config['services'][$service_type]['type'];
            }
            elseif ($service_type == '') {
                $servers[$server->name] = $server->name . ': ' . $server->config['services'][$service_type]['type'];
            }
        }
        return $servers;
    }

    /**
     * Check that a context type's service requirements are provided by at least 1 server.
     *
     * @param $type
     * @return array
     */
    static function checkServiceRequirements($type) {
        $class_name = Context::getClassName($type);

        // @var $context Context
        $service_requirements = $class_name::serviceRequirements();

        $services = [];
        foreach ($service_requirements as $service) {
            try {
                if (empty(Application::getAllServers($service))) {
                    $services[$service] = 0;
                }
                else {
                    $services[$service] = 1;
                }
            } catch (\Exception $e) {
                $services[$service] = 0;
            }
        }
        return $services;
    }
}
