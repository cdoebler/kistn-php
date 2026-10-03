<?php

namespace Kistn\Dto;

class Finding
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $packageVersion,
        public readonly string $advisoryId,
        public readonly string $severity,
    ) {}

    /**
     * The server only accepts low|medium|high|critical and rejects the whole push otherwise.
     * Audit tools also report 'moderate', 'info' or no severity at all.
     */
    public static function normalizeSeverity(mixed $raw): string
    {
        return match ($raw) {
            'low', 'medium', 'high', 'critical' => $raw,
            'moderate' => 'medium',
            default => 'low',
        };
    }
}
