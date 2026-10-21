<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3) exit(1);
if (!class_exists(ZipArchive::class)) throw new RuntimeException('The PHP ZIP extension is required to build a ZIP release.');
$source = realpath($argv[1]);
if ($source === false || !is_dir($source)) throw new RuntimeException('Invalid package staging directory.');
$archive = new ZipArchive();
if ($archive->open($argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create ZIP package.');
try {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->isLink()) throw new RuntimeException('Only regular runtime files may be packaged.');
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
        if (!$archive->addFile($file->getPathname(), $relative)) throw new RuntimeException('Cannot add runtime file to ZIP.');
        $mode = str_ends_with($relative, '.sh') || $relative === 'bin/console' ? 0100755 : 0100644;
        $archive->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, $mode << 16);
    }
} finally {
    if (!$archive->close()) throw new RuntimeException('Cannot finalize ZIP package.');
}
