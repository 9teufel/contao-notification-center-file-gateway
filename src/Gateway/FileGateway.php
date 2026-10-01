<?php

declare(strict_types=1);

namespace NineTeufel\NotificationCenterFileGatewayBundle\Gateway;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Dbafs;
use Contao\FilesModel;
use Psr\Log\LoggerInterface;
use Terminal42\NotificationCenterBundle\Exception\Parcel\CouldNotDeliverParcelException;
use Terminal42\NotificationCenterBundle\Exception\Parcel\CouldNotSealParcelException;
use Terminal42\NotificationCenterBundle\Gateway\AbstractGateway;
use Terminal42\NotificationCenterBundle\Parcel\Parcel;
use Terminal42\NotificationCenterBundle\Parcel\Stamp\GatewayConfigStamp;
use Terminal42\NotificationCenterBundle\Parcel\Stamp\LanguageConfigStamp;
use Terminal42\NotificationCenterBundle\Parcel\Stamp\TokenCollectionStamp;
use Terminal42\NotificationCenterBundle\Receipt\Receipt;
use NineTeufel\NotificationCenterFileGatewayBundle\Filesystem\LocalFileWriter;
use NineTeufel\NotificationCenterFileGatewayBundle\Parcel\Stamp\FileStamp;

final class FileGateway extends AbstractGateway
{
    public const NAME = '9teufel_file';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LocalFileWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getRequiredStampsForSealing(): array
    {
        return [GatewayConfigStamp::class, LanguageConfigStamp::class, TokenCollectionStamp::class];
    }

    protected function getRequiredStampsForSending(): array
    {
        return [FileStamp::class];
    }

    protected function doSealParcel(Parcel $parcel): Parcel
    {
        try {
            $this->framework->initialize();
            $config = $parcel->getStamp(GatewayConfigStamp::class)->gatewayConfig;
            $language = $parcel->getStamp(LanguageConfigStamp::class)->languageConfig;
            $uuid = $config->getString('tfg_directory');
            $folder = '' === $uuid ? null : $this->framework->getAdapter(FilesModel::class)->findByUuid($uuid);
            if (null === $folder || 'folder' !== $folder->type) {
                throw new \RuntimeException('Select an existing export folder using the file picker.');
            }

            $directory = (string) $folder->path;
            $filename = $this->replaceTokensAndInsertTags($parcel, $language->getString('tfg_filename'));
            $content = $this->replaceTokensAndInsertTags($parcel, $language->getString('tfg_content'));
            $mode = $config->getString('tfg_mode', LocalFileWriter::MODE_CREATE);
            $this->writer->resolveDirectory($directory);
            $this->writer->validateFilename($filename);
            $this->writer->validateMode($mode);

            return $parcel->seal()->withStamp(new FileStamp($directory, $filename, $content, $mode));
        } catch (\Throwable $e) {
            throw new CouldNotSealParcelException('Could not prepare file export: '.$e->getMessage(), 0, $e);
        }
    }

    protected function doSendParcel(Parcel $parcel): Receipt
    {
        try {
            $stamp = $parcel->getStamp(FileStamp::class);
            $path = $this->writer->write($stamp->directory, $stamp->filename, $stamp->content, $stamp->mode);
        } catch (\Throwable $e) {
            return Receipt::createForUnsuccessfulDelivery($parcel,
                CouldNotDeliverParcelException::becauseOfGatewayException(self::NAME, 0, $e));
        }

        // Indexing must not turn a successful write into a failed delivery (and a duplicate on retry).
        try {
            $this->framework->initialize();
            $this->framework->getAdapter(Dbafs::class)->addResource($path);
        } catch (\Throwable $e) {
            $this->logger->warning('File export was saved but could not be indexed in Contao. Synchronize the file manager.',
                ['path' => $path, 'exception' => $e]);
        }

        return Receipt::createForSuccessfulDelivery($parcel);
    }
}
