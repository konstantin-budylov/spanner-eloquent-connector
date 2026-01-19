<?php

namespace KonstantinBudylov\EloquentSpanner\Debugbar;

use KonstantinBudylov\EloquentSpanner\Debugbar\DataCollector\SpannerAuthCollector;
use KonstantinBudylov\EloquentSpanner\Debugbar\DataCollector\SpannerQueryCollector;
use KonstantinBudylov\EloquentSpanner\Debugbar\DataFormatter\SpannerQueryFormatter;
use Barryvdh\Debugbar\LaravelDebugbar;
use DebugBar\DataCollector\TimeDataCollector;
use Exception;

/**
 * Debug bar subclass which adds all without Request and with LaravelCollector.
 *
 * Add support for BYTES(16)
 */
class SpannerLaravelDebugbar extends LaravelDebugbar
{
    /**
     * Boot the debugbar (add collectors, renderer and listener)
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        parent::boot();

        /** @var \Barryvdh\Debugbar\LaravelDebugbar $debugbar */
        $debugbar = $this;

        /** @var \Illuminate\Contracts\Foundation\Application $app */
        $app = $this->app;

        if ($this->shouldCollect('spanner-db', true) && isset($this->app['db'])) {
            $db = $this->app['db'];
            if (
                $debugbar->hasCollector('time') && $this->app['config']->get(
                    'debugbar.options.spanner-db.timeline',
                    false
                )
            ) {
                $timeCollector = $debugbar->getCollector('time');
                assert($timeCollector instanceof TimeDataCollector);
            } else {
                $timeCollector = null;
            }
            $queryCollector = new SpannerQueryCollector($timeCollector);

            $queryCollector->setDataFormatter(new SpannerQueryFormatter());
            $queryCollector->setLimits($this->app['config']->get('debugbar.options.spanner-db.soft_limit'), $this->app['config']->get('debugbar.options.spanner-db.hard_limit'));
            if ($this->app['config']->get('debugbar.options.spanner-db.with_params')) {
                $queryCollector->setRenderSqlWithParams(true);
            }

            if ($this->app['config']->get('debugbar.options.spanner-db.backtrace')) {
                $middleware = ! $this->is_lumen ? $this->app['router']->getMiddleware() : [];
                $queryCollector->setFindSource(true, $middleware);
            }

            if ($this->app['config']->get('debugbar.options.spanner-db.backtrace_exclude_paths')) {
                $excludePaths = $this->app['config']->get('debugbar.options.spanner-db.backtrace_exclude_paths');
                $queryCollector->mergeBacktraceExcludePaths($excludePaths);
            }

            $queryCollector->setDurationBackground($this->app['config']->get('debugbar.options.spanner-db.duration_background'));

            if ($this->app['config']->get('debugbar.options.spanner-db.explain.enabled')) {
                $types = $this->app['config']->get('debugbar.options.spanner-db.explain.types');
                $queryCollector->setExplainSource(true, $types);
            }

            if ($this->app['config']->get('debugbar.options.spanner-db.hints', true)) {
                $queryCollector->setShowHints(true);
            }

            if ($this->app['config']->get('debugbar.options.spanner-db.show_copy', false)) {
                $queryCollector->setShowCopyButton(true);
            }

            $this->addCollector($queryCollector);

            try {
                $db->listen(
                    function (\Illuminate\Database\Events\QueryExecuted $query) {
                        if (!$this->shouldCollect('spanner-db', true)) {
                            return; // Issue 776 : We've turned off collecting after the listener was attached
                        }

                        //allow collecting only queries slower than a specified amount of milliseconds
                        $threshold = $this->app['config']->get('debugbar.options.spanner-db.slow_threshold', false);
                        if (!$threshold || $query->time > $threshold) {
                            $this['queries']->addQuery($query);
                        }
                    }
                );
            } catch (\Exception $e) {
                $this->addThrowable(
                    new Exception(
                        'Cannot add listen to Queries for Laravel Debugbar: ' . $e->getMessage(),
                        $e->getCode(),
                        $e
                    )
                );
            }

            try {
                $db->getEventDispatcher()->listen(
                    \Illuminate\Database\Events\TransactionBeginning::class,
                    function ($transaction) use ($queryCollector) {
                        $queryCollector->collectTransactionEvent('Begin Transaction', $transaction->connection);
                    }
                );

                $db->getEventDispatcher()->listen(
                    \Illuminate\Database\Events\TransactionCommitted::class,
                    function ($transaction) use ($queryCollector) {
                        $queryCollector->collectTransactionEvent('Commit Transaction', $transaction->connection);
                    }
                );

                $db->getEventDispatcher()->listen(
                    \Illuminate\Database\Events\TransactionRolledBack::class,
                    function ($transaction) use ($queryCollector) {
                        $queryCollector->collectTransactionEvent('Rollback Transaction', $transaction->connection);
                    }
                );

                $db->getEventDispatcher()->listen(
                    'connection.*.beganTransaction',
                    function ($event, $params) use ($queryCollector) {
                        $queryCollector->collectTransactionEvent('Begin Transaction', $params[0]);
                    }
                );

                $db->getEventDispatcher()->listen(
                    'connection.*.committed',
                    function ($event, $params) use ($queryCollector) {
                        $queryCollector->collectTransactionEvent('Commit Transaction', $params[0]);
                    }
                );

                $db->getEventDispatcher()->listen(
                    'connection.*.rollingBack',
                    function ($event, $params) use ($queryCollector) {
                        $queryCollector->collectTransactionEvent('Rollback Transaction', $params[0]);
                    }
                );

                $db->getEventDispatcher()->listen(
                    function (\Illuminate\Database\Events\ConnectionEstablished $event) use ($queryCollector) {
                        $queryCollector->collectTransactionEvent('Connection Established', $event->connection);

                        if (app('config')->get('debugbar.options.db.memory_usage')) {
                            $event->connection->beforeExecuting(function () use ($queryCollector) {
                                $queryCollector->startMemoryUsage();
                            });
                        }
                    }
                );
            } catch (\Exception $e) {
                $this->addThrowable(
                    new Exception(
                        'Cannot add listen transactions to Queries for Laravel Debugbar: ' . $e->getMessage(),
                        $e->getCode(),
                        $e
                    )
                );
            }
        }

        if ($this->shouldCollect('spanner-auth', false)) {
            try {
                $guards = $this->app['config']->get('auth.guards', []);
                $authCollector = new SpannerAuthCollector($app['auth'], $guards);

                $authCollector->setShowName(
                    $this->app['config']->get('debugbar.options.spanner-auth.show_name')
                );
                $this->addCollector($authCollector);
            } catch (\Exception $e) {
                $this->addThrowable(
                    new Exception(
                        'Cannot add AuthCollector to Laravel Debugbar: ' . $e->getMessage(),
                        $e->getCode(),
                        $e
                    )
                );
            }
        }
    }
}
