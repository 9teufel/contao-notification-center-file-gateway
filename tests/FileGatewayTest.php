<?php

declare(strict_types=1);

namespace NineTeufel\NotificationCenterFileGatewayBundle\Tests;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\InsertTag\InsertTagSubscription;
use Contao\CoreBundle\String\SimpleTokenParser;
use Contao\Dbafs;
use Contao\FilesModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpKernel\Fragment\FragmentHandler;
use Terminal42\NotificationCenterBundle\Config\GatewayConfig;
use Terminal42\NotificationCenterBundle\Config\LanguageConfig;
use Terminal42\NotificationCenterBundle\Config\MessageConfig;
use Terminal42\NotificationCenterBundle\Exception\Parcel\CouldNotSealParcelException;
use Terminal42\NotificationCenterBundle\Parcel\Parcel;
use Terminal42\NotificationCenterBundle\Parcel\Stamp\GatewayConfigStamp;
use Terminal42\NotificationCenterBundle\Parcel\Stamp\LanguageConfigStamp;
use Terminal42\NotificationCenterBundle\Parcel\Stamp\TokenCollectionStamp;
use Terminal42\NotificationCenterBundle\Token\Token;
use Terminal42\NotificationCenterBundle\Token\TokenCollection;
use NineTeufel\NotificationCenterFileGatewayBundle\Filesystem\LocalFileWriter;
use NineTeufel\NotificationCenterFileGatewayBundle\Gateway\FileGateway;
use NineTeufel\NotificationCenterFileGatewayBundle\Parcel\Stamp\FileStamp;

final class FileGatewayTest extends TestCase
{
    private string $root;
    private FileGateway $gateway;
    private Adapter $files;
    private Adapter $dbafs;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/nc-gateway-test-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/files/export', 0700, true);
        $framework = $this->createMock(ContaoFramework::class);
        $this->files = $this->createMock(Adapter::class);
        $this->dbafs = $this->createMock(Adapter::class);
        $framework->method('getAdapter')->willReturnCallback(fn ($class) => match ($class) {
            FilesModel::class => $this->files, Dbafs::class => $this->dbafs,
            default => throw new \LogicException($class),
        });
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->gateway = new FileGateway($framework, new LocalFileWriter($this->root), $this->logger);
        $simple = new SimpleTokenParser(new ExpressionLanguage());
        $legacy = $this->createMock(\Contao\InsertTags::class);
        $legacy->method('encodeHtmlAttributes')->willReturnArgument(0);
        $insert = new InsertTagParser($framework, new NullLogger(), $this->createMock(FragmentHandler::class), $legacy);
        $insert->addSubscription(new InsertTagSubscription(new class {
            public function replace(): \Contao\CoreBundle\InsertTag\InsertTagResult { return new \Contao\CoreBundle\InsertTag\InsertTagResult('20260930'); }
        }, 'replace', 'test_date', null, false, false));
        $this->gateway->setContainer(new ServiceLocator([
            'simple_token_parser' => static fn () => $simple, 'insert_tag_parser' => static fn () => $insert,
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/files/export/*') as $file) { unlink($file); }
        rmdir($this->root.'/files/export'); rmdir($this->root.'/files'); rmdir($this->root);
    }

    private function parcel(string $filename = 'order_##order_id##_{{test_date}}.txt'): Parcel
    {
        return (new Parcel(MessageConfig::fromArray(['id' => 1])))
            ->withStamp(new GatewayConfigStamp(GatewayConfig::fromArray([
                'type' => FileGateway::NAME, 'tfg_directory' => random_bytes(16), 'tfg_mode' => 'create',
            ])))
            ->withStamp(new LanguageConfigStamp(LanguageConfig::fromArray([
                'tfg_filename' => $filename, 'tfg_content' => "Name: ##name##\nDatum: {{test_date}}\n<xml>&amp;</xml>",
            ])))
            ->withStamp(new TokenCollectionStamp(new TokenCollection([
                Token::fromValue('order_id', '42'), Token::fromValue('name', 'René'),
            ])));
    }

    private function expectFolder(): void
    {
        $this->files->expects(self::once())->method('__call')
            ->with('findByUuid', self::callback(static fn ($args) => 16 === strlen($args[0])))
            ->willReturn((object) ['type' => 'folder', 'path' => 'files/export']);
    }

    public function testRealParsersSealAndSerializedParcelSendsWithoutReloadingConfig(): void
    {
        $this->expectFolder();
        $this->dbafs->expects(self::once())->method('__call')->with('addResource', ['files/export/order_42_20260930.txt']);
        $original = $this->parcel();
        $sealed = $this->gateway->sealParcel($original);
        self::assertFalse($original->isSealed());
        self::assertTrue($sealed->isSealed());
        self::assertSame('order_42_20260930.txt', $sealed->getStamp(FileStamp::class)->filename);
        self::assertSame("Name: René\nDatum: 20260930\n<xml>&amp;</xml>", $sealed->getStamp(FileStamp::class)->content);
        $restored = Parcel::fromSerialized($sealed->serialize());
        self::assertSame($sealed->getStamp(FileStamp::class)->toArray(), $restored->getStamp(FileStamp::class)->toArray());
        self::assertFalse($sealed->unseal()->hasStamp(FileStamp::class));
        self::assertTrue($this->gateway->sendParcel($restored)->wasDelivered());
        self::assertSame($restored->getStamp(FileStamp::class)->content, file_get_contents($this->root.'/files/export/order_42_20260930.txt'));
    }

    public function testInvalidTokenFilenameProducesSealingException(): void
    {
        $this->expectFolder();
        $parcel = $this->parcel('##order_id##.txt')->withStamp(new TokenCollectionStamp(new TokenCollection([
            Token::fromValue('order_id', '../outside'),
        ])));
        $this->expectException(CouldNotSealParcelException::class);
        $this->gateway->sealParcel($parcel);
    }

    public function testMissingFolderProducesSealingException(): void
    {
        $this->files->method('__call')->willReturn(null);
        $this->expectException(CouldNotSealParcelException::class);
        $this->gateway->sealParcel($this->parcel());
    }

    public function testMissingSendingStampReturnsFailedReceipt(): void
    {
        $receipt = $this->gateway->sendParcel(new Parcel(MessageConfig::fromArray([])));
        self::assertFalse($receipt->wasDelivered());
        self::assertNotNull($receipt->getException());
    }

    public function testWriteFailureReturnsFailedReceipt(): void
    {
        $parcel = (new Parcel(MessageConfig::fromArray([])))->seal()
            ->withStamp(new FileStamp('files/missing', 'export.txt', 'content', 'create'));
        $receipt = $this->gateway->sendParcel($parcel);
        self::assertFalse($receipt->wasDelivered());
        self::assertStringContainsString('selected directory', $receipt->getException()->getMessage());
    }

    public function testIndexingFailureDoesNotReportSavedFileAsFailed(): void
    {
        $this->dbafs->method('__call')->willThrowException(new \RuntimeException('DB unavailable'));
        $this->logger->expects(self::once())->method('warning');
        $parcel = (new Parcel(MessageConfig::fromArray([])))->seal()
            ->withStamp(new FileStamp('files/export', 'export.txt', 'saved', 'create'));
        self::assertTrue($this->gateway->sendParcel($parcel)->wasDelivered());
        self::assertSame('saved', file_get_contents($this->root.'/files/export/export.txt'));
    }
}
