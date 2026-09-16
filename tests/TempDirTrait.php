<?php
declare(strict_types=1);

namespace ManorLedger\Tests;

trait TempDirTrait
{
    private string $tempDir = '';

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/manor-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tempDir = $dir;
        return $dir;
    }

    protected function removeTempDir(): void
    {
        if ($this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tempDir);
    }
}
