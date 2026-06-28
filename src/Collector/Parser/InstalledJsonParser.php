<?php

namespace Kistn\Collector\Parser;

use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;
use Kistn\Exception\CollectorException;

class InstalledJsonParser
{
    public function __construct(
        private readonly string $installedJsonPath,
        private readonly string $composerJsonPath,
    ) {}

    public function parse(): InventoryPayload
    {
        if (! file_exists($this->installedJsonPath)) {
            throw new CollectorException("installed.json not found at {$this->installedJsonPath}");
        }

        $data = json_decode((string) file_get_contents($this->installedJsonPath), true);

        if (! is_array($data)) {
            throw new CollectorException("Failed to parse installed.json at {$this->installedJsonPath}");
        }

        /** @var list<string> $devPackageNames */
        $devPackageNames = is_array($data['dev-package-names'] ?? null) ? $data['dev-package-names'] : [];

        $directNames = $this->directPackageNames();

        /** @var array<array-key, mixed> $packageList */
        $packageList = is_array($data['packages'] ?? null) ? $data['packages'] : [];

        $depths = $this->computeDepths($packageList, $directNames);
        $privatePackages = $this->detectPrivatePackages($packageList);

        $packages = [];

        foreach ($packageList as $entry) {
            $pkg = $this->parseEntry($entry, $directNames, $devPackageNames, $depths);
            if ($pkg !== null) {
                $packages[] = $pkg;
            }
        }

        return new InventoryPayload($packages, [], $privatePackages);
    }

    /**
     * @param  string[]  $directNames
     * @param  string[]  $devPackageNames
     * @param  array<string, int>  $depths
     */
    private function parseEntry(mixed $entry, array $directNames, array $devPackageNames, array $depths): ?Package
    {
        if (! is_array($entry)) {
            return null;
        }

        $nameValue = $entry['name'] ?? '';
        $versionValue = $entry['version'] ?? '';

        $name = is_string($nameValue) ? $nameValue : '';
        $version = is_string($versionValue) ? $versionValue : '';

        if ($name === '' || $version === '') {
            return null;
        }

        return new Package(
            name: $name,
            version: $version,
            ecosystem: 'composer',
            isDirect: in_array($name, $directNames, true),
            isDev: in_array($name, $devPackageNames, true),
            depth: $depths[$name] ?? null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return list<string>
     */
    private function detectPrivatePackages(array $entries): array
    {
        $private = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';

            if ($name === '') {
                continue;
            }

            $notifUrl = is_string($entry['notification-url'] ?? null) ? $entry['notification-url'] : null;

            if ($notifUrl !== 'https://packagist.org/downloads/') {
                $private[] = $name;
            }
        }

        return $private;
    }

    /**
     * BFS through require fields to assign each package its minimum dependency depth.
     *
     * @param  array<array-key, mixed>  $entries
     * @param  string[]  $directNames
     * @return array<string, int>
     */
    private function computeDepths(array $entries, array $directNames): array
    {
        /** @var array<string, list<string>> $requires */
        $requires = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';
            if ($name === '') {
                continue;
            }
            $req = is_array($entry['require'] ?? null) ? array_keys($entry['require']) : [];
            $requires[$name] = array_values(array_filter(
                $req,
                fn (mixed $r): bool => is_string($r) && str_contains($r, '/'),
            ));
        }

        /** @var array<string, int> $depths */
        $depths = [];
        /** @var list<array{0: string, 1: int}> $queue */
        $queue = [];

        foreach ($directNames as $name) {
            if (isset($requires[$name]) && ! isset($depths[$name])) {
                $depths[$name] = 0;
                $queue[] = [$name, 0];
            }
        }

        $head = 0;
        while ($head < count($queue)) {
            /** @var array{0: string, 1: int} $item */
            $item = $queue[$head++];
            [$current, $depth] = $item;
            foreach ($requires[$current] ?? [] as $dep) {
                if (! isset($depths[$dep])) {
                    $depths[$dep] = $depth + 1;
                    $queue[] = [$dep, $depth + 1];
                }
            }
        }

        return $depths;
    }

    /** @return string[] */
    private function directPackageNames(): array
    {
        if (! file_exists($this->composerJsonPath)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($this->composerJsonPath), true);

        if (! is_array($json)) {
            return [];
        }

        return array_keys(array_merge(
            (array) ($json['require'] ?? []),
            (array) ($json['require-dev'] ?? []),
        ));
    }
}
