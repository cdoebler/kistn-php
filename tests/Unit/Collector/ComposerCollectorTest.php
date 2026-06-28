<?php

use Kistn\Collector\ComposerCollector;
use Kistn\Process\ProcessRunnerInterface;

$fixturesDir = __DIR__ . '/../../../tests/fixtures';

function makeProcessRunner(string $output, int $exitCode): ProcessRunnerInterface
{
    return new readonly class ($output, $exitCode) implements ProcessRunnerInterface {
        public function __construct(private string $output, private int $exitCode) {}
        public function run(string $command): array
        {
            return ['output' => $this->output, 'exitCode' => $this->exitCode];
        }
    };
}

test('ComposerCollector returns null when no composer.lock exists', function () {
    $collector = new ComposerCollector(
        lockFilePath: '/nonexistent/composer.lock',
        composerJsonPath: '/nonexistent/composer.json',
        runner: makeProcessRunner('', 1),
    );
    expect($collector->collect())->toBeNull();
});

test('ComposerCollector returns packages from lock file', function () use ($fixturesDir) {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
    );
    $payload = $collector->collect();
    expect($payload)->not->toBeNull();
    expect($payload->packages)->not->toBeEmpty();
});

test('ComposerCollector parses findings from audit output', function () use ($fixturesDir) {
    $auditJson = json_encode([
        'advisories' => [
            'guzzlehttp/guzzle' => [
                [
                    'advisoryId' => 'GHSA-xxxx-yyyy-zzzz',
                    'packageName' => 'guzzlehttp/guzzle',
                    'affectedVersions' => '>=7.0,<7.8.1',
                    'severity' => 'high',
                ],
            ],
        ],
        'abandoned' => [],
    ]);

    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner($auditJson, 1),
    );
    $payload = $collector->collect();
    expect($payload->findings)->toHaveCount(1);
    expect($payload->findings[0]->advisoryId)->toBe('GHSA-xxxx-yyyy-zzzz');
    expect($payload->findings[0]->severity)->toBe('high');
});

test('ComposerCollector returns empty findings on audit binary failure', function () use ($fixturesDir) {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('', 127),
    );
    $payload = $collector->collect();
    expect($payload->findings)->toBe([]);
});

test('ComposerCollector ecosystem is composer', function () use ($fixturesDir) {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
    );
    expect($collector->ecosystem())->toBe('composer');
});

test('ComposerCollector lockFileHash returns sha256 of lock file', function () use ($fixturesDir) {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
    );
    $expected = hash('sha256', (string) file_get_contents($fixturesDir . '/composer.lock.json'));
    expect($collector->lockFileHash())->toBe($expected);
});

it('isToolAvailable returns true when composer exits 0', function (): void {
    $collector = new ComposerCollector('/tmp/lock', '/tmp/json', makeProcessRunner('Composer version 2.x', 0));

    expect($collector->isToolAvailable())->toBeTrue();
});

it('isToolAvailable returns false when composer is not found', function (): void {
    $collector = new ComposerCollector('/tmp/lock', '/tmp/json', makeProcessRunner('', 127));

    expect($collector->isToolAvailable())->toBeFalse();
});

it('lockFiles returns only existing files for ComposerCollector', function () use ($fixturesDir): void {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
    );

    $lockFiles = $collector->lockFiles();

    expect($lockFiles)->toHaveKey('composer_lock');
    expect($lockFiles)->toHaveKey('composer_json');
    expect($lockFiles['composer_lock'])->toBe($fixturesDir . '/composer.lock.json');
    expect($lockFiles['composer_json'])->toBe($fixturesDir . '/composer.json');
});

it('lockFiles excludes non-existing files for ComposerCollector', function (): void {
    $collector = new ComposerCollector(
        lockFilePath: '/nonexistent/composer.lock',
        composerJsonPath: '/nonexistent/composer.json',
        runner: makeProcessRunner('{}', 0),
    );

    expect($collector->lockFiles())->toBe([]);
});

