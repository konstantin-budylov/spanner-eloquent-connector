<?php

namespace KonstantinBudylov\EloquentSpanner;

use KonstantinBudylov\Services\BaseSpanner\Query\SpannerQueryBuilder;
use KonstantinBudylov\Services\BaseSpanner\Query\SpannerQueryGrammar;
use KonstantinBudylov\Services\BaseSpanner\Schema\BaseSpannerSchemaBuilder;
use KonstantinBudylov\Services\BaseSpanner\Schema\BaseSpannerSchemaGrammar;
use KonstantinBudylov\Services\BaseSpanner\SpannerBinaryUuid;
use Barryvdh\Debugbar\Facades\Debugbar;
use Carbon\Carbon;
use Closure;
use Colopl\Spanner\Connection as BaseConnection;
use Google\ApiCore\ApiException;
use Google\Cloud\Core\LongRunning\LongRunningOperation;
use Google\Cloud\Spanner\Bytes;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Session\SessionPoolInterface;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Str;
use Psr\Cache\CacheItemPoolInterface;
use Ramsey\Uuid\Uuid;
use Redis;
use RedisException;

/**
 * Extended support of spanner + tracing
 */
class Connection extends BaseConnection
{
    /**
     * @var array<string, EmulatorLockData>
     */
    protected static $emulatorLocks = [];

    public const MUTATIONS_LIMIT = 5000;

    /**
     * Create a new spanner database connection instance.
     *
     * @param string $instanceId instance ID
     * @param string $databaseName
     * @param string $tablePrefix
     * @param array<mixed> $config
     * @param CacheItemPoolInterface $authCache
     * @param SessionPoolInterface $sessionPool
     */
    public function __construct(
        string $instanceId,
        string $databaseName,
        $tablePrefix = '',
        array $config = [],
        CacheItemPoolInterface $authCache = null,
        SessionPoolInterface $sessionPool = null,
    ) {
        parent::__construct($instanceId, $databaseName, $tablePrefix, $config, $authCache, $sessionPool);
    }

    protected function getEmulatorHost(): ?string
    {
        return $this->config['client']['emulatorHost'] ?? getenv('SPANNER_EMULATOR_HOST');
    }

    protected function hasEmulator(): bool
    {
        return $this->config['client']['hasEmulator'] ?? false;
    }

    /**
     * Tries to acquire emulator lock
     */
    protected function emulatorAcquireLock(): void
    {
        // ignore nested transactions or real spanner
        if ($this->transactions > 0 || !$this->hasEmulator()) {
            return;
        }

        $emulatorHost = (string) $this->getEmulatorHost();
        if (!isset(self::$emulatorLocks[$emulatorHost])) {
            self::$emulatorLocks[$emulatorHost] = new EmulatorLockData(
                lock: null,
                redisLockConnection:  null,
                locksCount: 0,
            );
        }
        $lockConfig = &self::$emulatorLocks[$emulatorHost];
        $lockConfig->locksCount++;
        // already locked
        if ($lockConfig->locksCount > 1) {
            return;
        }

        try {
            $spannerEmulatorLockConfig = config('database.spannerEmulatorLock');
            $spannerEmulatorLockDriver = $spannerEmulatorLockConfig['driver'] ?? 'redis';
            if ($spannerEmulatorLockDriver == 'none') {
                return;
            }
            $spannerEmulatorLockTime = $spannerEmulatorLockConfig['lockTime'] ?? 600;
            $spannerEmulatorLockWait = $spannerEmulatorLockConfig['lockWaitTime'] ?? 1200;

            if (is_null($lockConfig->lock)) {
                if ($spannerEmulatorLockDriver == 'redis') {
                    $redis = app('redis');
                    $store = new RedisStore($redis, '', 'default');
                    $lockConnection = $store->lockConnection();
                    $lockConfig->redisLockConnection = $lockConnection instanceof PhpRedisConnection ? $lockConnection : null;
                } else {
                    $path = storage_path('framework/cache/data');
                    $store = (new FileStore(app('files'), $path))->setLockDirectory($path);
                }
                $lockConfig->lock = $store->lock(
                    'spanner_emulator_transaction_lock_' . ($this->config['client']['emulatorHost'] ?? ''),
                    $spannerEmulatorLockTime,
                );
            }

            $saveRedisPrefix = $lockConfig->redisLockConnection?->client()->getOption(Redis::OPT_PREFIX);
            $measure = 'spanner-emulator-lock';

            try {
                Debugbar::startMeasure($measure, 'Spanner Emulator Lock');

                // reset redis global prefix to share the lock among all local projects
                $lockConfig->redisLockConnection?->client()->setOption(Redis::OPT_PREFIX, '');

                $lockConfig->lock->block($spannerEmulatorLockWait);
            } finally {
                // restore redis global prefix so Cache works properly
                $lockConfig->redisLockConnection?->client()->setOption(Redis::OPT_PREFIX, $saveRedisPrefix);

                Debugbar::stopMeasure($measure);
            }
        } catch (RedisException $e) {
            // error with redis, ignore
            return;
        }
    }

