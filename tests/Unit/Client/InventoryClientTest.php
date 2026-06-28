<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Kistn\Client\InventoryClient;
use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;
use Kistn\Exception\ApiException;

function makeMockHttpClient(array $responses): ClientInterface
{
    return new class ($responses) implements ClientInterface {
        public array $requests = [];

        public function __construct(private array $queue)
        {
        }

        public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            $this->requests[] = $request;
            return array_shift($this->queue);
        }
    };
}

function makeClient(ClientInterface $http): InventoryClient
{
    $factory = new Psr17Factory();
    return new InventoryClient(
        baseUrl: 'https://example.com',
        projectId: 'test-uuid',
        token: 'test-token',
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
    );
}

test('getHashes returns all ecosystem hashes from server response', function () {
    $http = makeMockHttpClient([
        new Response(200, [], json_encode(['composer' => 'sha256:abc123', 'npm' => null, 'wp-plugin' => null])),
    ]);
    $client = makeClient($http);
    $hashes = $client->getHashes();
    expect($hashes['composer'])->toBe('sha256:abc123')
        ->and($hashes['npm'])->toBeNull();
});

test('getHashes sends GET to project-level hashes endpoint', function () {
    $http = makeMockHttpClient([
        new Response(200, [], json_encode(['composer' => null])),
    ]);
    $client = makeClient($http);
    $client->getHashes();

    $uri = $http->requests[0]->getUri();
    expect((string) $uri)->toBe('https://example.com/api/projects/test-uuid/hashes');
});

