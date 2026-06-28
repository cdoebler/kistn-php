<?php

namespace Kistn\Collector\Parser;

use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;
use Kistn\Exception\CollectorException;

class NpmLockParser
{
    public function __construct(
        private readonly string $lockFilePath,
        private readonly string $packageJsonPath,
    ) {}

    public function parse(): InventoryPayload
    {
        if (! file_exists($this->lockFilePath)) {
            throw new CollectorException("package-lock.json not found at {$this->lockFilePath}");
        }

        $lock = json_decode((string) file_get_contents($this->lockFilePath), true);

        if (! is_array($lock) || ! isset($lock['packages'])) {
            throw new CollectorException("Unsupported or malformed package-lock.json at {$this->lockFilePath}. lockfileVersion 2 or 3 required.");
        }

        /** @var array<array-key, mixed> $packageMap */
        $packageMap = $lock['packages'];

        $directNames = $this->rootDirectNames($packageMap);

        /** @var array<string, array{version: string, isDev: bool, deps: string[], segments: int}> $entries */
        $entries = [];

        foreach ($packageMap as $path => $entry) {
            if ($path === '' || ! is_string($path) || ! str_starts_with($path, 'node_modules/') || ! is_array($entry)) {
                continue;
            }

            $lastOffset = strrpos($path, 'node_modules/');
            $name = substr($path, $lastOffset + strlen('node_modules/'));
            $versionValue = $entry['version'] ?? '';
            $version = is_string($versionValue) ? $versionValue : '';

            if ($name === '' || $version === '') {
                continue;
            }

            // Prefer the shallowest (hoisted) occurrence — fewest nested node_modules segments.
            // Path order is not reliable: node_modules/a/node_modules/b sorts before node_modules/b.
            $segments = substr_count($path, 'node_modules/');
            if (isset($entries[$name]) && $entries[$name]['segments'] <= $segments) {
                continue;
            }

            $deps = array_merge(
                is_array($entry['dependencies'] ?? null) ? $entry['dependencies'] : [],
                is_array($entry['optionalDependencies'] ?? null) ? $entry['optionalDependencies'] : [],
            );

            $entries[$name] = [
                'version'  => $version,
                'isDev'    => ! empty($entry['dev']),
                'deps'     => array_keys($deps),
                'segments' => $segments,
            ];
        }

        $depths = $this->computeDepths($entries, $directNames);

        $packages = [];
        foreach ($entries as $name => $data) {
            $packages[] = new Package(
                name: $name,
                version: $data['version'],
                ecosystem: 'npm',
                isDirect: in_array($name, $directNames, true),
                isDev: $data['isDev'],
                depth: $depths[$name] ?? null,
            );
        }

        return new InventoryPayload($packages);
    }

    /**
     * BFS through package dependencies to assign each package its minimum logical depth.
     *
     * @param  array<string, array{version: string, isDev: bool, deps: string[], segments: int}>  $entries
     * @param  string[]  $directNames
     * @return array<string, int>
     */
    private function computeDepths(array $entries, array $directNames): array
    {
        /** @var array<string, int> $depths */
        $depths = [];
        /** @var list<array{0: string, 1: int}> $queue */
        $queue = [];

        foreach ($directNames as $name) {
            if (isset($entries[$name]) && ! isset($depths[$name])) {
                $depths[$name] = 0;
                $queue[] = [$name, 0];
            }
        }

        $head = 0;
        while ($head < count($queue)) {
            [$current, $depth] = $queue[$head++];
            foreach ($entries[$current]['deps'] ?? [] as $dep) {
                if (isset($entries[$dep]) && ! isset($depths[$dep])) {
                    $depths[$dep] = $depth + 1;
                    $queue[] = [$dep, $depth + 1];
                }
            }
        }

        return $depths;
    }

    /**
     * Direct package names from the root entry in packages[""] or package.json fallback.
     *
     * @param  array<array-key, mixed>  $packageMap
     * @return string[]
     */
    private function rootDirectNames(array $packageMap): array
    {
        $root = $packageMap[''] ?? null;
        if (is_array($root)) {
            return array_keys(array_merge(
                (array) ($root['dependencies'] ?? []),
                (array) ($root['devDependencies'] ?? []),
                (array) ($root['optionalDependencies'] ?? []),
            ));
        }

        return $this->directPackageNames();
    }

    /** @return string[] */
    private function directPackageNames(): array
    {
        if (! file_exists($this->packageJsonPath)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($this->packageJsonPath), true);

        if (! is_array($json)) {
            return [];
        }

        return array_keys(array_merge(
            (array) ($json['dependencies'] ?? []),
            (array) ($json['devDependencies'] ?? []),
        ));
    }
}
