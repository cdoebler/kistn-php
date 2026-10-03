<?php

use Kistn\Cache\LocalHashCache;
use Kistn\Client\InventoryClientInterface;
use Kistn\Collector\CollectorInterface;
use Kistn\Dto\InventoryPayload;
use Kistn\Dto\Package;
use Kistn\InventoryPusher;
use Kistn\TransmitMode;

function pusherMakePayload(): InventoryPayload
{
    return new InventoryPayload([new Package('vendor/pkg', '1.0.0', 'composer', true, 0)]);
}

function pusherMakeCollector(string $ecosystem, ?InventoryPayload $payload, ?string $lockHash): CollectorInterface
{
    return new readonly class ($ecosystem, $payload, $lockHash) implements CollectorInterface {
        public function __construct(
            private string $eco,
            private ?InventoryPayload $payload,
            private ?string $hash,
        ) {}
        public function collect(): ?InventoryPayload { return $this->payload; }
        public function ecosystem(): string { return $this->eco; }
        public function lockFileHash(): ?string { return $this->hash; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return []; }
    };
}

/**
 * @param array<string> $pushCalls  Populated with ecosystem keys from each push() call
 */
function pusherMakeClient(array &$pushCalls, ?string $serverHash = null): InventoryClientInterface
{
    return new class ($pushCalls, $serverHash) implements InventoryClientInterface {
        public function __construct(private array &$calls, private readonly ?string $serverHash) {}
        /** @return array<string, string|null> */
        public function getHashes(): array { return ['composer' => $this->serverHash, 'npm' => null]; }
        /** @param array<string, InventoryPayload> $payloads */
        public function push(array $payloads): void { foreach (array_keys($payloads) as $eco) { $this->calls[] = $eco; } }
        public function uploadFiles(array $filePaths): void {}
    };
}

function pusherMakeCachePath(): string
{
    return sys_get_temp_dir() . '/.test-pusher-cache-' . uniqid();
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/.test-pusher-cache-*') ?: [] as $file) {
        @unlink($file);
    }
});

test('push is skipped when collector returns null (ecosystem absent)', function () {
    $pushCalls = [];
    $cache = new LocalHashCache(pusherMakeCachePath());
    $client = pusherMakeClient($pushCalls);
    $collector = pusherMakeCollector('composer', null, null);

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toBeEmpty();
});

test('push is skipped when lock file hash unchanged and tool is unavailable', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:same');
    $cache->setOutdatedHash('composer', hash('sha256', (string) json_encode([])));

    $client = pusherMakeClient($pushCalls, 'server-hash');

    $collector = new readonly class implements CollectorInterface {
        public function collect(): ?InventoryPayload { return pusherMakePayload(); }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return 'sha256:same'; }
        public function isToolAvailable(): bool { return false; }
        public function lockFiles(): array { return []; }
    };

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toBeEmpty();
    unlink($cachePath);
});

test('push is skipped when lock file hash unchanged, tool available, and findings unchanged', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:same');

    $payload = pusherMakePayload(); // no findings
    $computer = new \Kistn\HashComputer();
    $contentHash = $computer->compute($payload);

    $findingsHash = hash('sha256', (string) json_encode([]));
    $cache->setFindingsHash('composer', $findingsHash);
    $cache->setOutdatedHash('composer', hash('sha256', (string) json_encode([])));

    $client = pusherMakeClient($pushCalls, $contentHash);

    $collector = new readonly class ('sha256:same', $payload) implements CollectorInterface {
        public function __construct(private string $lh, private InventoryPayload $p) {}
        public function collect(): ?InventoryPayload { return $this->p; }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return $this->lh; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return []; }
    };

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toBeEmpty();
    unlink($cachePath);
});

test('push is sent for findings-only change when tool available and packages match server', function () {
    $pushCalls = [];
    $uploadCalled = false;
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:same');

    $payload = new InventoryPayload(
        [new \Kistn\Dto\Package('vendor/pkg', '1.0.0', 'composer', true, 0)],
        [new \Kistn\Dto\Finding('vendor/pkg', '1.0.0', 'GHSA-new-cve-0001', 'high')],
    );

    $computer = new \Kistn\HashComputer();
    $contentHash = $computer->compute($payload);

    $cache->setFindingsHash('composer', hash('sha256', (string) json_encode([])));

    $client = new class ($pushCalls, $uploadCalled, $contentHash) implements InventoryClientInterface {
        public function __construct(
            private array &$pushCalls,
            private bool &$uploadCalled,
            private readonly string $serverHash,
        ) {}
        /** @return array<string, string|null> */
        public function getHashes(): array { return ['composer' => $this->serverHash]; }
        /** @param array<string, InventoryPayload> $payloads */
        public function push(array $payloads): void { foreach (array_keys($payloads) as $eco) { $this->pushCalls[] = $eco; } }
        public function uploadFiles(array $filePaths): void { $this->uploadCalled = true; }
    };

    $collector = new readonly class ('sha256:same', $payload) implements CollectorInterface {
        public function __construct(private string $lh, private InventoryPayload $p) {}
        public function collect(): ?InventoryPayload { return $this->p; }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return $this->lh; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return ['composer_lock' => '/tmp/composer.lock']; }
    };

    (new InventoryPusher($client, [$collector], $cache, TransmitMode::Always))->pushAll();

    expect($pushCalls)->toHaveCount(1)->toContain('composer');
    expect($uploadCalled)->toBeFalse(); // no file upload on findings-only push
    unlink($cachePath);
});

