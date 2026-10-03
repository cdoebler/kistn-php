<?php

namespace Kistn;

use Kistn\Cache\LocalHashCache;
use Kistn\Client\InventoryClientInterface;
use Kistn\Collector\CollectorInterface;
use Kistn\Dto\Finding;
use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;

class InventoryPusher
{
    /**
     * @param CollectorInterface[] $collectors
     */
    public function __construct(
        private readonly InventoryClientInterface $client,
        private readonly array $collectors,
        private readonly LocalHashCache $cache,
        private readonly TransmitMode $transmitComposerFiles = TransmitMode::Never,
        private readonly TransmitMode $transmitNpmFiles = TransmitMode::Never,
    ) {}

    public function pushAll(): void
    {
        $serverHashes = $this->client->getHashes();

        /** @var array<string, InventoryPayload> $payloads */
        $payloads = [];

        /** @var array<string, string> $uploadFiles */
        $uploadFiles = [];

        /** @var array<string, array{collector: CollectorInterface, payload: InventoryPayload}> $pushed */
        $pushed = [];

        foreach ($this->collectors as $collector) {
            $ecosystem  = $collector->ecosystem();
            $serverHash = $serverHashes[$ecosystem] ?? null;
            $lockHash   = $collector->lockFileHash();

            if ($lockHash !== null && $this->cache->get($ecosystem) === $lockHash && ! $collector->isToolAvailable()) {
                continue;
            }

            $payload = $collector->collect();

            if ($payload === null) {
                continue;
            }

            $contentHash    = (new HashComputer())->compute($payload);
            $packagesChanged = $contentHash !== $serverHash;

            if (! $packagesChanged) {
                $findingsHash       = $this->computeFindingsHash($payload->findings);
                $cachedFindingsHash = $this->cache->getFindingsHash($ecosystem);
                $outdatedHash       = $this->computeOutdatedHash($payload->packages);
                $cachedOutdatedHash = $this->cache->getOutdatedHash($ecosystem);

                if ($findingsHash === $cachedFindingsHash && $outdatedHash === $cachedOutdatedHash) {
                    if ($lockHash !== null) {
                        $this->cache->set($ecosystem, $lockHash);
                    }

                    continue;
                }

                $payloads[$ecosystem] = $payload;
                $pushed[$ecosystem]   = ['collector' => $collector, 'payload' => $payload];
            } else {
                $payloads[$ecosystem] = $payload;
                $pushed[$ecosystem]   = ['collector' => $collector, 'payload' => $payload];

                if ($this->shouldUploadFiles($collector)) {
                    foreach ($collector->lockFiles() as $field => $path) {
                        $uploadFiles[$field] = $path;
                    }
                }
            }
        }

        if ($payloads === []) {
            return;
        }

        $this->client->push($payloads);

        if ($uploadFiles !== []) {
            $this->client->uploadFiles($uploadFiles);
        }

        foreach ($pushed as $ecosystem => ['collector' => $collector, 'payload' => $payload]) {
            $this->cache->setFindingsHash($ecosystem, $this->computeFindingsHash($payload->findings));
            $this->cache->setOutdatedHash($ecosystem, $this->computeOutdatedHash($payload->packages));
            $lockHash = $collector->lockFileHash();

            if ($lockHash !== null) {
                $this->cache->set($ecosystem, $lockHash);
            }
        }
    }

    private function shouldUploadFiles(CollectorInterface $collector): bool
    {
        $mode = match ($collector->ecosystem()) {
            'composer' => $this->transmitComposerFiles,
            'npm'      => $this->transmitNpmFiles,
            default    => TransmitMode::Never,
        };

        return match ($mode) {
            TransmitMode::Always   => true,
            TransmitMode::Never    => false,
            TransmitMode::OnDemand => ! $collector->isToolAvailable(),
        };
    }

    /**
     * @param Finding[] $findings
     */
    private function computeFindingsHash(array $findings): string
    {
        $items = array_map(
            fn (Finding $f): array => [
                'advisory_id'     => $f->advisoryId,
                'package_name'    => $f->packageName,
                'package_version' => $f->packageVersion,
                'severity'        => $f->severity,
            ],
            $findings,
        );

        usort($items, fn (array $a, array $b): int => strcmp($a['advisory_id'], $b['advisory_id']));

        return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
    }

    /**
     * @param Package[] $packages
     */
    private function computeOutdatedHash(array $packages): string
    {
        $items = [];

        foreach ($packages as $p) {
            if ($p->availableVersion !== null) {
                $items[] = ['name' => $p->name, 'available_version' => $p->availableVersion];
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
    }
}
