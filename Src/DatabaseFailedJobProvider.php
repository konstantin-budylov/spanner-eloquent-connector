<?php

namespace KonstantinBudylov\EloquentSpanner;

use Illuminate\Queue\Failed\DatabaseFailedJobProvider as IlluminateDatabaseFailedJobProvider;
use Illuminate\Support\Facades\Date;
use Ramsey\Uuid\Uuid;

/**
 * Support for binary uuids.
 */
class DatabaseFailedJobProvider extends IlluminateDatabaseFailedJobProvider
{
    /**
     * Log a failed job into storage.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  \Exception  $exception
     * @return int|null
     */
    public function log($connection, $queue, $payload, $exception)
    {
        $failed_at = Date::now();

        $exception = (string) $exception;

        $id = Uuid::uuid4()->toString();

        $this->getTable()->insert(compact(
            'id',
            'connection',
            'queue',
            'payload',
            'exception',
            'failed_at'
        ));

        return null;
    }

    /**
     * Get a list of all of the failed jobs.
     *
     * @return array<mixed>
     */
    public function all()
    {
        return $this->getTable()->orderBy('failed_at', 'desc')->get()->all();
    }
}