test('getHashes sends Authorization header', function () {
    $http = makeMockHttpClient([
        new Response(200, [], json_encode(['composer' => null])),
    ]);
    $client = makeClient($http);
    $client->getHashes();

    expect($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('getHashes returns empty array when response body is invalid', function () {
    $http = makeMockHttpClient([
        new Response(200, [], 'not-json'),
    ]);
    $client = makeClient($http);
    expect($client->getHashes())->toBe([]);
});

test('push sends POST to project-level inventory endpoint', function () {
    $http = makeMockHttpClient([
        new Response(202, [], ''),
    ]);
    $client = makeClient($http);
    $payload = new InventoryPayload([
        new Package('vendor/pkg', '1.0.0', 'composer', true, 0),
    ]);
    $client->push(['composer' => $payload]);

    $uri = $http->requests[0]->getUri();
    expect((string) $uri)->toBe('https://example.com/api/projects/test-uuid/inventory');
});

test('push wraps payloads under ecosystems key', function () {
    $http = makeMockHttpClient([
        new Response(202, [], ''),
    ]);
    $client = makeClient($http);
    $payload = new InventoryPayload([
        new Package('vendor/pkg', '1.0.0', 'composer', true, 0),
    ]);
    $client->push(['composer' => $payload]);

    $body = json_decode((string) $http->requests[0]->getBody(), true);
    expect($body)->toHaveKey('ecosystems')
        ->and($body['ecosystems'])->toHaveKey('composer')
        ->and($body['ecosystems']['composer']['packages'][0]['name'])->toBe('vendor/pkg')
        ->and($body['ecosystems']['composer']['packages'][0]['is_direct'])->toBeTrue();
});

test('push sends private_packages per ecosystem', function () {
    $http = makeMockHttpClient([
        new Response(202, [], ''),
    ]);
    $client = makeClient($http);
    $payload = new InventoryPayload([], [], ['my-vendor/private-lib']);
    $client->push(['composer' => $payload]);

    $body = json_decode((string) $http->requests[0]->getBody(), true);
    expect($body['ecosystems']['composer']['private_packages'])->toBe(['my-vendor/private-lib']);
});

test('push bundles multiple ecosystems in one request', function () {
    $http = makeMockHttpClient([
        new Response(202, [], ''),
    ]);
    $client = makeClient($http);
    $composerPayload = new InventoryPayload([new Package('vendor/a', '1.0.0', 'composer', true, 0)]);
    $npmPayload      = new InventoryPayload([new Package('lodash', '4.17.21', 'npm', true, 0)]);
    $client->push(['composer' => $composerPayload, 'npm' => $npmPayload]);

    $body = json_decode((string) $http->requests[0]->getBody(), true);
    expect($body['ecosystems'])->toHaveKey('composer')
        ->and($body['ecosystems'])->toHaveKey('npm');
    expect($http->requests)->toHaveCount(1);
});

test('push throws ApiException on non-2xx response', function () {
    $http = makeMockHttpClient([
        new Response(422, [], '{"message":"validation error"}'),
    ]);
    $client = makeClient($http);

    expect(fn () => $client->push(['composer' => new InventoryPayload([])]))
        ->toThrow(ApiException::class);
});

test('push includes available_version in package payload when set', function () {
    $http = makeMockHttpClient([
        new Response(202, [], ''),
    ]);
    $client = makeClient($http);
    $payload = new InventoryPayload([
        new Package('vendor/pkg', '1.0.0', 'composer', true, false, null, '2.0.0'),
    ]);
    $client->push(['composer' => $payload]);

    $body = json_decode((string) $http->requests[0]->getBody(), true);
    expect($body['ecosystems']['composer']['packages'][0]['available_version'])->toBe('2.0.0');
});

test('push sends null available_version when not set', function () {
    $http = makeMockHttpClient([
        new Response(202, [], ''),
    ]);
    $client = makeClient($http);
    $payload = new InventoryPayload([
        new Package('vendor/pkg', '1.0.0', 'composer', true),
    ]);
    $client->push(['composer' => $payload]);

    $body = json_decode((string) $http->requests[0]->getBody(), true);
    expect($body['ecosystems']['composer']['packages'][0]['available_version'])->toBeNull();
});

it('uploadFiles sends multipart POST to project-level files endpoint', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($tmpFile, '{"packages":[]}');

    $http = makeMockHttpClient([
        new Response(202, [], ''),
    ]);
    $client = makeClient($http);
    $client->uploadFiles(['composer_lock' => $tmpFile]);

    $request = $http->requests[0];
    expect($request->getMethod())->toBe('POST');
    expect((string) $request->getUri())->toBe('https://example.com/api/projects/test-uuid/files');
    expect($request->getHeaderLine('Content-Type'))->toContain('multipart/form-data; boundary=');
    $body = (string) $request->getBody();
    expect($body)->toContain('composer_lock');
    expect($body)->toContain('Content-Type: application/octet-stream');

    unlink($tmpFile);
});

it('uploadFiles accepts multiple files in one request', function (): void {
    $tmpComposer = tempnam(sys_get_temp_dir(), 'composer');
    $tmpNpm      = tempnam(sys_get_temp_dir(), 'npm');
    file_put_contents($tmpComposer, '{}');
    file_put_contents($tmpNpm, '{}');

    $http = makeMockHttpClient([
        new Response(202, [], ''),
    ]);
    $client = makeClient($http);
    $client->uploadFiles(['composer_lock' => $tmpComposer, 'package_lock' => $tmpNpm]);

    expect($http->requests)->toHaveCount(1);
    $body = (string) $http->requests[0]->getBody();
    expect($body)->toContain('composer_lock')
        ->and($body)->toContain('package_lock');

    unlink($tmpComposer);
    unlink($tmpNpm);
});

it('uploadFiles throws exception on non-2xx response', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($tmpFile, '{}');

    $http = makeMockHttpClient([
        new Response(500, [], 'Server Error'),
    ]);
    $client = makeClient($http);

    expect(fn () => $client->uploadFiles(['composer_lock' => $tmpFile]))
        ->toThrow(ApiException::class);

    unlink($tmpFile);
});

it('uploadFiles does nothing when filePaths is empty', function (): void {
    $http = makeMockHttpClient([]);
    $client = makeClient($http);
    $client->uploadFiles([]);

    expect($http->requests)->toBeEmpty();
});
