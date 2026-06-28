<?php

namespace Kistn;

use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;

class HashComputer
{
    public function compute(InventoryPayload $payload): string
    {
        $packages = array_map(
            fn (Package $p): array => [
                'name'      => $p->name,
                'version'   => $p->version,
                'is_direct' => $p->isDirect,
                'is_dev'    => $p->isDev,
                'depth'     => $p->depth,
            ],
            $payload->packages,
        );

        usort($packages, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return hash('sha256', json_encode($packages, JSON_THROW_ON_ERROR));
    }
}
