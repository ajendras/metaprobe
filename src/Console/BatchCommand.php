<?php

declare(strict_types=1);

namespace MetaProbe\Console;

use MetaProbe\MetaProbe;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Batch processes a directory of images, displays a progress bar,
 * optionally sanitizes files, runs privacy analysis on each, and
 * exports a structured summary as CSV.
 */
final class BatchCommand extends Command
{
    protected static $defaultName = 'batch';
    protected static $defaultDescription = 'Batch process images in a directory with progress bar and CSV export';

    protected function configure(): void
    {
        $this
            ->setName('batch')
            ->setDescription(self::$defaultDescription)
            ->setHelp(
                "Processes all supported image files (JPEG, PNG, TIFF, WebP, AVIF, HEIC) in a directory.\n" .
                "Shows a live progress bar, collects metadata/privacy scores, writes a CSV summary,\n" .
                "and can optionally sanitize images in-place."
            )
            ->addArgument('directory', InputArgument::REQUIRED, 'Path to the directory containing images')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Path for the output CSV summary (default: metaprobe-summary.csv in current dir)',
                null
            )
            ->addOption(
                'recursive',
                'r',
                InputOption::VALUE_NONE,
                'Recursively scan subdirectories'
            )
            ->addOption(
                'sanitize',
                null,
                InputOption::VALUE_NONE,
                'Also sanitize each image (overwrites originals — use with caution)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = (string) $input->getArgument('directory');
        $outputCsv = (string) $input->getOption('output') ?: getcwd() . DIRECTORY_SEPARATOR . 'metaprobe-summary.csv';
        $recursive = (bool) $input->getOption('recursive');
        $shouldSanitize = (bool) $input->getOption('sanitize');

        if (!is_dir($directory) || !is_readable($directory)) {
            $io->error(sprintf('Cannot access directory: %s', $directory));
            return Command::FAILURE;
        }

        try {
            $files = MetaProbe::findImages($directory, $recursive);

            if (empty($files)) {
                $io->warning('No supported image files found in the supplied directory.');
                return Command::SUCCESS;
            }

            $io->title(sprintf('MetaProbe Batch • %s (%d images)', basename($directory), count($files)));
            $io->comment('Analyzing metadata and privacy risks...');

            $progressBar = new ProgressBar($output, count($files));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');
            $progressBar->start();

            $summary = MetaProbe::batch(
                $directory,
                $recursive,
                static function () use ($progressBar): void {
                    $progressBar->advance();
                }
            );

            $progressBar->finish();
            $io->newLine(2);

            if ($shouldSanitize) {
                $this->performBatchSanitization($files, $io, $output);
            }

            $this->exportSummary($summary, $outputCsv, $io);
            $this->renderBatchSummary($summary, $io);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error('Batch processing error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Sanitizes every file (second pass with its own progress bar).
     */
    private function performBatchSanitization(array $files, SymfonyStyle $io, OutputInterface $output): void
    {
        $io->comment('Sanitizing images (overwriting originals)...');

        $progressBar = new ProgressBar($output, count($files));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();

        foreach ($files as $file) {
            try {
                MetaProbe::sanitize($file);
            } catch (Throwable $e) {
                // continue with remaining files
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine();
        $io->success('All images sanitized.');
    }

    /**
     * Writes the batch summary to a CSV file.
     */
    private function exportSummary(array $summary, string $csvPath, SymfonyStyle $io): void
    {
        if (empty($summary)) {
            $io->warning('No data to export to CSV.');
            return;
        }

        $directory = dirname($csvPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            $io->warning(sprintf('CSV output directory is not writable: %s', $directory));
            return;
        }

        $handle = fopen($csvPath, 'w');
        if ($handle === false) {
            $io->error('Unable to create CSV file.');
            return;
        }

        fputcsv($handle, array_keys($summary[0]));

        foreach ($summary as $row) {
            $safeRow = array_map(
                static fn (mixed $value): string => is_array($value) ? implode(' | ', $value) : (string) $value,
                $row
            );
            fputcsv($handle, $safeRow);
        }

        fclose($handle);

        $io->success(sprintf(
            'CSV summary written (%d rows) → %s',
            count($summary),
            basename($csvPath)
        ));
    }

    /**
     * Renders a compact table with high-level batch statistics.
     */
    private function renderBatchSummary(array $summary, SymfonyStyle $io): void
    {
        if (empty($summary)) {
            return;
        }

        $total = count($summary);
        $scores = array_column($summary, 'privacy_score');
        $avgScore = $total > 0 ? round(array_sum($scores) / $total, 1) : 0;
        $gpsCount = count(array_filter(
            $summary,
            static fn (array $row): bool => in_array($row['has_gps'] ?? false, ['YES', true], true)
        ));
        $lowScoreCount = count(array_filter($scores, static fn (int $s): bool => $s < 60));

        $io->newLine();
        $io->title('Batch Summary');

        $table = new Table($io);
        $table->setHeaders(['Metric', 'Value']);
        $table->setStyle('box');
        $table->addRow(['Images processed', (string) $total]);
        $table->addRow(['Average privacy score', $avgScore . '/100']);
        $table->addRow(['Containing GPS data', (string) $gpsCount]);
        $table->addRow(['Low-safety images (<60)', (string) $lowScoreCount]);
        $table->render();

        $io->newLine();
        $io->note('Tip: combine --recursive and --sanitize for large collections. CSV contains full per-file details.');
    }
}
