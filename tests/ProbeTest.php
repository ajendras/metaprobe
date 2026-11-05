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
        $this->assertStringContainsString('google.com/maps', $gps->getGoogleMapsUrl());
        $this->assertSame('51.5125000,-0.1250000', (string) $gps);
    }

    public function testGpsFromExifSupportsImagickFormats(): void
    {
        $raw = [
            'GPSLatitudeRef' => 'S',
            'GPSLatitude' => '34/1,12/1,0/1',
            'GPSLongitudeRef' => 'E',
            'GPSLongitude' => '150/1,0/1,0/1',
        ];

        $gps = Gps::fromExif($raw);
        $this->assertSame(-34.2, $gps->getLatitude());
        $this->assertSame(150.0, $gps->getLongitude());
    }

    public function testMetadataDtoProvidesAllAccessors(): void
    {
        $metadata = new Metadata(
            exif: ['Camera' => 'Canon EOS R5'],
            iptc: ['keywords' => ['test']],
            xmp: ['creator' => 'Alice'],
            gps: Gps::fromExif([
                'GPSLatitudeRef' => 'N',
                'GPSLatitude' => ['51/1', '30/1', '0/1'],
                'GPSLongitudeRef' => 'E',
                'GPSLongitude' => ['0/1', '7/1', '0/1'],
            ])
        );

        $this->assertArrayHasKey('Camera', $metadata->getExif());
        $this->assertSame(['keywords' => ['test']], $metadata->getIptc());
        $this->assertSame(['creator' => 'Alice'], $metadata->getXmp());
        $this->assertInstanceOf(Gps::class, $metadata->getGps());
        $this->assertIsInt($metadata->getPrivacyScore());
        $this->assertIsArray($metadata->toArray());
        $this->assertStringContainsString('privacy_score', $metadata->toJson());
    }

    public function testScannerDetectsRisksAndComputesScore(): void
    {
        $scanner = new Scanner();
        $metadata = new Metadata(
            exif: [
                'GPSLatitude' => '51/1',
                'GPSLongitude' => '0/1',
                'Make' => 'Apple',
                'Model' => 'iPhone 14',
                'Artist' => 'John Doe',
            ],
            gps: Gps::fromExif([
                'GPSLatitudeRef' => 'N',
                'GPSLatitude' => ['51/1', '30/1', '0/1'],
                'GPSLongitudeRef' => 'W',
                'GPSLongitude' => ['0/1', '7/1', '0/1'],
            ])
        );

        $risks = $scanner->getRisks($metadata);
        $this->assertNotEmpty($risks);
        $this->assertStringContainsString('GPS', $risks[0]);
        $this->assertStringContainsString('Device', implode(' ', $risks));

        $score = $scanner->calculateScore($metadata);
        $this->assertLessThan(100, $score);
        $this->assertGreaterThanOrEqual(0, $score);
    }

    public function testSanitizerProducesSmallerOrEqualFileWithoutMetadata(): void
    {
        $sanitizer = new Sanitizer();
        $original = __DIR__ . '/fixtures/test.jpg';

        if (!file_exists($original)) {
            $this->markTestSkipped('Test fixture missing');
        }

        $clean = tempnam(sys_get_temp_dir(), 'sanitized_') . '.jpg';
        $success = $sanitizer->sanitize($original, $clean);

        $this->assertTrue($success);
        $this->assertFileExists($clean);
        $this->assertLessThanOrEqual(filesize($original), filesize($clean));

        @unlink($clean);
    }

    public function testFacadeProbeAndScanReturnConsistentObjects(): void
    {
        $path = __DIR__ . '/fixtures/test.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Test fixture missing');
        }

        $metadata = MetaProbe::probe($path);
        $report = MetaProbe::scan($path);

        $this->assertInstanceOf(Metadata::class, $metadata);
        $this->assertInstanceOf(SecurityReport::class, $report);
        $this->assertSame($metadata, $report->getMetadata());
    }

    public function testFindImagesReturnsOnlySupportedImageFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/metaprobe_find_test_' . bin2hex(random_bytes(6));
        mkdir($tempDir);

        touch($tempDir . '/photo.jpg');
        touch($tempDir . '/document.pdf');
        touch($tempDir . '/image.png');
        touch($tempDir . '/archive.zip');
        touch($tempDir . '/photo.webp');

        $images = MetaProbe::findImages($tempDir);

        $this->assertCount(3, $images);
        $basenames = array_map('basename', $images);
        $this->assertContains('photo.jpg', $basenames);
        $this->assertContains('image.png', $basenames);
        $this->assertContains('photo.webp', $basenames);
        $this->assertNotContains('document.pdf', $basenames);

        foreach (glob($tempDir . '/*') as $f) {
            unlink($f);
        }
        rmdir($tempDir);
    }

    public function testFindImagesSupportsRecursiveMode(): void
    {
        $tempDir = sys_get_temp_dir() . '/metaprobe_recursive_test_' . bin2hex(random_bytes(6));
        mkdir($tempDir);
        mkdir($tempDir . '/sub');

        touch($tempDir . '/top.jpg');
        touch($tempDir . '/sub/nested.png');

        $images = MetaProbe::findImages($tempDir, true);

        $this->assertCount(2, $images);
        $paths = implode(' ', $images);
        $this->assertStringContainsString('top.jpg', $paths);
        $this->assertStringContainsString('nested.png', $paths);

        foreach (glob($tempDir . '/sub/*') as $f) {
            unlink($f);
        }
        rmdir($tempDir . '/sub');
        foreach (glob($tempDir . '/*') as $f) {
            unlink($f);
        }
        rmdir($tempDir);
    }

    public function testBatchReturnsStructuredSummaryForDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/metaprobe_batch_test_' . bin2hex(random_bytes(6));
        mkdir($tempDir);
        touch($tempDir . '/test1.jpg');
        touch($tempDir . '/test2.png');

        $summary = MetaProbe::batch($tempDir);

        $this->assertIsArray($summary);
        $this->assertCount(2, $summary);

        $first = $summary[0];
        $this->assertArrayHasKey('filename', $first);
        $this->assertArrayHasKey('privacy_score', $first);
        $this->assertArrayHasKey('has_gps', $first);
        $this->assertArrayHasKey('risk_details', $first);

        foreach (glob($tempDir . '/*') as $f) {
            unlink($f);
        }
        rmdir($tempDir);
    }

    public function testBatchThrowsOnInvalidDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MetaProbe::batch('/path/that/does/not/exist');
    }
}
