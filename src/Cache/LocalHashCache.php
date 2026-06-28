<?php

namespace Kistn\Cache;

class LocalHashCache
{
    public function __construct(private readonly string $path) {}

    public function get(string $ecosystem): ?string
    {
        if (! file_exists($this->path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->path), true);

        if (! is_array($data)) {
            return null;
        }

        if (! isset($data[$ecosystem])) {
            return null;
        }

        $value = $data[$ecosystem];

        return is_string($value) ? $value : null;
    }

    public function set(string $ecosystem, string $hash): void
    {
        $data = [];

        if (file_exists($this->path)) {
            $decoded = json_decode((string) file_get_contents($this->path), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $data[$ecosystem] = $hash;

        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->path, (string) json_encode($data));
    }

    public function getFindingsHash(string $ecosystem): ?string
    {
        return $this->get("{$ecosystem}.findings");
    }

    public function setFindingsHash(string $ecosystem, string $hash): void
    {
        $this->set("{$ecosystem}.findings", $hash);
    }

    public function getOutdatedHash(string $ecosystem): ?string
    {
        return $this->get("{$ecosystem}.outdated");
    }

    public function setOutdatedHash(string $ecosystem, string $hash): void
    {
        $this->set("{$ecosystem}.outdated", $hash);
    }
}
