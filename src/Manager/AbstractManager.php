<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\Connection;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

abstract class AbstractManager implements ServiceSubscriberInterface
{
    protected Connection $connection;
    protected ContainerInterface $container;
    protected KernelInterface $kernel;
    protected ParameterBagInterface $parameterBag;

    public function __construct(
        Connection               $connection,
        ContainerInterface       $container,
        KernelInterface          $kernel,
        ParameterBagInterface    $parameterBag
    ) {
        $this->connection = $connection;
        $this->container = $container;
        $this->kernel = $kernel;
        $this->parameterBag = $parameterBag;
    }

    protected function dbColumnExists(string $table, string $column): bool
    {
        return Helper::dbColumnExists($this->connection, $table, $column);
    }

    public static function getSubscribedServices(): array
    {
        return [
            Alchemist::class,
            MigrateDBColsManager::class,
            MigrateGlobalColsManager::class,
            MigrationManager::class,
            TemplateManager::class,
        ];
    }

    public function alchemist(): Alchemist
    {
        return $this->container->get(Alchemist::class);
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

    protected function templateManager(): TemplateManager
    {
        return $this->container->get(TemplateManager::class);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}