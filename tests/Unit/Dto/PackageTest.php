<?php

use Kistn\Dto\Package;

test('Package stores all properties', function () {
    $p = new Package('vendor/pkg', '1.0.0', 'composer', true, false, 0);
    expect($p->name)->toBe('vendor/pkg');
    expect($p->version)->toBe('1.0.0');
    expect($p->ecosystem)->toBe('composer');
    expect($p->isDirect)->toBeTrue();
    expect($p->isDev)->toBeFalse();
    expect($p->depth)->toBe(0);
});

test('Package isDev defaults to false', function () {
    $p = new Package('vendor/pkg', '1.0.0', 'composer', false);
    expect($p->isDev)->toBeFalse();
});

test('Package depth defaults to null', function () {
    $p = new Package('vendor/pkg', '1.0.0', 'composer', false);
    expect($p->depth)->toBeNull();
});

test('Package has null availableVersion by default', function () {
    $pkg = new Package('vendor/pkg', '1.0.0', 'composer', true);
    expect($pkg->availableVersion)->toBeNull();
});

test('Package stores availableVersion when provided', function () {
    $pkg = new Package('vendor/pkg', '1.0.0', 'composer', true, false, null, '2.0.0');
    expect($pkg->availableVersion)->toBe('2.0.0');
});
