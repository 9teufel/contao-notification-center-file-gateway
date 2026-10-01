<?php

declare(strict_types=1);

namespace NineTeufel\NotificationCenterFileGatewayBundle\Parcel\Stamp;

use Terminal42\NotificationCenterBundle\Parcel\Stamp\StampInterface;

final class FileStamp implements StampInterface
{
    public function __construct(
        public readonly string $directory,
        public readonly string $filename,
        public readonly string $content,
        public readonly string $mode,
    ) {
    }

    public function toArray(): array
    {
        return ['directory' => $this->directory, 'filename' => $this->filename,
            'content' => $this->content, 'mode' => $this->mode];
    }

    public static function fromArray(array $data): self
    {
        foreach (['directory', 'filename', 'content', 'mode'] as $key) {
            if (!isset($data[$key]) || !\is_string($data[$key])) {
                throw new \InvalidArgumentException('Invalid file stamp: '.$key);
            }
        }

        return new self($data['directory'], $data['filename'], $data['content'], $data['mode']);
    }
}
