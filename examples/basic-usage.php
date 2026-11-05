<?php

declare(strict_types=1);

/**
 * Basic usage demonstration for the MetaProbe library.
 *
 * Shows single-file probing, privacy scanning, GPS handling,
 * sanitization and the new batch directory processing feature.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MetaProbe\MetaProbe;
use MetaProbe\Metadata;

// Single file example (replace with a real image path)
$imagePath = __DIR__ . '/../tests/fixtures/sample.jpg'; // adjust if needed

echo "=== MetaProbe Single-File Example ===\n\n";

try {
    $metadata = MetaProbe::probe($imagePath);
    $report = MetaProbe::scan($imagePath);

    echo $metadata->toJson() . "\n\n";

    if ($gps = $metadata->getGps()) {
        echo "GPS Location : " . (string) $gps . "\n";
        echo "Google Maps  : " . $gps->getGoogleMapsUrl() . "\n\n";
    }

    echo "Privacy Score: " . $report->getScore() . "/100\n";
    if (count($report->getRisks()) > 0) {
        echo "Risks:\n • " . implode("\n • ", $report->getRisks()) . "\n";
    } else {
        echo "No privacy risks detected.\n";
    }
} catch (\Throwable $e) {
    echo "Demo image not available — please supply a valid JPEG/PNG to run full example.\n";
    echo "Error was: " . $e->getMessage() . "\n";
}

echo "\n=== Batch Directory Example ===\n\n";

// Batch processes every supported image in a folder (returns summary array)
$batchDir = __DIR__;
$summary = MetaProbe::batch($batchDir, false);

echo "Processed " . count($summary) . " image(s) in directory.\n";

if (!empty($summary)) {
    $first = $summary[0];
    echo "First file   : " . $first['filename'] . "\n";
    echo "Privacy score: " . $first['privacy_score'] . "/100\n";
    echo "Camera       : " . $first['camera'] . "\n";

    if (!empty($first['gps_coordinates'])) {
        echo "GPS          : " . $first['gps_coordinates'] . "\n";
    }
}

echo "\nCSV export example:\n";
echo "MetaProbe::batch('photos', true); // then write array to CSV with fputcsv()\n";
echo "\nRun via CLI for live progress bar:\n";
echo "php bin/metaprobe batch ./photos --output=report.csv --recursive\n";
