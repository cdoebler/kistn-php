<?php

use Kistn\Collector\Parser\ComposerLockParser;

$fixturesDir = __DIR__ . '/../../../../tests/fixtures';

test('ComposerLockParser parses production packages', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    $names = array_map(fn ($p) => $p->name, $payload->packages);
    expect($names)->toContain('guzzlehttp/guzzle');
    expect($names)->toContain('guzzlehttp/promises');
    expect($names)->toContain('guzzlehttp/psr7');
});

test('ComposerLockParser marks direct packages correctly', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['guzzlehttp/guzzle']->isDirect)->toBeTrue();
    expect($byName['guzzlehttp/promises']->isDirect)->toBeFalse();
});

test('ComposerLockParser sets ecosystem to composer', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    foreach ($payload->packages as $pkg) {
        expect($pkg->ecosystem)->toBe('composer');
    }
});

test('ComposerLockParser includes dev packages', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    $names = array_map(fn ($p) => $p->name, $payload->packages);
    expect($names)->toContain('pestphp/pest');
});

test('ComposerLockParser marks dev direct packages correctly', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $payload = $parser->parse();

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['pestphp/pest']->isDirect)->toBeTrue();
});

test('ComposerLockParser computes depth 0 for direct packages', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['guzzlehttp/guzzle']->depth)->toBe(0);
    expect($byName['pestphp/pest']->depth)->toBe(0);
});

test('ComposerLockParser computes depth 1 for transitive packages', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['guzzlehttp/promises']->depth)->toBe(1);
    expect($byName['guzzlehttp/psr7']->depth)->toBe(1);
});

test('ComposerLockParser includes php-prefixed transitive packages in depth BFS', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['phpunit/phpunit']->depth)->toBe(1);
});

test('ComposerLockParser findings are always empty (no audit output)', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    expect($parser->parse()->findings)->toBe([]);
});

test('ComposerLockParser detects packages without Packagist notification-url as private', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    expect($parser->parse()->privatePackages)->toBe(['my-vendor/private-lib']);
});

test('ComposerLockParser does not flag Packagist packages as private', function () use ($fixturesDir) {
    $parser = new ComposerLockParser(
        lockFilePath: $fixturesDir . '/composer.lock.json',
        composerJsonPath: $fixturesDir . '/composer.json',
    );
    expect($parser->parse()->privatePackages)->not->toContain('guzzlehttp/guzzle');
});
