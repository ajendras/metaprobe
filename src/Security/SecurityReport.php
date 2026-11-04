<?php

declare(strict_types=1);

namespace MetaProbe\Security;

use MetaProbe\Metadata;

/**
 * Immutable report returned by the privacy scanner.
 *
 * Contains the calculated safety score, list of human-readable risks,
 * and a reference to the original Metadata DTO.
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

    /**
     * Returns the privacy safety score (0-100).
     */
    public function getScore(): int
    {
        return $this->score;
    }

    /**
     * Returns the list of detected privacy risks.
     *
     * @return list<string>
     */
    public function getRisks(): array
    {
        return $this->risks;
    }

    /**
     * Returns the underlying Metadata DTO.
     */
    public function getMetadata(): Metadata
    {
        return $this->metadata;
    }
}
