<?php

declare(strict_types=1);
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

// No Laravel boot, credentials, paths or document contents in output. The parent
// imposes a wall-clock timeout and starts this process with a 128 MiB memory cap.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
try {
    $bytes = stream_get_contents(STDIN);
    if (($argv[1] ?? '') !== 'application/pdf') {
        $image = @imagecreatefromstring($bytes);
        exit($image === false ? 1 : 0);
    }
    require dirname(__DIR__).'/vendor/autoload.php';
    $config = new Config;
    $config->setDecodeMemoryLimit(16 * 1024 * 1024);
    $config->setMaxNestingDepth(128);
    $config->setRetainImageContent(false);
    $document = (new Parser([], $config))->parseContent($bytes);
    $pages = $document->getPages();
    exit(count($pages) > 0 && count($pages) <= 500 ? 0 : 1);
} catch (Throwable) {
    exit(1);
}
