<?php

declare(strict_types=1);

namespace MetaProbe\Tests;

use InvalidArgumentException;
use MetaProbe\Gps;
use MetaProbe\MetaProbe;
use MetaProbe\Metadata;
use MetaProbe\Security\Sanitizer;
use MetaProbe\Security\Scanner;
use MetaProbe\Security\SecurityReport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Comprehensive test suite for MetaProbe core functionality.
 *
 * Covers GPS parsing, Metadata DTO behavior, Scanner risk detection and scoring,
 * Sanitizer metadata removal, and integration via the MetaProbe facade.
 */
final class ProbeTest extends TestCase
{
    public function testGpsFromExifConvertsDmsCorrectly(): void
    {
        $raw = [
            'GPSLatitudeRef' => 'N',
            'GPSLatitude' => ['51/1', '30/1', '45/1'],
            'GPSLongitudeRef' => 'W',
            'GPSLongitude' => ['0/1', '7/1', '30/1'],
        ];

        $gps = Gps::fromExif($raw);

        $this->assertSame(51.5125, $gps->getLatitude());
        $this->assertSame(-0.125, $gps->getLongitude());

        $decimal = $gps->getDecimal();
        $this->assertSame(51.5125, $decimal['lat']);
        $this->assertSame(-0.125, $decimal['lng']);

        $this->assertSame('51.5125000,-0.1250000', (string) $gps);
        $this->assertStringContainsString('google.com/maps', $gps->getGoogleMapsUrl());
    }

    public function testGpsFromExifHandlesFlatStructureAndRationals(): void
    {
        $raw = [
            'LatitudeRef' => 'S',
            'Latitude' => ['33/1', '52/1', '0/1'],
            'LongitudeRef' => 'E',
            'Longitude' => ['151/1', '12/1', '0/1'],
        ];

        $gps = Gps::fromExif($raw);

        $this->assertSame(-33.86666667, $gps->getLatitude());
        $this->assertSame(151.2, $gps->getLongitude());
    }

    public function testGpsThrowsOnMissingCoordinates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GPS coordinate data is missing or invalid');

