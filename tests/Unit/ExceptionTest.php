<?php

use Kistn\Exception\ApiException;
use Kistn\Exception\CollectorException;
use Kistn\Exception\InventoryException;

test('InventoryException extends RuntimeException', function () {
    $e = new InventoryException('oops');
    expect($e)->toBeInstanceOf(RuntimeException::class);
    expect($e->getMessage())->toBe('oops');
});

test('ApiException extends InventoryException and carries status and body', function () {
    $e = new ApiException(422, '{"error":"bad"}');
    expect($e)->toBeInstanceOf(InventoryException::class);
    expect($e->getStatusCode())->toBe(422);
    expect($e->getResponseBody())->toBe('{"error":"bad"}');
    expect($e->getMessage())->toContain('422');
});

test('CollectorException extends InventoryException', function () {
    $e = new CollectorException('composer not found');
    expect($e)->toBeInstanceOf(InventoryException::class);
    expect($e->getMessage())->toBe('composer not found');
});
