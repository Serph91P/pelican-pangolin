<?php

declare(strict_types=1);

if ($argc !== 3 || ! is_dir($argv[1])) {
    fwrite(STDERR, "Usage: build-archive.php SOURCE OUTPUT\n");
    exit(2);
}

$source = realpath($argv[1]);
$zip = new ZipArchive;
if ($source === false || $zip->open($argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to create plugin archive.\n");
    exit(1);
}

$zip->addEmptyDir('pelican-pangolin');
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($source) + 1);
    $archivePath = 'pelican-pangolin/'.str_replace(DIRECTORY_SEPARATOR, '/', $relative);

    if ($file->isDir()) {
        $zip->addEmptyDir($archivePath);
    } else {
        $zip->addFile($file->getPathname(), $archivePath);
    }
}

if (! $zip->close()) {
    fwrite(STDERR, "Unable to finalize plugin archive.\n");
    exit(1);
}
