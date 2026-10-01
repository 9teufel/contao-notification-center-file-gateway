<?php

declare(strict_types=1);

namespace NineTeufel\NotificationCenterFileGatewayBundle\Filesystem;

/** Writes only into existing local directories inside Contao's files/ tree. */
final class LocalFileWriter
{
    public const MODE_CREATE = 'create';
    public const MODE_OVERWRITE = 'overwrite';

    public function __construct(private readonly string $projectDir)
    {
    }

    public function validateFilename(string $filename): void
    {
        if ('' === $filename || strlen($filename) > 255 || str_starts_with($filename, '.')
            || preg_match('~[\\\\/\x00-\x1f\x7f]~', $filename)
            || str_ends_with($filename, '.') || str_ends_with($filename, ' ')
            || preg_match('~\.(?:php\d*|phtml|pht|phar|cgi|pl|py|sh|shtml)(?:\.|$)~i', $filename)) {
            throw new \InvalidArgumentException('Invalid or executable export filename. Use a plain basename such as export.csv.');
        }
    }

    public function validateMode(string $mode): void
    {
        if (!\in_array($mode, [self::MODE_CREATE, self::MODE_OVERWRITE], true)) {
            throw new \InvalidArgumentException('Unknown file storage mode.');
        }
    }

    public function resolveDirectory(string $directory): string
    {
        $directory = rtrim($directory, '/');
        if (str_contains($directory, '\\') || str_contains($directory, "\0")
            || ('files' !== $directory && !str_starts_with($directory, 'files/'))
            || \in_array('..', explode('/', $directory), true)
            || \in_array('.', explode('/', $directory), true)) {
            throw new \InvalidArgumentException('The export directory must be inside files/.');
        }

        $project = realpath($this->projectDir);
        $root = realpath($this->projectDir.'/files');
        $target = realpath($this->projectDir.'/'.$directory);
        if (false === $project || false === $root || false === $target || !is_dir($target)
            || !str_starts_with($root, $project.DIRECTORY_SEPARATOR)
            || ($target !== $root && !str_starts_with($target, $root.DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('The selected directory is missing or points outside the local files/ tree.');
        }

        return $target;
    }

    /** Returns the actual project-relative filename, including a collision suffix if needed. */
    public function write(string $directory, string $filename, string $content, string $mode): string
    {
        $this->validateFilename($filename);
        $this->validateMode($mode);
        $targetDir = $this->resolveDirectory($directory);
        if (!is_writable($targetDir)) {
            throw new \RuntimeException('The selected export directory is not writable.');
        }

        // Build the complete file first. Never expose partially written exports.
        $temporary = tempnam($targetDir, '.nc-export-');
        if (false === $temporary || \dirname($temporary) !== $targetDir) {
            if (false !== $temporary) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Could not create a temporary export file.');
        }

        try {
            $written = file_put_contents($temporary, $content, LOCK_EX);
            if (false === $written || $written !== strlen($content)) {
                throw new \RuntimeException('Could not write the complete export content.');
            }

            if (self::MODE_OVERWRITE === $mode) {
                $target = $targetDir.'/'.$filename;
                clearstatcache(true, $target);
                if (is_link($target) || (file_exists($target) && !is_file($target))) {
                    throw new \RuntimeException('Cannot overwrite a symbolic link or directory.');
                }
                if (!@rename($temporary, $target)) {
                    throw new \RuntimeException('Could not replace the export file.');
                }
            } else {
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $stem = '' === $extension ? $filename : substr($filename, 0, -strlen($extension) - 1);
                for ($index = 0; $index <= 10000; ++$index) {
                    $candidate = 0 === $index ? $filename : $stem.'__'.$index.('' === $extension ? '' : '.'.$extension);
                    $this->validateFilename($candidate);
                    $target = $targetDir.'/'.$candidate;
                    // link() creates atomically and fails if the name exists, also across processes.
                    if (@link($temporary, $target)) {
                        $filename = $candidate;
                        break;
                    }
                    clearstatcache(true, $target);
                    if (!file_exists($target) && !is_link($target)) {
                        throw new \RuntimeException('Could not create the export file. Local hard-link support is required.');
                    }
                    if (10000 === $index) {
                        throw new \RuntimeException('Too many files with the same export name.');
                    }
                }
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }

        return rtrim($directory, '/').'/'.$filename;
    }
}
