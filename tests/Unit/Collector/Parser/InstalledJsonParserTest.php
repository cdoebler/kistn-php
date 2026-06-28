<?php

use Kistn\Collector\Parser\InstalledJsonParser;
use Kistn\Exception\CollectorException;

$fixturesDir = __DIR__ . '/../../../../tests/fixtures';

test('InstalledJsonParser parses all installed packages', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    $names = array_map(fn ($p) => $p->name, $payload->packages);
    expect($names)->toContain('guzzlehttp/guzzle');
    expect($names)->toContain('guzzlehttp/promises');
    expect($names)->toContain('guzzlehttp/psr7');
    expect($names)->toContain('pestphp/pest');
});

test('InstalledJsonParser marks dev packages correctly', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['pestphp/pest']->isDev)->toBeTrue();
    expect($byName['guzzlehttp/guzzle']->isDev)->toBeFalse();
    expect($byName['guzzlehttp/promises']->isDev)->toBeFalse();
});

test('InstalledJsonParser marks direct packages correctly', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['guzzlehttp/guzzle']->isDirect)->toBeTrue();
    expect($byName['pestphp/pest']->isDirect)->toBeTrue();
    expect($byName['guzzlehttp/promises']->isDirect)->toBeFalse();
});

test('InstalledJsonParser sets ecosystem to composer', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    foreach ($payload->packages as $pkg) {
        expect($pkg->ecosystem)->toBe('composer');
    }
});

test('InstalledJsonParser computes depth 0 for direct packages', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['guzzlehttp/guzzle']->depth)->toBe(0);
    expect($byName['pestphp/pest']->depth)->toBe(0);
});

test('InstalledJsonParser computes depth 1 for transitive packages', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['guzzlehttp/promises']->depth)->toBe(1);
    expect($byName['guzzlehttp/psr7']->depth)->toBe(1);
});

test('InstalledJsonParser includes php-prefixed transitive packages in depth BFS', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['phpunit/phpunit']->depth)->toBe(1);
});

test('InstalledJsonParser findings are always empty', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    expect($parser->parse()->findings)->toBe([]);
});

test('InstalledJsonParser detects packages without Packagist notification-url as private', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    expect($parser->parse()->privatePackages)->toBe(['my-vendor/private-lib']);
});

test('InstalledJsonParser does not flag Packagist packages as private', function () use ($fixturesDir) {
    $parser = new InstalledJsonParser(
        installedJsonPath: $fixturesDir . '/installed.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    expect($parser->parse()->privatePackages)->not->toContain('guzzlehttp/guzzle');
});

test('InstalledJsonParser throws when installed.json missing', function () {
    $parser = new InstalledJsonParser(
        installedJsonPath: '/nonexistent/installed.json',
        composerJsonPath: '/nonexistent/composer.json',
    );
    expect(fn () => $parser->parse())->toThrow(CollectorException::class);
});

test('InstalledJsonParser excludes dev packages when dev-package-names is empty', function () use ($fixturesDir) {
    $tmpFile = sys_get_temp_dir() . '/installed-no-dev-' . uniqid() . '.json';
    file_put_contents($tmpFile, json_encode([
        'packages' => [
            ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1'],
            ['name' => 'guzzlehttp/promises', 'version' => '2.0.2'],
        ],
        'dev' => false,
        'dev-package-names' => [],
    ]));

    try {
        $parser = new InstalledJsonParser(
            installedJsonPath: $tmpFile,
            composerJsonPath: $fixturesDir . '/composer.json',
        );
        $payload = $parser->parse();

        expect($payload->packages)->toHaveCount(2);
        foreach ($payload->packages as $pkg) {
            expect($pkg->isDev)->toBeFalse();
        }
    } finally {
        unlink($tmpFile);
    }
});
