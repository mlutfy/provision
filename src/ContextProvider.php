<?php
/**
 * @file
 * Provides the Aegir\Provision\ContextSubscriber class.
 *

 *
 */

namespace Aegir\Provision;

use Symfony\Component\Config\Definition\ConfigurationInterface;


/**
 * Class ContextProvider
 *
 * Context class for a provider of services. Typically ServerContext
 * 
 * @package Aegir\Provision
 */
class ContextProvider extends Context
{
    const ROLE = 'provider';
    
    /**
     * @var array
     * A list of services provided by this context.
     */
    protected $services = [];
    
    
    /**
     * Load Service classes from config into Context.
     */
    protected function prepareServices() {
        foreach ($this->config['services'] as $service_name => $service) {
            $service_class = Service::getClassName($service_name, $service['type']);
            $this->services[$service_name] = new $service_class($service, $this);
        }
    }
    
    /**
     * Return all services this context provides.
     *
     * @return array
     */
    public function getServices() {
        return $this->services;
    }
    
    /**
     * Return a specific service provided by this context.
     *
     * @param $type
     *
     * @return \Aegir\Provision\Service
     */
    public function getService($type) {
        if (isset($this->services[$type])) {
            return $this->services[$type];
        }
        else {
            throw new \Exception("Service '$type' does not exist in the context '{$this->name}'.");
        }
    }
    
    /**
     * Return all services for this context.
     *
     * @return \Aegir\Provision\Service
     */
    public function service($type)
    {
        return $this->getService($type);
    }
    
    protected function servicesConfigTree(&$root_node) {
        $root_node
            ->attribute('context', $this)
            ->children()
                ->arrayNode('services')
                ->prototype('array')
                    ->children()
                        ->scalarNode('type')
                        ->isRequired(true)
                    ->end()
                    ->append($this->addServiceProperties('services'))
                ->end()
            ->end();
    }
    
    
    
}
