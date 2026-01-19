<?php

namespace KonstantinBudylov\EloquentSpanner;

use Google\Cloud\Core\Lock\SymfonyLockAdapter;

/**
 * Lock adapter with tracing.
 */
class SessionLock extends SymfonyLockAdapter
{
    /**
     * Execute a callable within a lock. If an exception is caught during
     * execution of the callable the lock will first be released before throwing
     * it.
     *
     * @param callable $func The callable to execute.
     * @param array<mixed> $options [optional] {
     *     Configuration options.
     *
     *     @type bool $blocking Whether the process should block while waiting
     *           to acquire the lock. **Defaults to** true.
     * }
     * @return mixed
     */
    public function synchronize(callable $func, array $options = [])
    {
        return traceInSpan("spanner-session-sync", function () use ($func, $options) {
            return parent::synchronize($func, $options);
        });
    }
}
