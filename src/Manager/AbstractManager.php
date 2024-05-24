<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException as DBALDBALException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

abstract class AbstractManager implements ServiceSubscriberInterface
{
    protected Connection $connection;
    protected ContainerInterface $container;
    protected Helper $helper;
    protected KernelInterface $kernel;
    protected ParameterBagInterface $parameterBag;

    public function __construct(
        Connection               $connection,
        ContainerInterface       $container,
        Helper                   $helper,
        KernelInterface          $kernel,
        ParameterBagInterface    $parameterBag
    ) {
        $this->connection = $connection;
        $this->container = $container;
        $this->helper = $helper;
        $this->kernel = $kernel;
        $this->parameterBag = $parameterBag;
    }

    public static function getSubscribedServices(): array
    {
        return [
            BundleAlchemist::class,
            MigrateDBColsManager::class,
            MigrateGlobalColsManager::class,
            MigrationManager::class,
            ModuleAlchemist::class,
            TemplateManager::class,
        ];
    }

    public function bundleAlchemist(): BundleAlchemist
    {
        return $this->container->get(BundleAlchemist::class);
    }

    protected function dbManager(): MigrateDBColsManager
    {
        return $this->container->get(MigrateDBColsManager::class);
    }

    protected function globalsManager(): MigrateGlobalColsManager
    {
        return $this->container->get(MigrateGlobalColsManager::class);
    }

    protected function migrationManager(): MigrationManager
    {
        return $this->container->get(MigrationManager::class);
    }

    public function moduleAlchemist(): ModuleAlchemist
    {
        return $this->container->get(ModuleAlchemist::class);
    }

    protected function templateManager(): TemplateManager
    {
        return $this->container->get(TemplateManager::class);
    }
}