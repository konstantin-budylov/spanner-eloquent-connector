<?php

namespace KonstantinBudylov\EloquentSpanner;

use KonstantinBudylov\Services\BaseSpanner\SpannerBinaryUuid;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\UpdatedBatchJobCounts;

class DatabaseBatchRepository extends DatabaseBatchRepository
{
    /**
     * Retrieve a list of batches.
     *
     * @param  int  $limit
     * @param  mixed  $before
     * @return \Illuminate\Bus\Batch[]
     */
    public function get($limit = 50, $before = null)
    {
        $beforeCreatedAt = 0;
        if ($before) {
            $beforeCreatedAt = $this->connection->table($this->table)
                                ->where('id', $before)
                                ->first()
                                ?->createdAt
                                ;
        }
        return $this->connection->table($this->table)
                            ->orderByDesc('created_at')
                            ->take($limit)
                            ->when($beforeCreatedAt, fn ($q) => $q->where('created_at', '<', $beforeCreatedAt))
                            ->get()
                            ->map(function ($batch) {
                                return $this->toBatch($batch);
                            })
                            ->all();
    }

    /**
     * Store a new pending batch.
     *
     * @param  \Illuminate\Bus\PendingBatch  $batch
     * @return \Illuminate\Bus\Batch
     */
    public function store(PendingBatch $batch)
    {
        $id = SpannerBinaryUuid::randomUuidBytes();

        $this->connection->table($this->table)->insert([
            'id' => $id,
            'name' => $batch->name,
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => $this->serialize($batch->options),
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ]);

        return $this->find($id);
    }

    /**
     * Convert the given raw batch to a Batch object.
     *
     * @param  object  $batch
     * @return \Illuminate\Bus\Batch
     */
    protected function toBatch($batch)
    {
        $batch = $this->factory->make(
            $this,
            new SpannerBinaryUuid(base64_decode($batch['id'])),
            $batch['name'],
            (int) $batch['total_jobs'],
            (int) $batch['pending_jobs'],
            (int) $batch['failed_jobs'],
            (array) json_decode($batch['failed_job_ids'], true),
            $this->unserialize($batch['options']),
            CarbonImmutable::createFromTimestamp($batch['created_at']),
            $batch['cancelled_at'] ? CarbonImmutable::createFromTimestamp($batch['cancelled_at']) : $batch['cancelled_at'],
            $batch['finished_at'] ? CarbonImmutable::createFromTimestamp($batch['finished_at']) : $batch['finished_at'],
        );
        return $batch;
    }

    /**
     * Decrement the total number of pending jobs for the batch.
     *
     * @param  string  $batchId
     * @param  string  $jobId
     * @return \Illuminate\Bus\UpdatedBatchJobCounts
     */
    public function decrementPendingJobs(string $batchId, string $jobId)
    {
        $values = $this->updateAtomicValues($batchId, function ($batch) use ($jobId) {
            return [
                'pending_jobs' => $batch['pending_jobs'] - 1,
                'failed_jobs' => $batch['failed_jobs'],
                'failed_job_ids' => json_encode(array_values(array_diff((array) json_decode($batch['failed_job_ids'], true), [$jobId]))),
            ];
        });

        return new UpdatedBatchJobCounts(
            $values['pending_jobs'],
            $values['failed_jobs']
        );
    }

    /**
     * Increment the total number of failed jobs for the batch.
     *
     * @param  string  $batchId
     * @param  string  $jobId
     * @return \Illuminate\Bus\UpdatedBatchJobCounts
     */
    public function incrementFailedJobs(string $batchId, string $jobId)
    {
        $values = $this->updateAtomicValues($batchId, function ($batch) use ($jobId) {
            return [
                'pending_jobs' => $batch['pending_jobs'],
                'failed_jobs' => $batch['failed_jobs'] + 1,
                'failed_job_ids' => json_encode(array_values(array_unique(array_merge((array) json_decode($batch['failed_job_ids'], true), [$jobId])))),
            ];
        });

        return new UpdatedBatchJobCounts(
            $values['pending_jobs'],
            $values['failed_jobs']
        );
    }


    /**
     * Update an atomic value within the batch.
     *
     * @param  string  $batchId
     * @param  \Closure  $callback
     * @return int|array<mixed>|null
     */
    protected function updateAtomicValues(string $batchId, Closure $callback)
    {
        return $this->connection->transaction(function () use ($batchId, $callback) {
            $batch = $this->connection->table($this->table)->where('id', $batchId)
                        ->first();

            return is_null($batch) ? [] : tap($callback($batch), function ($values) use ($batchId) {
                $this->connection->table($this->table)->where('id', $batchId)->update($values);
            });
        });
    }
}
