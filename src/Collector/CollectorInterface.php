<?php

namespace Kistn\Collector;

use Kistn\Dto\InventoryPayload;

interface CollectorInterface
{
    /**
     * Returns null if the ecosystem is not present (no lock file), skip silently.
     */
    public function collect(): ?InventoryPayload;

    public function ecosystem(): string;

    /**
     * SHA-256 hash of the lock file contents, or null if no lock file present.
     */
    public function lockFileHash(): ?string;

    /**
     * Returns true if the underlying tool (composer, npm, etc.) is available on PATH.
     */
    public function isToolAvailable(): bool;

    /**
     * Returns a map of field_name => absolute path for each lock/manifest file that exists.
     *
     * @return array<string, string>
     */
    public function lockFiles(): array;
}
