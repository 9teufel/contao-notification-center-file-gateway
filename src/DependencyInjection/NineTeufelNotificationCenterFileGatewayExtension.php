<?php

declare(strict_types=1);

namespace NineTeufel\NotificationCenterFileGatewayBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class NineTeufelNotificationCenterFileGatewayExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        (new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config')))->load('services.php');
    }
}
