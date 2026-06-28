<?php

namespace Kistn\Collector;

use Kistn\Collector\Parser\NpmLockParser;
use Kistn\Dto\Finding;
use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;
use Kistn\Process\ProcessRunnerInterface;

class NpmCollector implements CollectorInterface
{
    public function __construct(
        private readonly string $lockFilePath,
        private readonly string $packageJsonPath,
        private readonly ProcessRunnerInterface $runner,
    ) {}

    public function collect(): ?InventoryPayload
    {
        if (! file_exists($this->lockFilePath)) {
            return null;
        }

        $parser = new NpmLockParser($this->lockFilePath, $this->packageJsonPath);
        $payload = $parser->parse();

        $installedVersions = [];
        foreach ($payload->packages as $pkg) {
            $installedVersions[$pkg->name] = $pkg->version;
        }

        $findings = $this->runAudit($installedVersions);
        $outdated = $this->runOutdated();
        $packages = $this->attachAvailableVersions($payload->packages, $outdated);

        return new InventoryPayload($packages, $findings);
    }

    public function ecosystem(): string
    {
        return 'npm';
    }

    public function lockFileHash(): ?string
    {
        if (! file_exists($this->lockFilePath)) {
            return null;
        }

        return hash('sha256', (string) file_get_contents($this->lockFilePath));
    }

    public function isToolAvailable(): bool
    {
        $result = $this->runner->run('npm --version');

        return $result['exitCode'] === 0;
    }

    /**
     * @return array<string, string>
     */
    public function lockFiles(): array
    {
        $files = [];

        if (file_exists($this->lockFilePath)) {
            $files['package_lock'] = $this->lockFilePath;
        }

        if (file_exists($this->packageJsonPath)) {
            $files['package_json'] = $this->packageJsonPath;
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $installedVersions
     * @return Finding[]
     */
    private function runAudit(array $installedVersions): array
    {
        $result = $this->runner->run('npm audit --json');

        $data = json_decode($result['output'], true);

        if (! is_array($data) || ! isset($data['vulnerabilities'])) {
            return [];
        }

        $vulnerabilities = $data['vulnerabilities'];

        if (! is_array($vulnerabilities)) {
            return [];
        }

        $findings = [];

        foreach ($vulnerabilities as $name => $vuln) {
            if (! is_array($vuln)) {
                continue;
            }

            $advisoryId = $this->extractAdvisoryId($vuln);
            $severityValue = $vuln['severity'] ?? 'unknown';
            $nameValue = $vuln['name'] ?? null;
            $resolvedName = is_string($nameValue) ? $nameValue : (is_string($name) ? $name : null);

            $findings[] = new Finding(
                packageName: $resolvedName ?? 'unknown',
                packageVersion: $resolvedName !== null ? ($installedVersions[$resolvedName] ?? '*') : '*',
                advisoryId: $advisoryId,
                severity: is_string($severityValue) ? $severityValue : 'unknown',
            );
        }

        return $findings;
    }

    /** @return array<string, string> Map of package name → latest version */
    private function runOutdated(): array
    {
        $result = $this->runner->run('npm outdated --json');

        // npm outdated exits 1 when outdated packages exist — valid output
        $data = json_decode($result['output'], true);

        if (! is_array($data)) {
            return [];
        }

        $map = [];

        foreach ($data as $name => $info) {
            if (! is_array($info) || ! is_string($name)) {
                continue;
            }

            $latest = is_string($info['latest'] ?? null) ? $info['latest'] : null;

            if ($latest !== null) {
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

    /** @param array<array-key, mixed> $vuln */
    private function extractAdvisoryId(array $vuln): string
    {
        $viaRaw = $vuln['via'] ?? [];

        if (! is_array($viaRaw)) {
            return 'unknown';
        }

        foreach ($viaRaw as $item) {
            if (is_array($item) && isset($item['url']) && is_string($item['url'])) {
                if (preg_match('/GHSA-[a-z0-9-]+/i', $item['url'], $m)) {
                    return $m[0];
                }
            }
        }

        return 'unknown';
    }
}
