<?php

declare(strict_types=1);

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use NineTeufel\NotificationCenterFileGatewayBundle\Filesystem\LocalFileWriter;
use NineTeufel\NotificationCenterFileGatewayBundle\Gateway\FileGateway;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->set(LocalFileWriter::class)->arg('$projectDir', '%kernel.project_dir%');
    $services->set(FileGateway::class)
        ->arg('$framework', service(ContaoFramework::class))
        ->arg('$writer', service(LocalFileWriter::class))
        ->arg('$logger', service('logger'))
        ->tag('notification_center.gateway');
};
