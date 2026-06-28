<?php

use Kistn\Config;
use Kistn\Exception\InventoryException;

beforeEach(function () {
    $this->validConfigPath = sys_get_temp_dir() . '/test-inventory-config-' . uniqid() . '.php';
    file_put_contents($this->validConfigPath, '<?php return ["base_url" => "https://example.com", "project_id" => "uuid-123", "token" => "tok_abc"];');
});

afterEach(function () {
    if (file_exists($this->validConfigPath)) {
        unlink($this->validConfigPath);
    }
});

test('Config loads valid config file', function () {
    $config = Config::load($this->validConfigPath);
    expect($config->baseUrl())->toBe('https://example.com');
    expect($config->projectId())->toBe('uuid-123');
    expect($config->token())->toBe('tok_abc');
});

test('Config throws when file does not exist', function () {
    expect(fn () => Config::load('/nonexistent/path.php'))
        ->toThrow(InventoryException::class, 'not found');
});

test('Config throws when base_url is missing', function () {
    $path = sys_get_temp_dir() . '/test-config-missing-' . uniqid() . '.php';
    file_put_contents($path, '<?php return ["project_id" => "uuid", "token" => "tok"];');
    expect(fn () => Config::load($path))->toThrow(InventoryException::class, 'base_url');
    unlink($path);
});

test('Config throws when project_id is missing', function () {
    $path = sys_get_temp_dir() . '/test-config-missing-' . uniqid() . '.php';
    file_put_contents($path, '<?php return ["base_url" => "https://x.com", "token" => "tok"];');
    expect(fn () => Config::load($path))->toThrow(InventoryException::class, 'project_id');
    unlink($path);
});

test('Config throws when token is missing', function () {
    $path = sys_get_temp_dir() . '/test-config-missing-' . uniqid() . '.php';
    file_put_contents($path, '<?php return ["base_url" => "https://x.com", "project_id" => "uuid"];');
    expect(fn () => Config::load($path))->toThrow(InventoryException::class, 'token');
    unlink($path);
});
