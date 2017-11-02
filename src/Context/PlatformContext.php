<?php

namespace Aegir\Provision\Context;

use Aegir\Provision\Application;
use Aegir\Provision\Context;
use Aegir\Provision\Service\Http\Apache\Configuration\PlatformConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use Aegir\Provision\ConfigDefinition\ContextNodeDefinition;
/**
 * Class PlatformContext
 *
 * @package Aegir\Provision\Context
 *
 * @see \Provision_Context_platform
 */
class PlatformContext extends Context implements ConfigurationInterface
{
    /**
     * @var string
     */
    public $type = 'platform';
    
    /**
     * @var \Aegir\Provision\Context\ServerContext;
     */
    public $web_server;
    
    /**
     * PlatformContext constructor.
     *
     * @param $name
     * @param $console_config
     * @param Application $application
     * @param array $options
     */
    function __construct($name, $console_config, Application $application, array $options = [])
    {
        parent::__construct($name, $console_config, $application, $options);
        
        // Load "web_server" context.
        if (isset($this->config['web_server'])) {
//            print $this->config['web_server'];
//            $this->web_server = $application->getContext($this->config['web_server']);
//            $this->web_server->logger = $application->logger;
    
        }
        else {
            throw new \Exception('No web_server found.');
        }
    }
    
    static function option_documentation()
    {
        $options = [
          'root' => 'platform: path to a Drupal installation',
          'server' => 'platform: drush backend server; default @server_master',

            // web_server will be loaded via another method. For now using configTreeBuilder()
//          'web_server' => 'platform: web server hosting the platform; default @server_master',
          'makefile' => 'platform: drush makefile to use for building the platform if it doesn\'t already exist',
          'make_working_copy' => 'platform: Specifiy TRUE to build the platform with the Drush make --working-copy option.',
        ];

        return $options;
    }

    /**
     * @param $root_node
     */
    function configTreeBuilder(ArrayNodeDefinition &$root_node) {
        $root_node
            ->children()
                ->setNodeClass('context', 'Aegir\Provision\ConfigDefinition\ContextNodeDefinition')
                ->node('web_server', 'context')
            ->end()
        ->end();
    }

// @TODO: Remove. This should be handled by Services now.
//    public function verify() {
//        parent::verify();
//        $this->logger->info('Verifying Web Server...');
//        $this->web_server->verify();
//    }
}
