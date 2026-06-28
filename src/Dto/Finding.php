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
}
