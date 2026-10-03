<?php

use Kistn\Config;
use Kistn\Exception\InventoryException;
use Kistn\TransmitMode;

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

test('Config does not transmit files unless enabled', function (string $transmitEntries, TransmitMode $expected) {
    $path = sys_get_temp_dir() . '/test-config-transmit-' . uniqid() . '.php';
    file_put_contents($path, '<?php return ["base_url" => "https://x.com", "project_id" => "uuid", "token" => "tok"' . $transmitEntries . '];');

    $config = Config::load($path);
    unlink($path);

    expect($config->transmitComposerFiles())->toBe($expected)
        ->and($config->transmitNpmFiles())->toBe($expected);
})->with([
    'missing' => ['', TransmitMode::Never],
    'typo' => [', "transmit_composer_files" => "ture", "transmit_npm_files" => "ture"', TransmitMode::Never],
    'bool true' => [', "transmit_composer_files" => true, "transmit_npm_files" => true', TransmitMode::Always],
    'on-demand' => [', "transmit_composer_files" => "on-demand", "transmit_npm_files" => "on-demand"', TransmitMode::OnDemand],
]);

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
