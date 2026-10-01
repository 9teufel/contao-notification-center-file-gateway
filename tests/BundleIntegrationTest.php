<?php

declare(strict_types=1);

namespace NineTeufel\NotificationCenterFileGatewayBundle\Tests;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Terminal42\NotificationCenterBundle\BulkyItem\BulkyItemStorage;
use Terminal42\NotificationCenterBundle\DependencyInjection\CompilerPass\AbstractGatewayPass;
use Terminal42\NotificationCenterBundle\NotificationCenter;
use NineTeufel\NotificationCenterFileGatewayBundle\ContaoManager\Plugin;
use NineTeufel\NotificationCenterFileGatewayBundle\Gateway\FileGateway;
use NineTeufel\NotificationCenterFileGatewayBundle\NineTeufelNotificationCenterFileGatewayBundle;

final class BundleIntegrationTest extends TestCase
{
    public function testBundleLoadsServicesAndNotificationCenterInjectsParserLocator(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->register(ContaoFramework::class)->setSynthetic(true);
        $container->register(NotificationCenter::class)->setSynthetic(true);
        $container->register(BulkyItemStorage::class)->setSynthetic(true);
        $container->register('logger', NullLogger::class);
        $bundle = new NineTeufelNotificationCenterFileGatewayBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([], $container);
        $container->getDefinition(FileGateway::class)->setPublic(true);
        self::assertTrue($container->getDefinition(FileGateway::class)->hasTag('notification_center.gateway'));
        $container->addCompilerPass(new AbstractGatewayPass());
        $container->compile();
        $container->set(ContaoFramework::class, $this->createMock(ContaoFramework::class));
        $gateway = $container->get(FileGateway::class);
        self::assertInstanceOf(FileGateway::class, $gateway);
        self::assertSame('9teufel_file', $gateway->getName());
        self::assertCount(1, $container->getDefinition(FileGateway::class)->getMethodCalls());
    }

    public function testDcaAugmentsAllThreeTablesAndRetainsMailer(): void
    {
        $root = \dirname(__DIR__);
        foreach (['tl_nc_gateway', 'tl_nc_message', 'tl_nc_language'] as $table) {
            $GLOBALS['TL_DCA'][$table] = ['palettes' => ['mailer' => 'existing'], 'fields' => ['sentinel' => []]];
            require $root.'/contao/dca/'.$table.'.php';
            self::assertSame('existing', $GLOBALS['TL_DCA'][$table]['palettes']['mailer']);
            self::assertArrayHasKey('sentinel', $GLOBALS['TL_DCA'][$table]['fields']);
            self::assertArrayHasKey(FileGateway::NAME, $GLOBALS['TL_DCA'][$table]['palettes']);
        }
        self::assertSame('fileTree', $GLOBALS['TL_DCA']['tl_nc_gateway']['fields']['tfg_directory']['inputType']);
        self::assertFalse($GLOBALS['TL_DCA']['tl_nc_gateway']['fields']['tfg_directory']['eval']['files']);
        self::assertSame(['create', 'overwrite'], $GLOBALS['TL_DCA']['tl_nc_gateway']['fields']['tfg_mode']['options']);
        self::assertTrue($GLOBALS['TL_DCA']['tl_nc_language']['fields']['tfg_content']['eval']['preserveTags']);
    }

    public function testManagerPluginRegistersBundle(): void
    {
        $bundles = (new Plugin())->getBundles($this->createMock(ParserInterface::class));
        self::assertCount(1, $bundles);
        self::assertSame(NineTeufelNotificationCenterFileGatewayBundle::class, $bundles[0]->getName());
    }
}
