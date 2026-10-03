<?php

declare(strict_types=1);

use SymPress\Framework\SymPressFrameworkBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\AbstractKernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$root = dirname(__DIR__, 2);
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$directory = $arguments[1];
$mode = $arguments[2];
$poison = ($arguments[3] ?? null) === 'poison';
if ($poison) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/pools/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $bytes = file_get_contents($file->getPathname());
        $offset = strpos($bytes, 'sympress-symfony-cache-v1:');
        if ($offset !== false) {
            file_put_contents($file->getPathname(), substr($bytes, 0, $offset) . serialize((object) ['untrusted' => true]));
        }
    }
    $pdo = new PDO('sqlite:' . $directory . '/payload.sqlite');
    $query = $pdo->prepare('UPDATE cache_items SET item_data = ?');
    $query->execute([serialize((object) ['untrusted' => true])]);
}
unset($_ENV['APP_SECRET'], $_SERVER['APP_SECRET']);
putenv('APP_SECRET');
if ($mode !== 'missing') {
    $_ENV['APP_SECRET'] = $mode === 'short' ? 'old-short-secret' : str_repeat('test-secret-', 4);
}
$_ENV['SYMPRESS_PROJECT_DIR'] = $directory;
$kernel = new class ($root, 'test', false, $directory) extends AbstractKernel {
    public function __construct(string $root, string $environment, bool $debug, private readonly string $directory)
    {
        parent::__construct($root, $environment, $debug);
    }

    public function getCacheDir(): string
    {
        return $this->directory;
    }
};
$container = $kernel->createContainer();
$registry = (new BundleRegistry())->add(new BundleMetadata('sympress/framework-bundle', 'library', 'framework-bundle', $root, $root . '/composer.json', new SymPressFrameworkBundle()));
$files = $kernel->configureContainer($container->builder(), $container, $registry);
$container->builder()->setParameter('framework.cache', ['pools' => [
    'cache.files' => ['adapter' => 'cache.adapter.filesystem', 'public' => true],
    'cache.sqlite' => ['adapter' => 'cache.adapter.pdo', 'provider' => 'sqlite:' . $directory . '/payload.sqlite', 'public' => true],
    'cache.apcu' => ['adapter' => 'cache.adapter.apcu', 'public' => true],
]]);
$kernel->createRuntimeContainer($container, $registry, $files);
$hits = [];
$apcuTamperMiss = false;
foreach (['cache.app', 'cache.files', 'cache.sqlite', 'cache.apcu'] as $name) {
    $pool = $container->get($name);
    $item = $pool->getItem('upgrade-value');
    $hits[$name] = $item->isHit();
    $pool->save($item->set('current'));
    if ($poison && $name === 'cache.apcu') {
        foreach (apcu_cache_info()['cache_list'] as $entry) {
            $payload = apcu_fetch($entry['info']);
            if (is_string($payload) && str_starts_with($payload, 'sympress-symfony-cache-v1:')) {
                apcu_store($entry['info'], serialize((object) ['untrusted' => true]));
            }
        }
        $apcuTamperMiss = !$pool->getItem('upgrade-value')->isHit();
    }
}
$system = $container->get('cache.system');
$saved = $system->save($system->getItem('compiled')->set(['metadata' => 'current']));
echo json_encode(['hits' => $hits, 'system' => $saved, 'apcu_tamper_miss' => $apcuTamperMiss], JSON_THROW_ON_ERROR);
