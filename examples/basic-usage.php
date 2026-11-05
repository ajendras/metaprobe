<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MetaProbe\MetaProbe;

echo "MetaProbe Basic Usage Demo\n";
echo "=========================\n\n";

$path = __DIR__ . '/test-image.jpg';

// Create a small test image if it does not exist
if (!file_exists($path)) {
    $im = imagecreatetruecolor(200, 200);
    $bg = imagecolorallocate($im, 45, 85, 125);
    imagefill($im, 0, 0, $bg);
    imagejpeg($im, $path, 90);
    imagedestroy($im);
    echo "Created temporary test image.\n\n";
}

$metadata = MetaProbe::probe($path);
$report = MetaProbe::scan($path);
$stego = MetaProbe::detectSteganography($path);

echo $metadata->toJson() . "\n\n";

echo "Privacy Score: " . $report->getScore() . "/100\n";
if ($gps = $metadata->getGps()) {
    echo "GPS: " . (string) $gps . "\n";
    echo "Map: " . $gps->getGoogleMapsUrl() . "\n";
}

if (count($report->getRisks()) > 0) {
    echo "\nRisks:\n";
    foreach ($report->getRisks() as $risk) {
        echo " • " . $risk . "\n";
    }
} else {
    echo "\nNo privacy risks detected.\n";
}

if ($report->hasSteganographyRisk()) {
    echo "\n⚠️  STEGANOGRAPHY RISK DETECTED (unusual LSB patterns)\n";
} elseif (!empty($stego['risks'])) {
    echo "\nSteganography analysis: " . implode(', ', $stego['risks']) . "\n";
} else {
    echo "\nNo steganography indicators found.\n";
}

echo "\nDemo completed.\n";