test('findings hash cache is updated after findings-only push', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:same');

    $finding = new \Kistn\Dto\Finding('vendor/pkg', '1.0.0', 'GHSA-new-0001', 'high');
    $payload = new InventoryPayload(
        [new \Kistn\Dto\Package('vendor/pkg', '1.0.0', 'composer', true, 0)],
        [$finding],
    );

    $computer = new \Kistn\HashComputer();
    $contentHash = $computer->compute($payload);

    $cache->setFindingsHash('composer', hash('sha256', (string) json_encode([])));

    $client = pusherMakeClient($pushCalls, $contentHash);

    $collector = new readonly class ('sha256:same', $payload) implements CollectorInterface {
        public function __construct(private string $lh, private InventoryPayload $p) {}
        public function collect(): ?InventoryPayload { return $this->p; }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return $this->lh; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return []; }
    };

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    $expectedFindingsHash = hash('sha256', (string) json_encode([[
        'advisory_id'     => 'GHSA-new-0001',
        'package_name'    => 'vendor/pkg',
        'package_version' => '1.0.0',
        'severity'        => 'high',
    ]]));

    expect($cache->getFindingsHash('composer'))->toBe($expectedFindingsHash);
    unlink($cachePath);
});

test('findings hash cache is updated after full package push', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);

    $finding = new \Kistn\Dto\Finding('vendor/pkg', '1.0.0', 'GHSA-pkg-0001', 'medium');
    $payload = new InventoryPayload(
        [new \Kistn\Dto\Package('vendor/pkg', '1.0.0', 'composer', true, 0)],
        [$finding],
    );

    $client = pusherMakeClient($pushCalls, 'completely-different-server-hash');

    $collector = new readonly class ('sha256:new', $payload) implements CollectorInterface {
        public function __construct(private string $lh, private InventoryPayload $p) {}
        public function collect(): ?InventoryPayload { return $this->p; }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return $this->lh; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return []; }
    };

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toHaveCount(1);
    expect($cache->getFindingsHash('composer'))->not->toBeNull();
    unlink($cachePath);
});

test('push is sent when lock file hash changed and content differs from server', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:old');

    $client = pusherMakeClient($pushCalls, 'completely-different-server-hash');
    $collector = pusherMakeCollector('composer', pusherMakePayload(), 'sha256:new');

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toHaveCount(1)->toContain('composer');
    unlink($cachePath);
});

test('push is skipped when computed content hash matches server hash', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:old');

    $payload = pusherMakePayload();

    $computer = new \Kistn\HashComputer();
    $contentHash = $computer->compute($payload);

    $cache->setFindingsHash('composer', hash('sha256', (string) json_encode([])));
    $cache->setOutdatedHash('composer', hash('sha256', (string) json_encode([])));

    $client = pusherMakeClient($pushCalls, $contentHash);
    $collector = pusherMakeCollector('composer', $payload, 'sha256:new');

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toBeEmpty();
    unlink($cachePath);
});

test('cache is updated with new lock file hash after push', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);

    $client = pusherMakeClient($pushCalls, null);
    $collector = pusherMakeCollector('composer', pusherMakePayload(), 'sha256:fresh');

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($cache->get('composer'))->toBe('sha256:fresh');
    unlink($cachePath);
});

