<?php

namespace Aesislabs\Bundle\OdooBundle\DependencyInjection;

use Aesislabs\Bundle\OdooBundle\Connection\ClientRegistry;
use Aesislabs\Bundle\OdooBundle\ORM\ObjectManagerRegistry;
use Aesislabs\Component\Odoo\Client;
use Aesislabs\Component\Odoo\ORM\Configuration as OrmConfiguration;
use Aesislabs\Component\Odoo\ORM\ObjectManager;
use Doctrine\Common\Annotations\Reader;
use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @author Joanis ROUANET
 */
class AesislabsOdooExtension extends Extension
{
    /**
     * {@inheritdoc}
     *
     * @throws Exception on services file loading failure
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $container->setParameter('ang3_odoo.parameters', $config);

        $connections = $config['connections'] ?? [];
        $container->setParameter('ang3_odoo.connections', $connections);

        $orm = $config['orm'] ?? [];
        $orm['managers'] = $orm['managers'] ?? [];
        $container->setParameter('ang3_odoo.orm', $orm);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        if (!array_key_exists($config['default_connection'], $connections)) {
            throw new InvalidArgumentException(sprintf('The default Odoo connection "%s" is not configured', $config['default_connection']));
        }

        $clientRegistry = $container->getDefinition(ClientRegistry::class);

        foreach ($connections as $connectionName => $params) {
            $loggerServiceName = $params['logger'] ?: $config['default_logger'];
            $logger = $loggerServiceName ? new Reference($loggerServiceName) : null;

            $client = new Definition(Client::class, [
                $params['url'],
                $params['database'],
                $params['user'],
                $params['password'],
                $logger,
            ]);

            $connectionName = (string) $connectionName;
            $clientName = $this->formatClientServiceName($connectionName);
            $container->setDefinition($clientName, $client);
            $container->registerAliasForArgument($clientName, Client::class, "$connectionName.client");

            if ($connectionName === $config['default_connection']) {
                $container->setDefinition(Client::class, $client);
                $container->setAlias('ang3_odoo.client', $clientName);
                $container->registerAliasForArgument($clientName, Client::class, 'client');
            }

            $clientReference = new Reference($clientName);
            $clientRegistry->addMethodCall('add', [$connectionName, $clientReference]);
        }

        $ormEnabled = $orm['enabled'] ?? false;

        if ($ormEnabled) {
            $this->loadOrm($container, $connections, $config['default_connection'], $orm);
        }
    }

    public function loadOrm(ContainerBuilder $container, array $connections, string $defaultConnection, array $config): void
    {
        $managers = $config['managers'] ?? [];
        $objectManagerRegistry = $container->getDefinition(ObjectManagerRegistry::class);

        foreach ($managers as $connectionName => $managerConfig) {
            if (!isset($connections[$connectionName])) {
                throw new InvalidArgumentException(sprintf('The Odoo connection "%s" was not found', $connectionName));
            }

            $objectManagerServiceName = sprintf('ang3_odoo.orm.object_manager.%s', $connectionName);
            $configurationServiceName = sprintf('%s.configuration', $objectManagerServiceName);
            $configuration = new Definition(OrmConfiguration::class, [
                new Reference('cache.app'),
                new Reference('cache.app'),
            ]);
            $container->setDefinition($configurationServiceName, $configuration);

            $objectManagerServiceName = sprintf('ang3_odoo.orm.object_manager.%s', $connectionName);
            $objectManager = new Definition(ObjectManager::class, [
                new Reference($this->formatClientServiceName($connectionName)),
                new Reference($configurationServiceName),
                new Reference(Reader::class),
            ]);
            $container->setDefinition($objectManagerServiceName, $objectManager);
            $container->registerAliasForArgument($objectManagerServiceName, ObjectManager::class, sprintf('%sObjectManager', $connectionName));

            if ($connectionName === $defaultConnection) {
                $container->setDefinition(ObjectManager::class, $objectManager);
                $container->setAlias('ang3_odoo.object_manager', $objectManagerServiceName);
                $container->setAlias('ang3_odoo.default_object_manager', $objectManagerServiceName);
            }

            $objectManagerReference = new Reference($objectManagerServiceName);
            $objectManagerRegistry->addMethodCall('add', [$connectionName, $objectManagerReference]);
        }
    }

    private function formatClientServiceName(string $connectionName): string
    {
        return sprintf('ang3_odoo.client.%s', $connectionName);
    }
}
