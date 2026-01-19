<?php

namespace KonstantinBudylov\EloquentSpanner;

use Illuminate\Queue\QueueServiceProvider as IlluminateQueueServiceProvider;

/**
 * Support for binary uuids.
 */
class QueueServiceProvider extends IlluminateQueueServiceProvider
{
    /**
     * Create a new database failed job provider.
     *
     * @param array<mixed> $config
     * @return \Illuminate\Queue\Failed\DatabaseFailedJobProvider
     */
    protected function databaseFailedJobProvider($config)
    {
        return new DatabaseFailedJobProvider(
            $this->app['db'],
            $config['database'],
            $config['table']
        );
    }
}
