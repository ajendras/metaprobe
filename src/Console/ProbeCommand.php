<?php

declare(strict_types=1);

namespace MetaProbe\Console;

use MetaProbe\MetaProbe;
use MetaProbe\Security\SecurityReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symfony Console command that extracts metadata, runs privacy analysis,
 * and optionally sanitizes an image.
 */
final class ProbeCommand extends Command
{
    protected static $defaultName = 'probe';
    protected static $defaultDescription = 'Extract metadata and run privacy scan on an image';

    protected function configure(): void
    {
        $this
            ->setName('probe')
            ->setDescription(self::$defaultDescription)
            ->setHelp('Probe an image file for EXIF, IPTC, XMP metadata, GPS coordinates and privacy risks.')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the image file to analyze')
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format (pretty|json)',
                'pretty'
            )
            ->addOption(
                'sanitize',
                null,
                InputOption::VALUE_NONE,
                'Also create a sanitized copy of the image (strips PII)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $format = (string) $input->getOption('format');
        $shouldSanitize = (bool) $input->getOption('sanitize');

        if (!in_array($format, ['pretty', 'json'], true)) {
            $io->error('Invalid format. Allowed values: pretty, json');
            return Command::INVALID;
        }

        if (!file_exists($file) || !is_file($file) || !is_readable($file)) {
            $io->error(sprintf('Cannot read image file: %s', $file));
            return Command::FAILURE;
        }

        try {
            $metadata = MetaProbe::probe($file);
            $report = MetaProbe::scan($file);

            if ($format === 'json') {
                $output->writeln($metadata->toJson());
            } else {
                $this->renderPrettyReport($report, $file, $io);
            }

            if ($shouldSanitize) {
                $this->performSanitization($file, $io);
            }

            return Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Unexpected error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Renders a human-friendly report using Symfony Table and styled output.
     */
    private function renderPrettyReport(SecurityReport $report, string $file, SymfonyStyle $io): void
    {
        $metadata = $report->getMetadata();
        $gps = $metadata->getGps();

        $io->title('MetaProbe • ' . basename($file));
        $io->newLine();

        $table = new Table($io);
        $table->setHeaders(['Property', 'Value']);
        $table->setStyle('box');

        $exif = $metadata->getExif();
        if (!empty($exif['Camera'] ?? '')) {
            $table->addRow(['Camera', $exif['Camera']]);
        }
        if (!empty($exif['DateTimeOriginal'] ?? '')) {
            $table->addRow(['Date Taken', $exif['DateTimeOriginal']]);
        }
        if (!empty($exif['Exposure'] ?? '')) {
            $table->addRow(['Exposure', $exif['Exposure']]);
        }
        if (!empty($exif['Lens'] ?? '')) {
            $table->addRow(['Lens', $exif['Lens']]);
        }

        if ($gps !== null) {
            $table->addRow(['GPS', (string) $gps]);
            $table->addRow(['Google Maps', $gps->getGoogleMapsUrl()]);
        }

        $table->addRow(['Privacy Score', $report->getScore() . '/100']);

        $table->render();

        $risks = $report->getRisks();
        if (count($risks) > 0) {
            $io->newLine();
            $io->warning('Privacy Risks Detected');
            foreach ($risks as $risk) {
                $io->writeln(' • ' . $risk);
            }
        } else {
            $io->newLine();
            $io->success('No privacy risks detected');
        }

        $io->newLine();
    }

    /**
     * Runs sanitization and reports the result.
     */
    private function performSanitization(string $file, SymfonyStyle $io): void
    {
        $sanitizedPath = $this->buildSanitizedPath($file);

        $io->comment('Sanitizing image (removing metadata)...');

        $success = MetaProbe::sanitize($file, $sanitizedPath);

        if ($success) {
            $io->success(sprintf('Sanitized image saved to: %s', basename($sanitizedPath)));
        } else {
            $io->error('Sanitization failed.');
        }
    }

    /**
     * Generates a safe path for the sanitized copy.
     */
    private function buildSanitizedPath(string $originalPath): string
    {
        $info = pathinfo($originalPath);
        $directory = $info['dirname'] === '.' ? '' : $info['dirname'] . '/';
        $extension = strtolower($info['extension'] ?? 'jpg');

        return $directory . $info['filename'] . '.sanitized.' . $extension;
    }
}
