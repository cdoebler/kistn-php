<?php

namespace Kistn\Dto;

class InventoryPayload
{
    /**
     * @param Package[] $packages
     * @param Finding[] $findings
     * @param string[] $privatePackages
     */
    public function __construct(
        public readonly array $packages,
        public readonly array $findings = [],
        public readonly array $privatePackages = [],
    ) {}
}
