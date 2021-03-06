<?php
/**
 * @link       http://zoopcommerce.github.io/shard
 * @package    Zoop
 * @license    MIT
 */
namespace Zoop\Shard;

use Zend\ServiceManager\Config;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\ArrayUtils;
use Zoop\Shard\Core\ModelManagerAwareInterface;

/**
 * Pass this class a configuration array with extension namespaces, and then retrieve the
 * required subscribers, and document locations
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 *
 * @since   1.0
 * @author  Tim Roediger <superdweebie@gmail.com>
 */
class Manifest extends AbstractExtension
{
    protected $defaultServiceManagerConfig = [
        'invokables' => [
            'modelmanager.delegator.factory' => 'Zoop\Shard\Core\ModelManagerDelegatorFactory'
        ],
        'factories' => [
            'extension.accesscontrol'   => 'Zoop\Shard\AccessControl\ExtensionFactory',
            'extension.annotation'      => 'Zoop\Shard\Annotation\ExtensionFactory',
            'extension.core'            => 'Zoop\Shard\Core\ExtensionFactory',
            'extension.crypt'           => 'Zoop\Shard\Crypt\ExtensionFactory',
            'extension.freeze'          => 'Zoop\Shard\Freeze\ExtensionFactory',
            'extension.generator'       => 'Zoop\Shard\Generator\ExtensionFactory',
            'extension.odmcore'         => 'Zoop\Shard\ODMCore\ExtensionFactory',
            'extension.owner'           => 'Zoop\Shard\Owner\ExtensionFactory',
            'extension.reference'       => 'Zoop\Shard\Reference\ExtensionFactory',
            'extension.rest'            => 'Zoop\Shard\Rest\ExtensionFactory',
            'extension.serializer'      => 'Zoop\Shard\Serializer\ExtensionFactory',
            'extension.softdelete'      => 'Zoop\Shard\SoftDelete\ExtensionFactory',
            'extension.stamp'           => 'Zoop\Shard\Stamp\ExtensionFactory',
            'extension.state'           => 'Zoop\Shard\State\ExtensionFactory',
            'extension.validator'       => 'Zoop\Shard\Validator\ExtensionFactory',
            'extension.zone'            => 'Zoop\Shard\Zone\ExtensionFactory',
            'subscriber.lazysubscriber' => 'Zoop\Shard\Core\LazySubscriberFactory',
            'eventmanager'              => 'Zoop\Shard\Core\EventManagerFactory'
        ],
        'delegators' => [
            'modelmanager' => ['modelmanager.delegator.factory']
        ]
    ];

    /**
     * Keys are extension namespaces
     * Values are extensionConfig objects
     *
     * @var array
     */
    protected $extensionConfigs = [];

    protected $lazySubscriberConfig;

    protected $modelManager;

    protected $serviceManager;

    protected $initalized = false;

    protected $name;

    public function getExtensionConfigs()
    {
        return $this->extensionConfigs;
    }

    public function setExtensionConfigs(array $extensionConfigs)
    {
        $this->extensionConfigs = $extensionConfigs;
    }

    public function getLazySubscriberConfig()
    {
        return $this->lazySubscriberConfig;
    }

    public function setLazySubscriberConfig($lazySubscriberConfig)
    {
        $this->lazySubscriberConfig = $lazySubscriberConfig;
    }

    public function getModelManager()
    {
        return $this->modelManager;
    }

    public function setModelManager($modelManager)
    {
        $this->modelManager = $modelManager;
    }

    public function setServiceManager($serviceManager)
    {
        $this->serviceManager = $serviceManager;
    }

    public function getServiceManager()
    {
        $this->initalize();

        return $this->serviceManager;
    }

    public function getInitalized()
    {
        return $this->initalized;
    }

