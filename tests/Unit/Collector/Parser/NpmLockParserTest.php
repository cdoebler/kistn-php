<?php

use Kistn\Collector\Parser\NpmLockParser;

$fixturesDir = __DIR__ . '/../../../../tests/fixtures';

test('NpmLockParser parses packages from package-lock.json v3', function () use ($fixturesDir) {
    $parser = new NpmLockParser(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
    );
    $payload = $parser->parse();
    $names = array_map(fn ($p) => $p->name, $payload->packages);

    expect($names)->toContain('express');
    expect($names)->toContain('ms');
});

test('NpmLockParser marks direct packages correctly', function () use ($fixturesDir) {
    $parser = new NpmLockParser(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
    );
    $payload = $parser->parse();

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['express']->isDirect)->toBeTrue();
    expect($byName['ms']->isDirect)->toBeFalse();
});

test('NpmLockParser sets ecosystem to npm', function () use ($fixturesDir) {
    $parser = new NpmLockParser(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
    );
    foreach ($parser->parse()->packages as $pkg) {
        expect($pkg->ecosystem)->toBe('npm');
    }
});

test('NpmLockParser skips root entry but includes dev packages', function () use ($fixturesDir) {
    $parser = new NpmLockParser(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
    );
    $names = array_map(fn ($p) => $p->name, $parser->parse()->packages);

    expect($names)->not->toContain('my-app');
    expect($names)->toContain('jest');
});

test('NpmLockParser computes depth 0 for direct packages', function () use ($fixturesDir) {
    $parser = new NpmLockParser(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['express']->depth)->toBe(0);
    expect($byName['jest']->depth)->toBe(0);
});

test('NpmLockParser computes depth 1 for transitive packages', function () use ($fixturesDir) {
    $parser = new NpmLockParser(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['ms']->depth)->toBe(1);
    expect($byName['qs']->depth)->toBe(1);
});

test('NpmLockParser prefers hoisted version over a nested copy that sorts first', function () use ($fixturesDir) {
    $parser = new NpmLockParser(
        lockFilePath: $fixturesDir . '/package-lock-hoist.json',
        packageJsonPath: $fixturesDir . '/package.json',
    );
    $byName = [];
    foreach ($parser->parse()->packages as $p) {
        $byName[$p->name] = $p;
    }

    // node_modules/aaa/node_modules/zod (2.0.0) sorts before node_modules/zod (3.0.0);
    // the hoisted top-level copy must win.
    expect($byName['zod']->version)->toBe('3.0.0');
});

test('NpmLockParser marks dev direct packages correctly', function () use ($fixturesDir) {
    $parser = new NpmLockParser(
        lockFilePath: $fixturesDir . '/package-lock.json',
        packageJsonPath: $fixturesDir . '/package.json',
    );
    $payload = $parser->parse();

    $byName = [];
    foreach ($payload->packages as $p) {
        $byName[$p->name] = $p;
    }

    expect($byName['jest']->isDirect)->toBeTrue();
});
