<?php

namespace Kistn\Client;

use Kistn\Dto\InventoryPayload;

interface InventoryClientInterface
{
    /**
     * @return array<string, string|null>
     */
    public function getHashes(): array;

    /**
     * @param  array<string, InventoryPayload>  $payloads  Ecosystem => payload map
     */
    public function push(array $payloads): void;

    /**
     * @param  array<string, string>  $filePaths  Map of field name => absolute file path
     */
    public function uploadFiles(array $filePaths): void;
}
