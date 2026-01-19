<?php

namespace KonstantinBudylov\EloquentSpanner;

use KonstantinBudylov\EloquentSpanner\Connection;
use KonstantinBudylov\EloquentSpanner\SessionLock;
use KonstantinBudylov\EloquentSpanner\Debugbar\SpannerLaravelDebugbar;
use Barryvdh\Debugbar\LaravelDebugbar;
use Barryvdh\Debugbar\SymfonyHttpDriver;
use Colopl\Spanner\SpannerServiceProvider;
use Google\Cloud\Spanner\Session\CacheSessionPool;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

/**
 * Registers 'base-spanner' driver
 * Name of connection in 'databases.php' should not be name of any other driver to work
 */
class ServiceProvider extends SpannerServiceProvider
{
    protected string $authHash;
    protected string $sessionPoolDriver;

    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->resolving('db', function (DatabaseManager $db) {
            $db->extend('sxope-spanner', function ($config, $name) {
                // @phpstan-ignore-next-line
                return $this->createSpannerConnection($this->parseConfig($config, $name));
            });
        });

        // debugbar with support of BYTES()
        $this->app->singleton(LaravelDebugbar::class, function () {
            $debugbar = new SpannerLaravelDebugbar($this->app);

            if ($this->app->bound(SessionManager::class)) {
                $sessionManager = $this->app->make(SessionManager::class);
                $httpDriver = new SymfonyHttpDriver($sessionManager);
                $debugbar->setHttpDriver($httpDriver);
            }

            return $debugbar;
        });
    }

    /**
     * @param array{
     *      name: string,
     *      instance: string,
     *      database: string,
     *      prefix: string,
     *      cache_path: string|null,
     *      session_pool: array<string, mixed>,
     *      sessionPoolDriver: string|null,
     *      client: array{projectId: string|null},
     * } $config
     * @phpstan-ignore-next-line
     */
    protected function createSpannerConnection(array $config): Connection
    {
        if (
            config('session.driver') != 'redis'
            || (!config('app.enable_gke_workload_identity') && !is_readable(config('app.google_application_credentials')))
            || !extension_loaded('redis')
        ) {
            // if no credentials (artisan run outside k8) then disable redis provider
            $this->sessionPoolDriver = 'file';
        } else {
            $this->sessionPoolDriver = $config['sessionPoolDriver'] ?? 'file';
        }

        $this->authHash = $this->getAuthHash("{$config['client']['projectId']}.{$config['instance']}.{$config['database']}");
        return new Connection(
            $config['instance'],
            $config['database'],
            $config['prefix'],
            $config,
            $this->createAuthCache($config),
            $this->createSessionPool($config),
        );
    }

    /**
     * Hashes credentials file
     */
    protected function getAuthHash(string $database): string
    {
        if (is_readable(config('app.google_application_credentials'))) {
            return $database . '.' . sha1_file(config('app.google_application_credentials'));
        } else {
            return $database;
        }
    }

    /**
     * @param array{ name:string, cache_path: string|null } $config
     * @return AdapterInterface
     * @phpstan-ignore-next-line
     */
    protected function createAuthCache(array $config): AdapterInterface
    {
        if ($this->sessionPoolDriver != 'redis') {
            return parent::createAuthCache($config);
        }
        return $this->getCacheAdapter("spanner.auth.{$config['name']}", $config['cache_path']);
    }

    /**
     * @param array{ name: string, cache_path: string|null, session_pool: array<string, mixed>|null } $config
     * @return SessionPoolInterface
     */
    protected function createSessionPool(array $config): SessionPoolInterface
    {
        if ($this->sessionPoolDriver != 'redis') {
            return parent::createSessionPool($config);
        }

        $store = new RedisStore(Redis::connection()->client());
        $factory = new LockFactory($store);

        $lock = $factory->createLock("spanner.session.{$config['name']}.{$this->authHash}.lock");
        $lock = new SessionLock($lock);

        return new CacheSessionPool(
            $this->getCacheAdapter("spanner.session.{$config['name']}", $config['cache_path']),
            [ 'lock' => $lock] + ($config['session_pool'] ?? [])
        );
    }

    /**
     * @param string $namespace
     * @param string|null $path
     * @return AdapterInterface
     */
    protected function getCacheAdapter(string $namespace, ?string $path): AdapterInterface
    {
        if ($this->sessionPoolDriver != 'redis') {
            return parent::getCacheAdapter($namespace, $path);
        }
        $cachePrefix = "{$namespace}.{$this->authHash}";
        return new RedisAdapter(Redis::connection()->client(), $cachePrefix);
    }
}
