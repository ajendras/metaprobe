<?php

declare(strict_types=1);

namespace MetaProbe\Tests;

use MetaProbe\Gps;
use MetaProbe\MetaProbe;
use MetaProbe\Security\Scanner;
use MetaProbe\Security\SecurityReport;
use MetaProbe\Security\SteganographyDetector;
use PHPUnit\Framework\TestCase;

class ProbeTest extends TestCase
{
    public function testGpsFromExif(): void
    {
        $gps = Gps::fromExif([
            'GPSLatitude' => ['51/1', '30/1', '0/1'],
            'GPSLatitudeRef' => 'N',
            'GPSLongitude' => ['0/1', '7/1', '30/1'],
            'GPSLongitudeRef' => 'W',
        ]);

        $this->assertEquals(51.5, $gps->getLatitude());
        $this->assertEquals(-0.125, $gps->getLongitude());
        $this->assertStringContainsString('51.5000000,-0.1250000', (string) $gps);
    }

    public function testMetadataToArrayIncludesPrivacyScore(): void
    {
        $metadata = MetaProbe::probe(__DIR__ . '/../examples/test.jpg'); // placeholder path for test
        $array = $metadata->toArray();

        $this->assertArrayHasKey('privacy_score', $array);
        $this->assertIsInt($array['privacy_score']);
    }

    public function testScannerReturnsRisksAndScore(): void
    {
        $scanner = new Scanner();
        $metadata = MetaProbe::probe(__DIR__ . '/../examples/test.jpg');

        $risks = $scanner->getRisks($metadata);
        $score = $scanner->calculateScore($metadata);

        $this->assertIsArray($risks);
        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testSteganographyDetectorOnUniformImage(): void
    {
        $detector = new SteganographyDetector();

        $image = imagecreatetruecolor(80, 80);
        $color = imagecolorallocate($image, 0, 0, 0); // LSB = 0 for all channels
        imagefill($image, 0, 0, $color);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $tmp = tempnam(sys_get_temp_dir(), 'uniform_') . '.png';
        file_put_contents($tmp, $png);

        $result = $detector->analyze($tmp);
        unlink($tmp);

        $this->assertEmpty($result['risks'], 'Uniform LSB image should not be flagged');
        $this->assertEquals(0, $result['scorePenalty']);
    }

    public function testSteganographyDetectorOnRandomLsb(): void
    {
        $detector = new SteganographyDetector();

        $image = imagecreatetruecolor(120, 120);
        for ($y = 0; $y < 120; $y++) {
            for ($x = 0; $x < 120; $x++) {
                $color = imagecolorallocate($image, rand(0, 255), rand(0, 255), rand(0, 255));
                imagesetpixel($image, $x, $y, $color);
            }
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $tmp = tempnam(sys_get_temp_dir(), 'random_') . '.png';
        file_put_contents($tmp, $png);

        $result = $detector->analyze($tmp);
        unlink($tmp);

        $this->assertNotEmpty($result['risks'], 'Random LSB image should trigger steganography warning');
        $this->assertGreaterThan(0, $result['scorePenalty']);
    }

    public function testScanIncludesSteganographyCheck(): void
    {
        $image = imagecreatetruecolor(60, 60);
        $color = imagecolorallocate($image, 64, 128, 192); // mixed LSB
        imagefill($image, 0, 0, $color);

        ob_start();
        imagejpeg($image, null, 90);
        $jpg = ob_get_clean();
        imagedestroy($image);

        $tmp = tempnam(sys_get_temp_dir(), 'scan_test_') . '.jpg';
        file_put_contents($tmp, $jpg);

        $report = MetaProbe::scan($tmp);
        unlink($tmp);

        $this->assertInstanceOf(SecurityReport::class, $report);
        $this->assertIsInt($report->getScore());
        $this->assertIsArray($report->getRisks());
        $this->assertIsBool($report->hasSteganographyRisk());
    }

    public function testDetectSteganographyFacadeMethod(): void
    {
        $image = imagecreatetruecolor(50, 50);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $tmp = tempnam(sys_get_temp_dir(), 'facade_') . '.png';
        file_put_contents($tmp, $png);

        $result = MetaProbe::detectSteganography($tmp);
        unlink($tmp);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('risks', $result);
        $this->assertArrayHasKey('scorePenalty', $result);
    }
}