it('uploads composer files after push only when enabled', function (array $transmitModes, bool $expectedUpload): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($tmpFile, '{}');

    $uploadCalled = false;
    $client = new class ($uploadCalled) implements InventoryClientInterface {
        public function __construct(private bool &$uploadCalled) {}
        /** @return array<string, string|null> */
        public function getHashes(): array { return ['composer' => 'different']; }
        /** @param array<string, InventoryPayload> $payloads */
        public function push(array $payloads): void {}
        public function uploadFiles(array $filePaths): void { $this->uploadCalled = true; }
    };

    $collector = new readonly class ($tmpFile) implements CollectorInterface {
        public function __construct(private string $tmpFile) {}
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return 'hash1'; }
        public function collect(): ?InventoryPayload { return new InventoryPayload([], []); }
        public function lockFiles(): array { return ['composer_lock' => $this->tmpFile]; }
        public function isToolAvailable(): bool { return true; }
    };

    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'old-hash');

    $pusher = new InventoryPusher($client, [$collector], $cache, ...$transmitModes);
    $pusher->pushAll();

    expect($uploadCalled)->toBe($expectedUpload);
    unlink($tmpFile);
    unlink($cachePath);
})->with([
    'Always' => [[TransmitMode::Always, TransmitMode::Never], true],
    'default' => [[], false],
]);

it('skips upload when TransmitMode is Never', function (): void {
    $uploadCalled = false;
    $client = new class ($uploadCalled) implements InventoryClientInterface {
        public function __construct(private bool &$uploadCalled) {}
        /** @return array<string, string|null> */
        public function getHashes(): array { return ['composer' => 'different']; }
        /** @param array<string, InventoryPayload> $payloads */
        public function push(array $payloads): void {}
        public function uploadFiles(array $filePaths): void { $this->uploadCalled = true; }
    };

    $collector = new class implements CollectorInterface {
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return 'hash1'; }
        public function collect(): ?InventoryPayload { return new InventoryPayload([], []); }
        public function lockFiles(): array { return []; }
        public function isToolAvailable(): bool { return true; }
    };

    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'old-hash');

    $pusher = new InventoryPusher($client, [$collector], $cache, TransmitMode::Never, TransmitMode::Never);
    $pusher->pushAll();

    expect($uploadCalled)->toBeFalse();
    unlink($cachePath);
});

it('uploads when OnDemand and tool is unavailable', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($tmpFile, '{}');
    $uploadCalled = false;

    $client = new class ($uploadCalled) implements InventoryClientInterface {
        public function __construct(private bool &$uploadCalled) {}
        /** @return array<string, string|null> */
        public function getHashes(): array { return ['composer' => 'different']; }
        /** @param array<string, InventoryPayload> $payloads */
        public function push(array $payloads): void {}
        public function uploadFiles(array $filePaths): void { $this->uploadCalled = true; }
    };

    $collector = new readonly class ($tmpFile) implements CollectorInterface {
        public function __construct(private string $tmpFile) {}
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return 'hash1'; }
        public function collect(): ?InventoryPayload { return new InventoryPayload([], []); }
        public function lockFiles(): array { return ['composer_lock' => $this->tmpFile]; }
        public function isToolAvailable(): bool { return false; }
    };

    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'old-hash');

    $pusher = new InventoryPusher($client, [$collector], $cache, TransmitMode::OnDemand, TransmitMode::Never);
    $pusher->pushAll();

    expect($uploadCalled)->toBeTrue();
    unlink($tmpFile);
    unlink($cachePath);
});

it('skips upload when OnDemand and tool is available', function (): void {
    $uploadCalled = false;

    $client = new class ($uploadCalled) implements InventoryClientInterface {
        public function __construct(private bool &$uploadCalled) {}
        /** @return array<string, string|null> */
        public function getHashes(): array { return ['composer' => 'different']; }
        /** @param array<string, InventoryPayload> $payloads */
        public function push(array $payloads): void {}
        public function uploadFiles(array $filePaths): void { $this->uploadCalled = true; }
    };

    $collector = new class implements CollectorInterface {
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return 'hash1'; }
        public function collect(): ?InventoryPayload { return new InventoryPayload([], []); }
        public function lockFiles(): array { return []; }
        public function isToolAvailable(): bool { return true; }
    };

    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'old-hash');

    $pusher = new InventoryPusher($client, [$collector], $cache, TransmitMode::OnDemand, TransmitMode::Never);
    $pusher->pushAll();

    expect($uploadCalled)->toBeFalse();
    unlink($cachePath);
});

test('push is sent for outdated-only change when packages and findings match server', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:same');

    $payload = new InventoryPayload([
        new \Kistn\Dto\Package('vendor/pkg', '1.0.0', 'composer', true, false, null, '2.0.0'),
    ]);

    $computer = new \Kistn\HashComputer();
    $contentHash = $computer->compute($payload);

    $cache->setFindingsHash('composer', hash('sha256', (string) json_encode([])));
    $cache->setOutdatedHash('composer', hash('sha256', (string) json_encode([])));

    $client = pusherMakeClient($pushCalls, $contentHash);

    $collector = new readonly class ('sha256:same', $payload) implements CollectorInterface {
        public function __construct(private string $lh, private InventoryPayload $p) {}
        public function collect(): ?InventoryPayload { return $this->p; }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return $this->lh; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return []; }
    };

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toHaveCount(1)->toContain('composer');
    unlink($cachePath);
});

