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
            if (! is_array($vuln) || ! is_array($vuln['via'] ?? null)) {
                continue;
            }

            $nameValue = $vuln['name'] ?? null;
            $resolvedName = is_string($nameValue) ? $nameValue : (is_string($name) ? $name : null);

            if ($resolvedName === null) {
                continue;
            }

            // String `via` entries only name a vulnerable dependency ("Depends on vulnerable versions of X");
            // that dependency is reported with its own advisory, so they are not findings of this package.
            foreach ($vuln['via'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $advisoryId = $this->extractAdvisoryId($item);

                if ($advisoryId === null) {
                    continue;
                }

                $findings[] = new Finding(
                    packageName: $resolvedName,
                    packageVersion: $installedVersions[$resolvedName] ?? '*',
                    advisoryId: $advisoryId,
                    severity: Finding::normalizeSeverity($item['severity'] ?? $vuln['severity'] ?? null),
                );
            }
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

    /**
     * GHSA id from the advisory URL, else npm's numeric advisory id (`source`); null when neither exists.
     *
     * @param  array<array-key, mixed>  $advisory  One object entry of npm audit's `via`
     */
    private function extractAdvisoryId(array $advisory): ?string
    {
        $url = $advisory['url'] ?? null;

        if (is_string($url) && preg_match('/GHSA-[a-z0-9-]+/i', $url, $m)) {
            return $m[0];
        }

        $source = $advisory['source'] ?? null;

        if (is_int($source) || (is_string($source) && ctype_digit($source))) {
            return 'NPM-' . $source;
        }

        return null;
    }
}
