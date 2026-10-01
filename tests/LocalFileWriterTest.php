<?php

declare(strict_types=1);

namespace NineTeufel\NotificationCenterFileGatewayBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use NineTeufel\NotificationCenterFileGatewayBundle\Filesystem\LocalFileWriter;

final class LocalFileWriterTest extends TestCase
{
    private string $root;
    private LocalFileWriter $writer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/nc-file-test-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/files/export', 0700, true);
        $this->writer = new LocalFileWriter($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/files/export/{*,.*}', GLOB_BRACE) as $path) {
            if ('.' === basename($path) || '..' === basename($path)) {
                continue;
            }
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
            } else {
                rmdir($path);
            }
        }
        if (is_link($this->root.'/files/outside')) {
            unlink($this->root.'/files/outside');
        }
        @unlink($this->root.'/outside.txt');
        rmdir($this->root.'/files/export');
        rmdir($this->root.'/files');
        rmdir($this->root);
    }

    public function testNewFilesDoNotOverwriteAndKeepBytesUnchanged(): void
    {
        $content = "äöü;\"quoted\"\r\n<root>&amp;</root>\n";
        self::assertSame('files/export/test.csv', $this->writer->write('files/export', 'test.csv', $content, 'create'));
        self::assertSame('files/export/test__1.csv', $this->writer->write('files/export', 'test.csv', 'second', 'create'));
        self::assertSame('files/export/test__2.csv', $this->writer->write('files/export', 'test.csv', '', 'create'));
        self::assertSame($content, file_get_contents($this->root.'/files/export/test.csv'));
        self::assertSame('second', file_get_contents($this->root.'/files/export/test__1.csv'));
        self::assertSame('', file_get_contents($this->root.'/files/export/test__2.csv'));
        self::assertSame([], glob($this->root.'/files/export/.nc-export-*'));
    }

    public function testOverwriteReplacesWholeFileAndCanCreateMissingFile(): void
    {
        $this->writer->write('files/export', 'request.txt', str_repeat('old', 100), 'overwrite');
        $this->writer->write('files/export', 'request.txt', 'new', 'overwrite');
        self::assertSame('new', file_get_contents($this->root.'/files/export/request.txt'));
        self::assertSame(['request.txt'], array_map('basename', glob($this->root.'/files/export/*')));
    }

    public function testExtensionlessNamesAndMultipleDots(): void
    {
        foreach (['export', 'data.backup.json'] as $name) {
            $this->writer->write('files/export/', $name, 'a', 'create');
            $result = $this->writer->write('files/export', $name, 'b', 'create');
            self::assertSame('files/export/'.('export' === $name ? 'export__1' : 'data.backup__1.json'), $result);
        }
    }

    public static function invalidNames(): array
    {
        return array_map(static fn ($name) => [$name], ['', '../test', 'sub/file.csv', 'sub\\file.csv', '.htaccess', '.hidden', "x\0.csv", "x\n.csv", 'test.php', 'test.PHP8.csv', 'file.phar', 'test.', 'test ', str_repeat('a', 256)]);
    }

    #[DataProvider('invalidNames')]
    public function testInvalidFilenameFailsWithoutWriting(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->write('files/export', $name, 'x', 'create');
    }

    public function testOutsideAndMissingFoldersAreRejected(): void
    {
        foreach (['../', '/tmp', 'files/../', 'files/missing'] as $folder) {
            try {
                $this->writer->resolveDirectory($folder);
                self::fail('Unsafe directory accepted: '.$folder);
            } catch (\InvalidArgumentException|\RuntimeException) {
                self::assertTrue(true);
            }
        }
        symlink(sys_get_temp_dir(), $this->root.'/files/outside');
        $this->expectException(\RuntimeException::class);
        $this->writer->resolveDirectory('files/outside');
    }

    public function testOverwriteRejectsSymlinkWithoutChangingItsTarget(): void
    {
        file_put_contents($this->root.'/outside.txt', 'untouched');
        symlink($this->root.'/outside.txt', $this->root.'/files/export/link.txt');
        try {
            $this->writer->write('files/export', 'link.txt', 'changed', 'overwrite');
            self::fail('Symlink was accepted.');
        } catch (\RuntimeException) {
            self::assertSame('untouched', file_get_contents($this->root.'/outside.txt'));
            self::assertSame([], glob($this->root.'/files/export/.nc-export-*'));
        }
    }

    public function testNewFileSkipsExistingDirectoriesAndBrokenSymlinks(): void
    {
        mkdir($this->root.'/files/export/export.csv');
        symlink($this->root.'/missing', $this->root.'/files/export/export__1.csv');
        self::assertSame('files/export/export__2.csv', $this->writer->write('files/export', 'export.csv', 'ok', 'create'));
    }

    public function testAppendIsNotAnAcceptedMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->write('files/export', 'export.txt', 'x', 'append');
    }

    public function testParallelCreatesHaveUniqueCompleteFiles(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required for the concurrent writer test.');
        }
        $children = [];
        for ($i = 0; $i < 8; ++$i) {
            $pid = pcntl_fork();
            if (-1 === $pid) {
                self::fail('fork failed');
            }
            if (0 === $pid) {
                try {
                    $this->writer->write('files/export', 'parallel.txt', str_repeat((string) $i, 100000), 'create');
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        $files = glob($this->root.'/files/export/parallel*.txt');
        self::assertCount(8, $files);
        $contents = array_map('file_get_contents', $files);
        self::assertCount(8, array_unique($contents));
        foreach ($contents as $content) {
            self::assertSame(100000, strlen($content));
            self::assertSame(str_repeat($content[0], 100000), $content);
        }
    }
}
