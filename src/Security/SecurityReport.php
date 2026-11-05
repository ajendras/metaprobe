<?php

declare(strict_types=1);

namespace MetaProbe\Security;

use MetaProbe\Metadata;

/**
 * Immutable report containing privacy score, list of risks and the metadata.
 *
 * Also exposes a convenience method for steganography risk detection.
 */
final class SecurityReport
{
    /**
     * @param list<string> $risks
     */
    public function __construct(
        private readonly int $score,
        private readonly array $risks,
        private readonly Metadata $metadata,
    ) {
    }

    public function getScore(): int
    {
        return $this->score;
    }

    /**
     * @return list<string>
     */
    public function getRisks(): array
    {
        return $this->risks;
    }

    public function getMetadata(): Metadata
    {
        return $this->metadata;
    }

    public function isSafe(): bool
    {
        return $this->score >= 70;
    }

    /**
     * Returns true when any risk indicates possible LSB steganography.
     */
    public function hasSteganographyRisk(): bool
    {
        foreach ($this->risks as $risk) {
            $lower = strtolower($risk);
            if (str_contains($lower, 'steganography') || str_contains($lower, 'lsb')) {
                return true;
            }
        }

        return false;
    }
}