test('outdated hash cache is updated after outdated-only push', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:same');

    $payload = new InventoryPayload([
        new \Kistn\Dto\Package('vendor/pkg', '1.0.0', 'composer', true, false, null, '2.0.0'),
    ]);

    $computer = new \Kistn\HashComputer();
    $contentHash = $computer->compute($payload);

    $cache->setFindingsHash('composer', hash('sha256', (string) json_encode([])));
    $cache->setOutdatedHash('composer', hash('sha256', (string) json_encode([])));

    $client = pusherMakeClient($pushCalls, $contentHash);

    $collector = new readonly class ('sha256:same', $payload) implements CollectorInterface {
        public function __construct(private string $lh, private InventoryPayload $p) {}
        public function collect(): ?InventoryPayload { return $this->p; }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return $this->lh; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return []; }
    };

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    $expectedOutdatedHash = hash('sha256', (string) json_encode([[
        'name' => 'vendor/pkg',
        'available_version' => '2.0.0',
    ]]));

    expect($cache->getOutdatedHash('composer'))->toBe($expectedOutdatedHash);
    unlink($cachePath);
});

test('outdated hash cache is updated after full package push', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);

    $payload = new InventoryPayload([
        new \Kistn\Dto\Package('vendor/pkg', '1.0.0', 'composer', true, false, null, '2.0.0'),
    ]);

    $client = pusherMakeClient($pushCalls, 'completely-different-server-hash');

    $collector = new readonly class ('sha256:new', $payload) implements CollectorInterface {
        public function __construct(private string $lh, private InventoryPayload $p) {}
        public function collect(): ?InventoryPayload { return $this->p; }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return $this->lh; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return []; }
    };

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toHaveCount(1);
    expect($cache->getOutdatedHash('composer'))->not->toBeNull();
    unlink($cachePath);
});

test('push is still skipped when packages, findings, and outdated are all unchanged', function () {
    $pushCalls = [];
    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $cache->set('composer', 'sha256:same');

    $payload = new InventoryPayload([
        new \Kistn\Dto\Package('vendor/pkg', '1.0.0', 'composer', true, false, null, '2.0.0'),
    ]);

    $computer = new \Kistn\HashComputer();
    $contentHash = $computer->compute($payload);

    $cache->setFindingsHash('composer', hash('sha256', (string) json_encode([])));

    $expectedOutdatedHash = hash('sha256', (string) json_encode([[
        'name' => 'vendor/pkg',
        'available_version' => '2.0.0',
    ]]));
    $cache->setOutdatedHash('composer', $expectedOutdatedHash);

    $client = pusherMakeClient($pushCalls, $contentHash);

    $collector = new readonly class ('sha256:same', $payload) implements CollectorInterface {
        public function __construct(private string $lh, private InventoryPayload $p) {}
        public function collect(): ?InventoryPayload { return $this->p; }
        public function ecosystem(): string { return 'composer'; }
        public function lockFileHash(): ?string { return $this->lh; }
        public function isToolAvailable(): bool { return true; }
        public function lockFiles(): array { return []; }
    };

    (new InventoryPusher($client, [$collector], $cache))->pushAll();

    expect($pushCalls)->toBeEmpty();
    unlink($cachePath);
});

test('multiple collectors bundle into a single push call', function () {
    $pushCallCount = 0;
    $client = new class ($pushCallCount) implements InventoryClientInterface {
        public function __construct(private int &$pushCallCount) {}
        /** @return array<string, string|null> */
        public function getHashes(): array { return ['composer' => 'old', 'npm' => 'old']; }
        /** @param array<string, InventoryPayload> $payloads */
        public function push(array $payloads): void { $this->pushCallCount++; }
        public function uploadFiles(array $filePaths): void {}
    };

    $cachePath = pusherMakeCachePath();
    $cache = new LocalHashCache($cachePath);
    $composerCollector = pusherMakeCollector('composer', pusherMakePayload(), 'hash-composer');
    $npmCollector      = pusherMakeCollector('npm', new InventoryPayload([new Package('lodash', '4.17.21', 'npm', true, 0)]), 'hash-npm');

    (new InventoryPusher($client, [$composerCollector, $npmCollector], $cache))->pushAll();

    expect($pushCallCount)->toBe(1);
    unlink($cachePath);
});
