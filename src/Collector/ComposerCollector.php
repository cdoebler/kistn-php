<?php

namespace Kistn\Collector;

use Kistn\Collector\Parser\ComposerLockParser;
use Kistn\Collector\Parser\InstalledJsonParser;
use Kistn\Dto\Finding;
use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;
use Kistn\Process\ProcessRunnerInterface;

class ComposerCollector implements CollectorInterface
{
    public function __construct(
        private readonly string $lockFilePath,
        private readonly string $composerJsonPath,
        private readonly ProcessRunnerInterface $runner,
        private readonly ?string $installedJsonPath = null,
    ) {}

    public function collect(): ?InventoryPayload
    {
        if ($this->installedJsonPath !== null && file_exists($this->installedJsonPath)) {
            $parser = new InstalledJsonParser($this->installedJsonPath, $this->composerJsonPath);
            $payload = $parser->parse();
        } elseif (file_exists($this->lockFilePath)) {
            $parser = new ComposerLockParser($this->lockFilePath, $this->composerJsonPath);
            $payload = $parser->parse();
        } else {
            return null;
        }

        $findings = $this->runAudit();
        $outdated = $this->runOutdated();
        $packages = $this->attachAvailableVersions($payload->packages, $outdated);

        return new InventoryPayload($packages, $findings, $payload->privatePackages);
    }

    public function ecosystem(): string
    {
        return 'composer';
    }

    public function lockFileHash(): ?string
    {
        if ($this->installedJsonPath !== null && file_exists($this->installedJsonPath)) {
            return hash('sha256', (string) file_get_contents($this->installedJsonPath));
        }

        if (! file_exists($this->lockFilePath)) {
            return null;
        }

        return hash('sha256', (string) file_get_contents($this->lockFilePath));
    }

    public function isToolAvailable(): bool
    {
        $result = $this->runner->run('composer --version');

        return $result['exitCode'] === 0;
    }

    /**
     * @return array<string, string>
     */
    public function lockFiles(): array
    {
        $files = [];

        if ($this->installedJsonPath !== null && file_exists($this->installedJsonPath)) {
            $files['installed_json'] = $this->installedJsonPath;
        }

        if (file_exists($this->lockFilePath)) {
            $files['composer_lock'] = $this->lockFilePath;
        }

        if (file_exists($this->composerJsonPath)) {
            $files['composer_json'] = $this->composerJsonPath;
        }

        return $files;
    }

    /** @return Finding[] */
    private function runAudit(): array
    {
        $result = $this->runner->run('composer audit --format=json --no-interaction');

        $data = json_decode($result['output'], true);

        if (! is_array($data) || ! isset($data['advisories']) || ! is_array($data['advisories'])) {
            return [];
        }

        $findings = [];

        /** @var array<string, list<array<string, mixed>>> $advisoriesByPackage */
        $advisoriesByPackage = $data['advisories'];

        foreach ($advisoriesByPackage as $packageName => $advisories) {
            foreach ($advisories as $advisory) {
                $advisoryPackageName = is_string($advisory['packageName'] ?? null) ? $advisory['packageName'] : $packageName;
                $affectedVersions = is_string($advisory['affectedVersions'] ?? null) ? $advisory['affectedVersions'] : '';
                $advisoryId = is_string($advisory['advisoryId'] ?? null) ? $advisory['advisoryId'] : '';
                $findings[] = new Finding(
                    packageName: $advisoryPackageName,
                    packageVersion: $this->resolveAffectedVersion($affectedVersions) ?: 'unknown',
                    advisoryId: $advisoryId,
                    severity: Finding::normalizeSeverity($advisory['severity'] ?? null),
                );
            }
        }

        return $findings;
    }

    private function resolveAffectedVersion(string $affectedVersions): string
    {
        // Extract upper bound from constraint like ">=7.0,<7.8.1" as the pinned affected version
        if (preg_match('/<([^\s,>]+)/', $affectedVersions, $matches)) {
            return $matches[1];
        }

        return $affectedVersions;
    }

    /** @return array<string, string> Map of package name → latest version */
    private function runOutdated(): array
    {
        $result = $this->runner->run('composer outdated --format=json --no-interaction --locked');
        $data = json_decode($result['output'], true);

        if (! is_array($data) || ! isset($data['installed']) || ! is_array($data['installed'])) {
            return [];
        }

        $map = [];

        foreach ($data['installed'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = is_string($item['name'] ?? null) ? $item['name'] : null;
            $latest = is_string($item['latest'] ?? null) ? $item['latest'] : null;

            if ($name !== null && $latest !== null) {
                $map[$name] = $latest;
            }
        }

        return $map;
    }

    /**
     * @param  Package[]  $packages
     * @param  array<string, string>  $outdated
     * @return Package[]
     */
    private function attachAvailableVersions(array $packages, array $outdated): array
    {
        return array_map(function (Package $p) use ($outdated): Package {
            $latest = $outdated[$p->name] ?? null;

            if ($latest !== null && $latest !== $p->version) {
                return new Package($p->name, $p->version, $p->ecosystem, $p->isDirect, $p->isDev, $p->depth, $latest);
            }

            return $p;
        }, $packages);
    }
}