test('ComposerCollector uses installed.json when present', function () use ($fixturesDir) {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
        installedJsonPath: $fixturesDir . '/installed.json',
    );
    $payload = $collector->collect();

    $names = array_map(fn ($p) => $p->name, $payload->packages);
    expect($names)->toContain('pestphp/pest');

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }
    expect($byName['pestphp/pest']->isDev)->toBeTrue();
});

test('ComposerCollector falls back to composer.lock when installed.json absent', function () use ($fixturesDir) {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
        installedJsonPath: '/nonexistent/installed.json',
    );
    $payload = $collector->collect();

    expect($payload)->not->toBeNull();
    $names = array_map(fn ($p) => $p->name, $payload->packages);
    expect($names)->toContain('guzzlehttp/guzzle');
});

test('ComposerCollector lockFileHash uses installed.json when present', function () use ($fixturesDir) {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
        installedJsonPath: $fixturesDir . '/installed.json',
    );
    $expected = hash('sha256', (string) file_get_contents($fixturesDir . '/installed.json'));
    expect($collector->lockFileHash())->toBe($expected);
});

it('lockFiles includes installed_json when present', function () use ($fixturesDir): void {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
        installedJsonPath: $fixturesDir . '/installed.json',
    );

    $lockFiles = $collector->lockFiles();

    expect($lockFiles)->toHaveKey('installed_json');
    expect($lockFiles['installed_json'])->toBe($fixturesDir . '/installed.json');
    expect($lockFiles)->toHaveKey('composer_lock');
    expect($lockFiles)->toHaveKey('composer_json');
});

it('lockFiles omits installed_json when path not set', function () use ($fixturesDir): void {
    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: makeProcessRunner('{}', 0),
    );

    expect($collector->lockFiles())->not->toHaveKey('installed_json');
});

function makeCommandDispatchRunner(array $map): \Kistn\Process\ProcessRunnerInterface
{
    return new readonly class ($map) implements \Kistn\Process\ProcessRunnerInterface {
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

test('ComposerCollector attaches availableVersion when composer outdated reports a newer version', function () use ($fixturesDir) {
    $outdatedJson = json_encode([
        'installed' => [
            ['name' => 'guzzlehttp/guzzle', 'version' => '7.0.0', 'latest' => '7.9.0', 'latest-status' => 'semver-safeUpdate'],
        ],
    ]);

    $runner = makeCommandDispatchRunner([
        'audit'    => ['output' => '{}', 'exitCode' => 0],
        'outdated' => ['output' => $outdatedJson, 'exitCode' => 0],
    ]);

    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: $runner,
    );
    $payload = $collector->collect();

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName)->toHaveKey('guzzlehttp/guzzle');
    expect($byName['guzzlehttp/guzzle']->availableVersion)->toBe('7.9.0');
});

test('ComposerCollector leaves availableVersion null when package is up to date', function () use ($fixturesDir) {
    $outdatedJson = json_encode(['installed' => []]);

    $runner = makeCommandDispatchRunner([
        'audit'    => ['output' => '{}', 'exitCode' => 0],
        'outdated' => ['output' => $outdatedJson, 'exitCode' => 0],
    ]);

    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: $runner,
    );
    $payload = $collector->collect();

    foreach ($payload->packages as $p) {
        expect($p->availableVersion)->toBeNull();
    }
});

test('ComposerCollector skips availableVersion gracefully when composer outdated fails', function () use ($fixturesDir) {
    $runner = makeCommandDispatchRunner([
        'audit'    => ['output' => '{}', 'exitCode' => 0],
        'outdated' => ['output' => '', 'exitCode' => 127],
    ]);

    $collector = new ComposerCollector(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
        runner: $runner,
    );
    $payload = $collector->collect();

    expect($payload)->not->toBeNull();
    foreach ($payload->packages as $p) {
        expect($p->availableVersion)->toBeNull();
    }
});
