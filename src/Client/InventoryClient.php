<?php

namespace Kistn\Client;

use Kistn\Dto\Finding;
use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;
use Kistn\Exception\ApiException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class InventoryClient implements InventoryClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $projectId,
        private readonly string $token,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function getHashes(): array
    {
        $url = "{$this->baseUrl}/api/projects/{$this->projectId}/hashes";

        $request = $this->requestFactory
            ->createRequest('GET', $url)
            ->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('Accept', 'application/json');

        $response = $this->httpClient->sendRequest($request);
        $body = (string) $response->getBody();
        $this->assertSuccessful($response->getStatusCode(), $body);

        $data = json_decode($body, true);

        if (! is_array($data)) {
            return [];
        }

        $result = [];

        foreach ($data as $ecosystem => $hash) {
            $result[(string) $ecosystem] = is_string($hash) ? $hash : null;
        }

        return $result;
    }

    /**
     * @param  array<string, InventoryPayload>  $payloads
     */
    public function push(array $payloads): void
    {
        $url = "{$this->baseUrl}/api/projects/{$this->projectId}/inventory";

        $ecosystems = [];

        foreach ($payloads as $ecosystem => $payload) {
            $ecosystems[$ecosystem] = [
                'packages' => array_map(
                    fn (Package $p): array => [
                        'name'              => $p->name,
                        'version'           => $p->version,
                        'is_direct'         => $p->isDirect,
                        'is_dev'            => $p->isDev,
                        'depth'             => $p->depth,
                        'available_version' => $p->availableVersion,
                    ],
                    $payload->packages,
                ),
                'findings' => array_map(
                    fn (Finding $f): array => [
                        'package_name'    => $f->packageName,
                        'package_version' => $f->packageVersion,
                        'advisory_id'     => $f->advisoryId,
                        'severity'        => $f->severity,
                    ],
                    $payload->findings,
                ),
                'private_packages' => $payload->privatePackages,
            ];
        }

        $body = json_encode(['ecosystems' => $ecosystems], JSON_THROW_ON_ERROR);

        $request = $this->requestFactory
            ->createRequest('POST', $url)
            ->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->httpClient->sendRequest($request);
        $this->assertSuccessful($response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param  array<string, string>  $filePaths  Map of field name => absolute file path
     */
    public function uploadFiles(array $filePaths): void
    {
        if ($filePaths === []) {
            return;
        }

        $url      = "{$this->baseUrl}/api/projects/{$this->projectId}/files";
        $boundary = bin2hex(random_bytes(16));
        $body     = $this->buildMultipart($boundary, $filePaths);

        $request = $this->requestFactory
            ->createRequest('POST', $url)
            ->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('Content-Type', "multipart/form-data; boundary={$boundary}")
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->httpClient->sendRequest($request);
        $this->assertSuccessful($response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param  array<string, string>  $filePaths
     */
    private function buildMultipart(string $boundary, array $filePaths): string
    {
        $body = '';

        foreach ($filePaths as $fieldName => $path) {
            $raw = file_get_contents($path);

            if ($raw === false) {
                continue;
            }

            $compressed = gzencode($raw, 6);

            if ($compressed === false) {
                continue;
            }

            $gzFilename = basename($path).'.gz';
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$fieldName}\"; filename=\"{$gzFilename}\"\r\n";
            $body .= "Content-Type: application/octet-stream\r\n\r\n";
            $body .= $compressed."\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return $body;
    }

    private function assertSuccessful(int $statusCode, string $body): void
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ApiException($statusCode, $body);
        }
    }
}
