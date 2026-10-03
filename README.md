# kistn/php-client

PSR-18 PHP client for the Kistn API. Framework-agnostic — requires any PSR-18 HTTP client and PSR-17 HTTP factory.

## Installation

```bash
composer require kistn/php-client
```

Requires a PSR-18 HTTP client (e.g. `guzzlehttp/guzzle`) and PSR-17 factory.

## Usage

### Standalone

Copy `kistn.config.php.example` to `kistn.config.php` and fill in your credentials:

```php
return [
    'base_url'   => 'https://your-server.example',
    'project_id' => 'your-project-uuid-here',
    'token'      => 'your-api-token-here',

    'transmit_composer_files' => false, // false (default) | true | 'on-demand'
    'transmit_npm_files'      => false, // false (default) | true | 'on-demand'
];
```

#### Lock file upload (`transmit_composer_files` / `transmit_npm_files`)

Every push sends the package inventory (names, versions, dependency flags) and your local audit findings.
Your lock and manifest files (`composer.lock`, `composer.json`, `installed.json`, `package-lock.json`, `package.json`)
are **not** uploaded unless you opt in:

| Value | Behaviour |
|---|---|
| `false` (default, also used for missing/invalid values) | No files are uploaded. |
| `true` | Upload the files whenever the package list changed. |
| `'on-demand'` | Upload only when `composer` / `npm` is unavailable locally, so the server can run the audit instead. |

Uploaded files let the Kistn server run its own audit and cross-check your local findings. They contain
your full dependency tree, so enable this only if you are fine sharing that data with the server.

Wire up and push:

```php
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Kistn\Cache\LocalHashCache;
use Kistn\Client\InventoryClient;
use Kistn\Collector\ComposerCollector;
use Kistn\Collector\NpmCollector;
use Kistn\Config;
use Kistn\InventoryPusher;
use Kistn\Process\ShellProcessRunner;

$config = Config::load(__DIR__ . '/kistn.config.php');
$factory = new HttpFactory();

$client = new InventoryClient(
    baseUrl: $config->baseUrl(),
    projectId: $config->projectId(),
    token: $config->token(),
    httpClient: new GuzzleClient(),
    requestFactory: $factory,
    streamFactory: $factory,
);

$runner = new ShellProcessRunner();

$pusher = new InventoryPusher(
    client: $client,
    collectors: [
        new ComposerCollector(
            lockFilePath: __DIR__ . '/composer.lock',
            composerJsonPath: __DIR__ . '/composer.json',
            runner: $runner,
            installedJsonPath: __DIR__ . '/vendor/composer/installed.json', // preferred when present
        ),
        new NpmCollector(
            lockFilePath: __DIR__ . '/package-lock.json',
            packageJsonPath: __DIR__ . '/package.json',
            runner: $runner,
        ),
    ],
    cache: new LocalHashCache(__DIR__ . '/.inventory.hash'),
    transmitComposerFiles: $config->transmitComposerFiles(),
    transmitNpmFiles: $config->transmitNpmFiles(),
);

$pusher->pushAll();
```

### Push Flow

1. GET all ecosystem hashes — one call (`GET /hashes`), always runs.
2. Per ecosystem: check local lock-file hash against local cache — skip collection if unchanged.
3. Collect packages via `composer show --format=json` / `npm list --json`.
4. Compute content hash — skip ecosystem if matches server.
5. POST bundled payload (all changed ecosystems in one call).
6. Upload lock files for ecosystems with package-level changes — only if enabled via `TransmitMode` (off by default).
7. Store lock-file hash per ecosystem in local cache.

## Architecture

```
InventoryPusher
├── InventoryClientInterface   (HTTP: GET hashes, POST inventory, upload files)
├── CollectorInterface[]       (ComposerCollector, NpmCollector)
└── LocalHashCache             (file-based lock-file hash cache)

CollectorInterface
├── ComposerCollector  → installed.json (preferred) or composer.lock
│   ├── InstalledJsonParser  (detects private packages via notification-url)
│   └── ComposerLockParser   (fallback)
└── NpmCollector       → package-lock.json + NpmLockParser (BFS depth)

InventoryPayload
├── Package[]          (name, version, is_direct, is_dev, is_child, depth, source_url, available_version, author)
├── Finding[]          (package_name, package_version, advisory_id, severity)
└── privatePackages[]  (slugs confirmed absent from advisory DBs)
```

## Testing

```bash
composer run pest
composer run ci:check   # phpstan + rector:check + pest
```
