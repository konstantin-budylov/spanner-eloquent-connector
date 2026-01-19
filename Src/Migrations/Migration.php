<?php

namespace KonstantinBudylov\EloquentSpanner\Migrations;

use KonstantinBudylov\EloquentSpanner\Connection;
use KonstantinBudylov\EloquentSpanner\Schema\BaseSpannerSchemaBuilder;
use Carbon\Carbon;
use Google\Cloud\Spanner\Bytes;
use Illuminate\Database\Migrations\Migration as AbstractMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

abstract class Migration extends AbstractMigration
{
    protected const DATA_OWNER_ID = '37e7d8b7-a33d-4d51-8502-52572717f74b';
    protected const API_USER_NAME = 'SXOPE Sphere API';

    private Connection $db;
    private BaseSpannerSchemaBuilder $schema;
    private Carbon $now;
    private Bytes $dayId;
    private Bytes $dataOwnerId;

    public function __construct()
    {
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->db = DB::connection('sxope-spanner');
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->schema = Schema::connection('sxope-spanner');

        [$this->now, $this->dayId] = $this->db->getNow();

        $this->dataOwnerId = $this->createId(self::DATA_OWNER_ID);
    }

    protected function getCrud(): array
    {
        return [
            'created_at' => $this->getNow(),
            'updated_at' => $this->getNow(),
            'created_at_day_id' => $this->getDayId(),
            'updated_at_day_id' => $this->getDayId(),
            'created_by_sxope_username_plaintext' => self::API_USER_NAME,
            'updated_by_sxope_username_plaintext' => self::API_USER_NAME,
        ];
    }

    /**
     * @param string $uuid
     * @return Bytes
     */
    protected function createId(?string $uuid = null): Bytes
    {
        $uuid = $uuid === null ? Uuid::uuid4() : Uuid::fromString($uuid);
        return new Bytes($uuid->getBytes());
    }

    /**
     * @return Connection|\Illuminate\Database\ConnectionInterface
     */
    protected function getDb()
    {
        return $this->db;
    }

    /**
     * @return BaseSpannerSchemaBuilder|\Illuminate\Database\Schema\Builder
     */
    protected function getSchema()
    {
        return $this->schema;
    }

    /**
     * @return Carbon|mixed
     */
    protected function getNow()
    {
        return $this->now;
    }

    /**
     * @return Bytes|mixed
     */
    protected function getDayId()
    {
        return $this->dayId;
    }

    /**
     * @return Bytes
     */
    protected function getDataOwnerId(): Bytes
    {
        return $this->dataOwnerId;
    }
}
