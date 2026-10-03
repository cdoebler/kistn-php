<?php

namespace Kistn;

use Kistn\Exception\InventoryException;

class Config
{
    private function __construct(
        private readonly string $baseUrl,
        private readonly string $projectId,
        private readonly string $token,
        private readonly TransmitMode $transmitComposerFiles,
        private readonly TransmitMode $transmitNpmFiles,
    ) {}

    public static function load(string $path): self
    {
        if (! file_exists($path)) {
            throw new InventoryException("Config file not found: {$path}");
        }

        /** @var mixed $data */
        $data = require $path;

        if (! is_array($data)) {
            throw new InventoryException("Config file must return an array: {$path}");
        }

        foreach (['base_url', 'project_id', 'token'] as $key) {
            if (empty($data[$key])) {
                throw new InventoryException("Config key '{$key}' is missing or empty in {$path}");
            }
        }

        $baseUrl = $data['base_url'];
        $projectId = $data['project_id'];
        $token = $data['token'];

        if (! is_string($baseUrl) || ! is_string($projectId) || ! is_string($token)) {
            throw new InventoryException("Config values must be strings in {$path}");
        }

        $transmitComposerFiles = self::resolveTransmitMode($data['transmit_composer_files'] ?? false, 'transmit_composer_files', $path);
        $transmitNpmFiles = self::resolveTransmitMode($data['transmit_npm_files'] ?? false, 'transmit_npm_files', $path);

        return new self(
            baseUrl: rtrim($baseUrl, '/'),
            projectId: $projectId,
            token: $token,
            transmitComposerFiles: $transmitComposerFiles,
            transmitNpmFiles: $transmitNpmFiles,
        );
    }

    private static function resolveTransmitMode(mixed $raw, string $key, string $path): TransmitMode
    {
        if (is_bool($raw)) {
            $raw = $raw ? 'true' : 'false';
        }

        if (! is_string($raw)) {
            throw new InventoryException("Config key '{$key}' must be a bool or string in {$path}");
        }

        // Uploading lock files shares the full dependency tree with the server — opt-in only, also for typos.
        return TransmitMode::tryFrom($raw) ?? TransmitMode::Never;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function transmitComposerFiles(): TransmitMode
    {
        return $this->transmitComposerFiles;
    }

    public function transmitNpmFiles(): TransmitMode
    {
        return $this->transmitNpmFiles;
    }
}
