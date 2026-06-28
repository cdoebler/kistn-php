<?php

use Kistn\Dto\Finding;
use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;

test('InventoryPayload stores packages and findings', function () {
    $pkg = new Package('vendor/pkg', '1.0.0', 'composer', true, 0);
    $finding = new Finding('vendor/pkg', '1.0.0', 'GHSA-xxxx', 'high');
    $payload = new InventoryPayload([$pkg], [$finding]);

    expect($payload->packages)->toHaveCount(1);
    expect($payload->findings)->toHaveCount(1);
});

test('InventoryPayload findings default to empty array', function () {
    $payload = new InventoryPayload([]);
    expect($payload->findings)->toBe([]);
});

test('InventoryPayload stores private packages', function () {
    $payload = new InventoryPayload([], [], ['my-vendor/private-lib']);
    expect($payload->privatePackages)->toBe(['my-vendor/private-lib']);
});

test('InventoryPayload private packages default to empty array', function () {
    $payload = new InventoryPayload([]);
    expect($payload->privatePackages)->toBe([]);
});