    public function setInitalized($initalized)
    {
        $this->initalized = $initalized;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    protected function initalize()
    {
        if ($this->initalized) {
            return;
        }
        $this->initalized = true;

        $initalServiceManagerConfig = ArrayUtils::merge(
            $this->defaultServiceManagerConfig,
            $this->serviceManagerConfig
        );

        if (isset($this->serviceManager)) {
            $serviceManager = $this->serviceManager;
        } else {
            $serviceManager = self::createServiceManager($initalServiceManagerConfig);
            $this->serviceManager = $serviceManager;
        }
        $serviceManager->setService('manifest', $this);

        //initalize extensions
        foreach ($this->extensionConfigs as $name => $extensionConfig) {
            if (!$extensionConfig) {
                unset($this->extensionConfigs[$name]);
                continue;
            }
            $this->expandExtensionConfig($name);
        }

        //merge all the configs
        $config = [
            'service_manager_config' => [],
            'models' => []
        ];
        foreach ($this->extensionConfigs as $extensionConfig) {
            $config = ArrayUtils::merge(
                $config,
                array_intersect_key(
                    $extensionConfig,
                    $config
                )
            );
        }
        $this->serviceManagerConfig = ArrayUtils::merge($config['service_manager_config'], $initalServiceManagerConfig);
        $this->models = ArrayUtils::merge($config['models'], $this->models);

        //Apply service manager config
        $serviceManagerConfig = new Config($this->serviceManagerConfig);
        $serviceManager->setAllowOverride(true);
        $serviceManagerConfig->configureServiceManager($serviceManager);
        $serviceManager->setAllowOverride(false);

        //setup lazySubscriber configuration
        $this->lazySubscriberConfig = $this->getLazySubscirberConfig($serviceManager);
        $this->subscribers = ['subscriber.lazysubscriber'];
    }

    protected function getLazySubscirberConfig($serviceManager)
    {
        $lazySubscriberConfig = [];
        foreach ($this->extensionConfigs as $extensionConfig) {
            foreach ($extensionConfig['subscribers'] as $subscriber) {
                foreach ($serviceManager->get($subscriber)->getSubscribedEvents() as $event) {
                    if (! isset($lazySubscriberConfig[$event])) {
                        $lazySubscriberConfig[$event] = [];
                    }
                    $lazySubscriberConfig[$event][] = $subscriber;
                }
            }
            foreach ($this->subscribers as $subscriber) {
                foreach ($serviceManager->get($subscriber)->getSubscribedEvents() as $event) {
                    if (! isset($lazySubscriberConfig[$event])) {
                        $lazySubscriberConfig[$event] = [];
                    }
                    $lazySubscriberConfig[$event][] = $subscriber;
                }
            }
        }

        return $lazySubscriberConfig;
    }

    protected function expandExtensionConfig($name)
    {
        //Get extension
        $extension = $this->serviceManager->get($name);

        //ensure dependencies get expaned also
        foreach (array_keys($extension->getDependencies()) as $dependencyName) {
            if (! isset($this->extensionConfigs[$dependencyName]) ||
                is_bool($this->extensionConfigs[$dependencyName])
            ) {
                $this->expandExtensionConfig($dependencyName);
            }
        }

        $this->extensionConfigs[$name] = $extension->toArray();
    }

    /**
     * Creates a service manager instnace
     *
     * @param  array                               $config
     * @return \Zend\ServiceManager\ServiceManager
     */
    public static function createServiceManager(array $config = [])
    {
        $serviceManager = new ServiceManager(new Config($config));

        $serviceManager->addInitializer(
            function ($instance, ServiceLocatorInterface $serviceLocator) {
                if ($instance instanceof ModelManagerAwareInterface) {
                    $instance->setModelManager($serviceLocator->get('modelmanager'));
                }
            }
        );

        $serviceManager->addInitializer(
            function ($instance, ServiceLocatorInterface $serviceLocator) {
                if ($instance instanceof ServiceLocatorAwareInterface) {
                    $instance->setServiceLocator($serviceLocator);
                }
            }
        );

        return $serviceManager;
    }

    /**
     * Cast to array
     * This allows the merged manifest config to be cached
     * between requests for significant performance improvement
     *
     * @return array
     */
    public function toArray()
    {
        $this->initalize();
        $array = parent::toArray();
        unset($array['default_service_manager_config']);
        unset($array['service_manager']);

        return $array;
    }
}