    /**
     * Releases emulator lock
     */
    protected function emulatorReleaseLock(): void
    {
        // skip when there multiple transactions or real spanner
        if ($this->transactions != 0 || !$this->hasEmulator()) {
            return;
        }

        $emulatorHost = (string) $this->getEmulatorHost();
        // skip when no locks at all
        if (!isset(self::$emulatorLocks[$emulatorHost])) {
            return;
        }
        $lockConfig = &self::$emulatorLocks[$emulatorHost];
        // skip when lock already released
        if ($lockConfig->locksCount == 0) {
            return;
        }

        $lockConfig->locksCount--;
        // skip when not locked or there are many re-entries into locks
        if ($lockConfig->locksCount > 0 || !$lockConfig->lock) {
            return;
        }

        $saveRedisPrefix = $lockConfig->redisLockConnection?->client()->getOption(Redis::OPT_PREFIX);
        try {
            // reset redis global prefix to share the lock among all local projects
            $lockConfig->redisLockConnection?->client()->setOption(Redis::OPT_PREFIX, '');

            $lockConfig->lock->release();
        } catch (RedisException $e) {
            // error with redis, ignore
            return;
        } finally {
            // restore redis global prefix so Cache works properly
            $lockConfig->redisLockConnection?->client()->setOption(Redis::OPT_PREFIX, $saveRedisPrefix);
        }
    }

    /**
     * Run callback under emulator lock
     *
     * @template T
     * @param  Closure(): T $callback
     * @return T
     */
    protected function emulatorLockBlock(Closure $callback)
    {
        try {
            $this->emulatorAcquireLock();
            return $callback();
        } finally {
            $this->emulatorReleaseLock();
        }
    }

    /**
     * @template T
     * @param  Closure(static): T $callback
     * @param  int $attempts
     * @return T
     */
    public function transaction(Closure $callback, $attempts = Database::MAX_RETRIES)
    {
        return $this->emulatorLockBlock(function () use ($callback, $attempts) {
            return traceInSpan('sql-transaction', function () use ($callback, $attempts) {
                return parent::transaction($callback, $attempts);
            });
        });
    }

    /**
     * @inheritDoc
     */
    public function runPartitionedDml($query, $bindings = [])
    {
        return $this->emulatorLockBlock(function () use ($query, $bindings) {
            return parent::runPartitionedDml($query, $bindings);
        });
    }

    /**
     * @inheritDoc
     */
    protected function createTransaction()
    {
        $this->emulatorAcquireLock();
        parent::createTransaction();
    }

    /**
     * @inheritDoc
     */
    public function commit()
    {
        parent::commit();
        $this->emulatorReleaseLock();
    }

    /**
     * @inheritDoc
     */
    public function rollBack($toLevel = null)
    {
        try {
            parent::rollBack($toLevel);
        } finally {
            $this->emulatorReleaseLock();
        }
    }

    public function query(): SpannerQueryBuilder
    {
        $queryGrammar = $this->getQueryGrammar();
        assert($queryGrammar instanceof SpannerQueryGrammar);
        return new SpannerQueryBuilder($this, $queryGrammar, $this->getPostProcessor());
    }

