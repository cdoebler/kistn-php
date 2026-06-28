<?php

use Kistn\Cache\LocalHashCache;

beforeEach(function () {
    $this->cachePath = sys_get_temp_dir() . '/.test-inventory-hash-' . uniqid();
});

afterEach(function () {
    if (file_exists($this->cachePath)) {
        unlink($this->cachePath);
    }
});

test('get returns null when cache file does not exist', function () {
    $cache = new LocalHashCache($this->cachePath);
    expect($cache->get('composer'))->toBeNull();
});

test('set and get round-trip', function () {
    $cache = new LocalHashCache($this->cachePath);
    $cache->set('composer', 'sha256:abc123');
    expect($cache->get('composer'))->toBe('sha256:abc123');
});

test('set for multiple ecosystems', function () {
    $cache = new LocalHashCache($this->cachePath);
    $cache->set('composer', 'sha256:aaa');
    $cache->set('npm', 'sha256:bbb');
    expect($cache->get('composer'))->toBe('sha256:aaa');
    expect($cache->get('npm'))->toBe('sha256:bbb');
});

test('get returns null for unknown ecosystem even when cache file exists', function () {
    $cache = new LocalHashCache($this->cachePath);
    $cache->set('composer', 'sha256:aaa');
    expect($cache->get('npm'))->toBeNull();
});

test('cache persists across instances', function () {
    (new LocalHashCache($this->cachePath))->set('composer', 'sha256:persistent');
    expect((new LocalHashCache($this->cachePath))->get('composer'))->toBe('sha256:persistent');
});

test('getFindingsHash returns null when no cache file exists', function () {
    $cache = new LocalHashCache($this->cachePath);
    expect($cache->getFindingsHash('composer'))->toBeNull();
});

test('setFindingsHash persists and getFindingsHash retrieves it', function () {
    $cache = new LocalHashCache($this->cachePath);

    $cache->setFindingsHash('composer', 'abc123');

    expect($cache->getFindingsHash('composer'))->toBe('abc123');
});

test('findings hash is independent from lock file hash', function () {
    $cache = new LocalHashCache($this->cachePath);

    $cache->set('composer', 'lock-hash');
    $cache->setFindingsHash('composer', 'findings-hash');

    expect($cache->get('composer'))->toBe('lock-hash');
    expect($cache->getFindingsHash('composer'))->toBe('findings-hash');
});

test('findings hash for one ecosystem does not affect another', function () {
    $cache = new LocalHashCache($this->cachePath);

    $cache->setFindingsHash('composer', 'composer-findings');
    $cache->setFindingsHash('npm', 'npm-findings');

    expect($cache->getFindingsHash('composer'))->toBe('composer-findings');
    expect($cache->getFindingsHash('npm'))->toBe('npm-findings');
});

test('getOutdatedHash returns null when no cache file exists', function () {
    $cache = new LocalHashCache($this->cachePath);
    expect($cache->getOutdatedHash('composer'))->toBeNull();
});

test('setOutdatedHash persists and getOutdatedHash retrieves it', function () {
    $cache = new LocalHashCache($this->cachePath);
    $cache->setOutdatedHash('composer', 'outdated-hash-abc');
    expect($cache->getOutdatedHash('composer'))->toBe('outdated-hash-abc');
});

test('outdated hash is independent from lock file hash and findings hash', function () {
    $cache = new LocalHashCache($this->cachePath);
    $cache->set('composer', 'lock-hash');
    $cache->setFindingsHash('composer', 'findings-hash');
    $cache->setOutdatedHash('composer', 'outdated-hash');
    expect($cache->get('composer'))->toBe('lock-hash');
    expect($cache->getFindingsHash('composer'))->toBe('findings-hash');
    expect($cache->getOutdatedHash('composer'))->toBe('outdated-hash');
});

test('outdated hash for one ecosystem does not affect another', function () {
    $cache = new LocalHashCache($this->cachePath);
    $cache->setOutdatedHash('composer', 'composer-outdated');
    $cache->setOutdatedHash('npm', 'npm-outdated');
    expect($cache->getOutdatedHash('composer'))->toBe('composer-outdated');
    expect($cache->getOutdatedHash('npm'))->toBe('npm-outdated');
});
