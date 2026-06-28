<?php

use Kistn\Dto\Finding;

test('Finding stores all properties', function () {
    $f = new Finding('vendor/pkg', '1.0.0', 'GHSA-xxxx-yyyy-zzzz', 'high');
    expect($f->packageName)->toBe('vendor/pkg');
    expect($f->packageVersion)->toBe('1.0.0');
    expect($f->advisoryId)->toBe('GHSA-xxxx-yyyy-zzzz');
    expect($f->severity)->toBe('high');
});