    public function getSchemaBuilder(): BaseSpannerSchemaBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new BaseSpannerSchemaBuilder($this);
    }

    protected function getDefaultQueryGrammar(): SpannerQueryGrammar
    {
        return new SpannerQueryGrammar();
    }

    protected function getDefaultSchemaGrammar(): BaseSpannerSchemaGrammar
    {
        return new BaseSpannerSchemaGrammar();
    }

    /**
     * Prepare the query bindings for execution.
     *
     * Supports optional conversion of all uuids into bytes
     *
     * @param  array<mixed>  $bindings
     * @return array<mixed>
     */
    public function prepareBindings(array $bindings)
    {
        $bindings = parent::prepareBindings($bindings);

        $uuidCasts = $this->getConfig('convert_string_uuids_params_to_bytes_in_queries');
        $hexStringUuidCasts = $this->getConfig('convert_hex_string_len_32_params_to_bytes_in_queries');

        if ($uuidCasts || $hexStringUuidCasts) {
            // dynamic replacement of string uuids should be enabled per database
            // replaces all uuids params indiscriminately in case of simple queries, like
            // DB::table('')->where
            // DB::statement('', [])
            foreach ($bindings as $key => $value) {
                if (is_string($value)) {
                    $strLen = strlen($value);
                    if ($uuidCasts && $strLen === 36 && Str::isUuid($value)) {
                        $bindings[$key] = new SpannerBinaryUuid($value);
                    }

                    // process uuids without dashes
                    if ($hexStringUuidCasts && $strLen === 32 && preg_match('/^[\da-f]{32}$/iD', $value) > 0) {
                        $bindings[$key] = new SpannerBinaryUuid(Uuid::fromString($value)->toString());
                    }
                }
            }
        }

        return $bindings;
    }

    /**
     * Execute a Closure with modified config flag.
     */
    protected function executeClosureWithModifiedConfig(Closure $callback, string $flagName, bool $flagValue): mixed
    {
        $savedFlagValue = $this->getConfig($flagName) == true;
        try {
            $this->config[$flagName] = $flagValue;
            return $callback();
        } finally {
            $this->config[$flagName] = $savedFlagValue;
        }
    }

    /**
     * Execute a Closure without replacement of string uuids to binary uuids.
     */
    public function withoutUuidCasts(Closure $callback): mixed
    {
        return $this->executeClosureWithModifiedConfig(
            $callback,
            'convert_string_uuids_params_to_bytes_in_queries',
            false,
        );
    }

    /**
     * Execute a Closure with replacement of string uuids to binary uuids.
     */
    public function withUuidCasts(Closure $callback): mixed
    {
        return $this->executeClosureWithModifiedConfig(
            $callback,
            'convert_string_uuids_params_to_bytes_in_queries',
            true,
        );
    }

    /**
     * Execute a Closure without replacement of string uuids without dashes (hex string with len 32) to binary uuids.
     */
    public function withoutHexStringUuidCasts(Closure $callback): mixed
    {
        return $this->executeClosureWithModifiedConfig(
            $callback,
            'convert_hex_string_len_32_params_to_bytes_in_queries',
            false,
        );
    }

    /**
     * Execute a Closure with replacement of string uuids without dashes (hex string with len 32) to binary uuids.
     */
    public function withHexStringUuidCasts(Closure $callback): mixed
    {
        return $this->executeClosureWithModifiedConfig(
            $callback,
            'convert_hex_string_len_32_params_to_bytes_in_queries',
            true,
        );
    }

    /**
     * Execute a Closure without any replacement of string uuids to binary uuids.
     */
    public function withoutAnyUuidCasts(Closure $callback): mixed
    {
        return $this->withoutUuidCasts(
            function () use ($callback) {
                return $this->withoutHexStringUuidCasts($callback);
            }
        );
    }

    /**
     * Execute a Closure with replacement of string uuids to binary uuids.
     */
    public function withAnyUuidCasts(Closure $callback): mixed
    {
        return $this->withUuidCasts(
            function () use ($callback) {
                return $this->withHexStringUuidCasts($callback);
            }
        );
    }

    public function waitForOperation(LongRunningOperation $operation): mixed
    {
        $tryCount = 0;
        while ($tryCount <= 10) {
            try {
                return parent::waitForOperation($operation);
            } catch (ApiException $e) {
                if ($tryCount < 10 && $e->getCode() == \Google\Rpc\Code::DEADLINE_EXCEEDED) {
                    $tryCount++;
                } else {
                    throw($e);
                }
            }
        }
        return null;
    }

    /**
     * This method is used on EVERY save to the database, especially with DDD-models
     *
     * @fixme remove
     * @return array{0: Carbon, 1: Bytes}
     */
    public function getNow(): array
    {
        static $cache = [];

        $now = Carbon::now();
        $nowStr = $now->format('Y-m-d');

        if (isset($cache[$nowStr])) {
            return [$now, $cache[$nowStr]];
        }

        $cache[$nowStr] = $this
            ->table('days')
            ->where('day_date', $nowStr)
            ->value('day_id');

        return [$now, $cache[$nowStr]];
    }
}