        Gps::fromExif(['GPSLatitudeRef' => 'N']);
    }

    public function testMetadataDtoProvidesAccessorsAndSerialization(): void
    {
        $exif = ['Camera' => 'Canon EOS R5', 'ExposureTime' => '1/100'];
        $iptc = ['author' => 'Test Author', 'keywords' => ['test']];
        $xmp = ['creatorTool' => 'Adobe Photoshop'];

        $gps = Gps::fromExif([
            'GPSLatitudeRef' => 'N',
            'GPSLatitude' => ['40/1', '0/1', '0/1'],
            'GPSLongitudeRef' => 'W',
            'GPSLongitude' => ['74/1', '0/1', '0/1'],
        ]);

        $metadata = new Metadata(
            exif: $exif,
            iptc: $iptc,
            xmp: $xmp,
            gps: $gps
        );

        $this->assertSame($exif, $metadata->getExif());
        $this->assertSame($iptc, $metadata->getIptc());
        $this->assertSame($xmp, $metadata->getXmp());
        $this->assertSame($gps, $metadata->getGps());

        $array = $metadata->toArray();
        $this->assertArrayHasKey('exif', $array);
        $this->assertArrayHasKey('gps', $array);
        $this->assertArrayHasKey('google_maps_url', $array);
        $this->assertArrayHasKey('privacy_score', $array);
        $this->assertIsInt($array['privacy_score']);

        $json = $metadata->toJson();
        $this->assertIsString($json);
        $this->assertStringContainsString('Canon EOS R5', $json);
        $this->assertStringContainsString('privacy_score', $json);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
    }

    public function testScannerDetectsRisksAndComputesScore(): void
    {
        $metadata = new Metadata(
            exif: [
                'Make' => 'Apple',
                'Model' => 'iPhone 14',
                'GPSLatitude' => '40/1',
                'GPSLatitudeRef' => 'N',
                'GPSLongitude' => '74/1',
                'GPSLongitudeRef' => 'W',
            ],
            iptc: [
                'author' => 'John Doe',
                'city' => 'New York',
            ],
            xmp: [
                'creator' => 'John Doe',
            ]
        );

        $scanner = new Scanner();
        $risks = $scanner->getRisks($metadata);

        $this->assertCount(4, $risks);
        $this->assertContains('GPS coordinates present (high privacy risk)', $risks);
        $this->assertStringContainsString('Author/creator information', implode('', $risks));
        $this->assertStringContainsString('Device fingerprint', implode('', $risks));

        $score = $scanner->calculateScore($metadata);
        $this->assertLessThanOrEqual(30, $score);
        $this->assertGreaterThanOrEqual(0, $score);
    }

    public function testScannerGivesPerfectScoreForCleanImage(): void
    {
        $metadata = new Metadata(
            exif: ['DateTime' => '2023:01:01 12:00:00'],
            iptc: ['copyright' => '© Public Domain'],
            xmp: []
        );

        $scanner = new Scanner();
        $risks = $scanner->getRisks($metadata);
        $score = $scanner->calculateScore($metadata);

        $this->assertEmpty($risks);
        $this->assertSame(100, $score);
    }

    public function testIsSensitiveCorrectlyIdentifiesRisks(): void
    {
        $scanner = new Scanner();

        $this->assertTrue($scanner->isSensitive('GPSLatitude', '51/1'));
        $this->assertTrue($scanner->isSensitive('author', 'John Smith'));
        $this->assertTrue($scanner->isSensitive('Model', 'iPhone 14'));
        $this->assertTrue($scanner->isSensitive('city', 'London'));

        $this->assertFalse($scanner->isSensitive('ExposureTime', '1/100'));
        $this->assertFalse($scanner->isSensitive('ImageWidth', 1920));
        $this->assertFalse($scanner->isSensitive('title', ''));
    }

    public function testSanitizerRemovesMetadataAndPreservesImage(): void
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'metaprobe_test_in_') . '.jpg';
        $outputPath = tempnam(sys_get_temp_dir(), 'metaprobe_test_out_') . '.jpg';

        // Create a simple test image
        $image = imagecreatetruecolor(200, 200);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        imagestring($image, 5, 50, 90, 'MetaProbe Test', 0);
        imagejpeg($image, $inputPath, 90);
        imagedestroy($image);

        $originalSize = filesize($inputPath);

        $sanitizer = new Sanitizer();
        $result = $sanitizer->sanitize($inputPath, $outputPath);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);

        $sanitizedSize = filesize($outputPath);
        $this->assertGreaterThan(100, $sanitizedSize);

        // Verify the output can be read as an image and has no EXIF (best effort)
        $this->assertTrue($this->isValidJpeg($outputPath));

        $exifAfter = @exif_read_data($outputPath);
        // Many sanitized images have no EXIF; we accept either no data or very minimal
        if ($exifAfter !== false) {
            $this->assertArrayNotHasKey('Make', $exifAfter);
            $this->assertArrayNotHasKey('Model', $exifAfter);
        }

        unlink($inputPath);
        unlink($outputPath);
    }

    public function testMetaProbeFacadeProbeAndScan(): void
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'metaprobe_facade_') . '.jpg';

        $image = imagecreatetruecolor(100, 100);
        imagejpeg($image, $inputPath, 80);
        imagedestroy($image);

        $metadata = MetaProbe::probe($inputPath);
        $this->assertInstanceOf(Metadata::class, $metadata);

        $report = MetaProbe::scan($inputPath);
        $this->assertInstanceOf(SecurityReport::class, $report);
        $this->assertIsInt($report->getScore());
        $this->assertIsArray($report->getRisks());
        $this->assertInstanceOf(Metadata::class, $report->getMetadata());

        $this->assertIsArray($metadata->toArray());

        unlink($inputPath);
    }

    public function testMetaProbeThrowsOnInvalidFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File does not exist');

        MetaProbe::probe('/tmp/nonexistent-metaprobe-test-image.jpg');
    }

    public function testMetaProbeSanitizeCreatesCleanOutput(): void
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'metaprobe_sanitize_in_') . '.jpg';
        $outputPath = tempnam(sys_get_temp_dir(), 'metaprobe_sanitize_out_') . '.jpg';

        $image = imagecreatetruecolor(80, 80);
        imagejpeg($image, $inputPath, 90);
        imagedestroy($image);

        $result = MetaProbe::sanitize($inputPath, $outputPath);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);
        $this->assertTrue($this->isValidJpeg($outputPath));

        unlink($inputPath);
        unlink($outputPath);
    }

    /**
     * Helper to verify a file is a valid JPEG by attempting to create an image resource.
     */
    private function isValidJpeg(string $path): bool
    {
        if (!file_exists($path) || filesize($path) < 100) {
            return false;
        }

        $img = @imagecreatefromjpeg($path);
        if ($img === false) {
            return false;
        }

        imagedestroy($img);
        return true;
    }
}
