<?php

use Kistn\Collector\NpmCollector;
use Kistn\Process\ProcessRunnerInterface;

$fixturesDir = __DIR__ . '/../../../tests/fixtures';

if (! function_exists('makeCommandDispatchRunner')) {
    function makeCommandDispatchRunner(array $map): ProcessRunnerInterface
    {
        return new readonly class ($map) implements ProcessRunnerInterface {
            /** @param array<string, array{output: string, exitCode: int}> $map */
            public function __construct(private array $map) {}

            public function run(string $command): array
            {
                foreach ($this->map as $pattern => $result) {
                    if (str_contains($command, $pattern)) {
                        return $result;
                    }
                }

                return ['output' => '', 'exitCode' => 0];
            }
        };
    }
}

function makeNpmRunner(string $output, int $exitCode): ProcessRunnerInterface
{
    return new readonly class ($output, $exitCode) implements ProcessRunnerInterface {
        public function __construct(private string $output, private int $exitCode) {}
        public function run(string $command): array
        {
            return ['output' => $this->output, 'exitCode' => $this->exitCode];
        }
    };
}

test('NpmCollector returns null when no package-lock.json exists', function () {
    $collector = new NpmCollector(
        lockFilePath: '/nonexistent/package-lock.json',
        packageJsonPath: '/nonexistent/package.json',
        runner: makeNpmRunner('', 1),
    );
    expect($collector->collect())->toBeNull();
});

test('NpmCollector returns packages from lock file', function () use ($fixturesDir) {
    $collector = new NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: makeNpmRunner('{}', 0),
    );
    $payload = $collector->collect();
    expect($payload)->not->toBeNull();
    expect($payload->packages)->not->toBeEmpty();
});

test('NpmCollector parses findings from npm audit output', function () use ($fixturesDir) {
    $auditJson = json_encode([
        'vulnerabilities' => [
            'express' => [
                'name' => 'express',
                'severity' => 'high',
                'via' => [['source' => 1234567, 'name' => 'express', 'url' => 'https://github.com/advisories/GHSA-rv95-896h-c2vc']],
                'nodes' => ['node_modules/express'],
            ],
        ],
        'metadata' => ['vulnerabilities' => ['high' => 1]],
    ]);

    $collector = new NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: makeNpmRunner($auditJson, 1),
    );
    $payload = $collector->collect();
    expect($payload->findings)->toHaveCount(1);
    expect($payload->findings[0]->severity)->toBe('high');
    expect($payload->findings[0]->packageVersion)->toBe('4.18.2');
});

test('NpmCollector falls back to * for packages not in lock file', function () use ($fixturesDir) {
    $auditJson = json_encode([
        'vulnerabilities' => [
            'unknown-pkg' => [
                'name' => 'unknown-pkg',
                'severity' => 'low',
                'via' => [['url' => 'https://github.com/advisories/GHSA-xxxx-xxxx-xxxx']],
                'nodes' => ['node_modules/unknown-pkg'],
            ],
        ],
    ]);

    $collector = new NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: makeNpmRunner($auditJson, 1),
    );
    $payload = $collector->collect();
    expect($payload->findings[0]->packageVersion)->toBe('*');
});

test('NpmCollector returns empty findings on audit binary failure', function () use ($fixturesDir) {
    $collector = new NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: makeNpmRunner('', 127),
    );
    expect($collector->collect()->findings)->toBe([]);
});

test('NpmCollector ecosystem is npm', function () use ($fixturesDir) {
    $collector = new NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: makeNpmRunner('{}', 0),
    );
    expect($collector->ecosystem())->toBe('npm');
});

test('NpmCollector lockFileHash returns sha256 of lock file', function () use ($fixturesDir) {
    $collector = new NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: makeNpmRunner('{}', 0),
    );
    $expected = hash('sha256', (string) file_get_contents($fixturesDir . '/package-lock.json'));
    expect($collector->lockFileHash())->toBe($expected);
});

it('isToolAvailable returns true when npm exits 0', function (): void {
    $collector = new NpmCollector('/tmp/package-lock.json', '/tmp/package.json', makeNpmRunner('10.0.0', 0));

    expect($collector->isToolAvailable())->toBeTrue();
});

it('isToolAvailable returns false when npm is not found', function (): void {
    $collector = new NpmCollector('/tmp/package-lock.json', '/tmp/package.json', makeNpmRunner('', 127));

    expect($collector->isToolAvailable())->toBeFalse();
});

it('lockFiles returns only existing files for NpmCollector', function () use ($fixturesDir): void {
    $collector = new NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: makeNpmRunner('{}', 0),
    );

    $lockFiles = $collector->lockFiles();

    expect($lockFiles)->toHaveKey('package_lock');
    expect($lockFiles)->toHaveKey('package_json');
    expect($lockFiles['package_lock'])->toBe($fixturesDir . '/package-lock.json');
    expect($lockFiles['package_json'])->toBe($fixturesDir . '/package.json');
});

it('lockFiles excludes non-existing files for NpmCollector', function (): void {
    $collector = new NpmCollector(
        lockFilePath: '/nonexistent/package-lock.json',
        packageJsonPath: '/nonexistent/package.json',
        runner: makeNpmRunner('{}', 0),
    );

    expect($collector->lockFiles())->toBe([]);
});

test('NpmCollector attaches availableVersion when npm outdated reports a newer version', function () use ($fixturesDir) {
    $outdatedJson = json_encode([
        'express' => ['current' => '4.18.2', 'wanted' => '5.0.0', 'latest' => '5.0.0', 'dependent' => 'test', 'location' => 'node_modules/express'],
    ]);

    $runner = makeCommandDispatchRunner([
        'audit'    => ['output' => '{"vulnerabilities":{}}', 'exitCode' => 0],
        'outdated' => ['output' => $outdatedJson, 'exitCode' => 1],
    ]);

    $collector = new \Kistn\Collector\NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: $runner,
    );
    $payload = $collector->collect();

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName)->toHaveKey('express');
    expect($byName['express']->availableVersion)->toBe('5.0.0');
});

test('NpmCollector leaves availableVersion null when package is up to date', function () use ($fixturesDir) {
    $runner = makeCommandDispatchRunner([
        'audit'    => ['output' => '{"vulnerabilities":{}}', 'exitCode' => 0],
        'outdated' => ['output' => '{}', 'exitCode' => 0],
    ]);

    $collector = new \Kistn\Collector\NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: $runner,
    );
    $payload = $collector->collect();

    foreach ($payload->packages as $p) {
        expect($p->availableVersion)->toBeNull();
    }
});

test('NpmCollector skips availableVersion gracefully when npm outdated fails', function () use ($fixturesDir) {
    $runner = makeCommandDispatchRunner([
        'audit'    => ['output' => '{"vulnerabilities":{}}', 'exitCode' => 0],
        'outdated' => ['output' => 'not json', 'exitCode' => 127],
    ]);

    $collector = new \Kistn\Collector\NpmCollector(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
        runner: $runner,
    );
    $payload = $collector->collect();

    expect($payload)->not->toBeNull();
    foreach ($payload->packages as $p) {
        expect($p->availableVersion)->toBeNull();
    }
});
