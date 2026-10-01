<?php

declare(strict_types=1);

namespace NineTeufel\NotificationCenterFileGatewayBundle\ContaoManager;

use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Terminal42\NotificationCenterBundle\Terminal42NotificationCenterBundle;
use NineTeufel\NotificationCenterFileGatewayBundle\NineTeufelNotificationCenterFileGatewayBundle;

final class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [(new BundleConfig(NineTeufelNotificationCenterFileGatewayBundle::class))
            ->setLoadAfter([Terminal42NotificationCenterBundle::class])];
    }
}
