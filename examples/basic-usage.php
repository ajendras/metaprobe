<?php

declare(strict_types=1);

/**
 * MetaProbe — Basic Usage Example
 *
 * Demonstrates the primary facade API for metadata extraction,
 * privacy scanning, and reporting. Run from the command line with
 * an image path that contains EXIF, IPTC or XMP data for best results.
 *
 * Usage: php examples/basic-usage.php path/to/image.jpg
 */

namespace MetaProbe\Example;

use MetaProbe\MetaProbe;

if (PHP_SAPI !== 'cli') {
    echo "This example must be run from the command line.\n";
    exit(1);
}

// Support both direct execution and Composer-based usage
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$autoloadFound = false;
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    fwrite(STDERR, "Autoloader not found. Please run 'composer install' first.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " <image-path>\n");
    fwrite(STDERR, "Example: php " . basename(__FILE__) . " sample-with-exif.jpg\n");
    exit(1);
}

$path = $argv[1];

if (!file_exists($path) || !is_readable($path)) {
    fwrite(STDERR, "Error: Cannot read file '$path'.\n");
    exit(1);
}

try {
    $metadata = MetaProbe::probe($path);
    $report = MetaProbe::scan($path);

    $exif = $metadata->getExif();
    $gps = $metadata->getGps();

    echo "\nMetaProbe Analysis\n";
    echo str_repeat('=', 50) . "\n";
    echo "File          : " . basename($path) . "\n";
    echo "Privacy Score : " . $report->getScore() . "/100\n\n";

    if (!empty($exif['Camera'] ?? '')) {
        echo "Camera        : " . $exif['Camera'] . "\n";
    }

    if (!empty($exif['DateTimeOriginal'] ?? '')) {
        echo "Taken         : " . $exif['DateTimeOriginal'] . "\n";
    }

    if (!empty($exif['Exposure'] ?? '')) {
        echo "Exposure      : " . $exif['Exposure'] . "\n";
    }

    if ($gps !== null) {
        echo "GPS           : " . (string) $gps . "\n";
        echo "Google Maps   : " . $gps->getGoogleMapsUrl() . "\n";
    }

    $risks = $report->getRisks();
    if (count($risks) > 0) {
        echo "\nPrivacy Risks (" . count($risks) . "):\n";
        foreach ($risks as $risk) {
            echo " • " . $risk . "\n";
        }
    } else {
        echo "\nNo privacy risks detected — image is clean.\n";
    }

    echo "\nFull structured data available via:\n";
    echo "  \$metadata->toArray() or \$metadata->toJson()\n";
    echo "  \$metadata->getPrivacyScore()\n\n";

    // Optional: demonstrate sanitization
    echo "To sanitize this image run:\n";
    echo "  php bin/metaprobe probe " . basename($path) . " --sanitize\n\n";
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "Unexpected error: " . $e->getMessage() . "\n");
    exit(1);
}
