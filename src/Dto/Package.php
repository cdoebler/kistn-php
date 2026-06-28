<?php

namespace Kistn\Dto;

class Package
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $ecosystem,
        public readonly bool $isDirect,
        public readonly bool $isDev = false,
        public readonly ?int $depth = null,
        public readonly ?string $availableVersion = null,
    ) {}
}
