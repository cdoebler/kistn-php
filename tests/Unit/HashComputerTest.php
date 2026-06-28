<?php

use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;
use Kistn\HashComputer;

test('HashComputer produces SHA-256 matching server algorithm', function () {
    $packages = [
        new Package('laravel/framework', 'v11.0.0', 'composer', true, false, 0),
        new Package('guzzlehttp/guzzle', '7.8.0', 'composer', false, false, null),
    ];
    $payload = new InventoryPayload($packages);

    // Reference: server algorithm (usort by name, json_encode, sha256)
    $sorted = [
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.0', 'is_direct' => false, 'is_dev' => false, 'depth' => null],
        ['name' => 'laravel/framework', 'version' => 'v11.0.0', 'is_direct' => true, 'is_dev' => false, 'depth' => 0],
    ];
    $expected = hash('sha256', json_encode($sorted));

    expect((new HashComputer())->compute($payload))->toBe($expected);
});

test('HashComputer is order-independent — packages sorted by name', function () {
    $pkgA = new Package('aaa/pkg', '1.0.0', 'composer', true, false, 0);
    $pkgB = new Package('zzz/pkg', '2.0.0', 'composer', false, false, null);

    $hashAB = (new HashComputer())->compute(new InventoryPayload([$pkgA, $pkgB]));
    $hashBA = (new HashComputer())->compute(new InventoryPayload([$pkgB, $pkgA]));

    expect($hashAB)->toBe($hashBA);
});

test('HashComputer returns different hash for different package lists', function () {
    $p1 = new InventoryPayload([new Package('a/b', '1.0.0', 'composer', true, false, 0)]);
    $p2 = new InventoryPayload([new Package('a/b', '2.0.0', 'composer', true, false, 0)]);

    expect((new HashComputer())->compute($p1))->not->toBe((new HashComputer())->compute($p2));
});
