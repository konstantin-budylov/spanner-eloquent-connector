<?php

declare(strict_types=1);

namespace KonstantinBudylov\EloquentSpanner\Casts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
/**
 * Add to Model: protected $casts = [ 'json_column_name' => SpannerJsonObjectCast::class, ];
 */
class SpannerJsonObjectCast
{
    private const TEMPLATE_DB_UPDATE = "JSON '###JSON###'";
    private const TEMPLATE_DECODE_REGEXP = "~^JSON '(.*)'$~";

    /**
     * @warning json to array allowed only!
     *
     * @param array<mixed> $attributes
     *
     * @return ?array<mixed>
     * @throws \JsonException
     */
    public function get(Model $model, string $key, ?string $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        if (preg_match(self::TEMPLATE_DECODE_REGEXP, $value, $match)) {
            $value = $match[1];
        }

        return json_decode(
            $value,
            true,
            64,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     *
     * @param ?array<mixed> $value
     * @param array<mixed> $attributes
     *
     * @return Expression[]
     */
    public function set(
        Model $model,
        string $key,
        ?array $value,
        array $attributes
    ): array {
        $expr = null;
        if ($value !== null) {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR + JSON_FORCE_OBJECT + JSON_HEX_APOS + JSON_HEX_QUOT
            );

            $expr = new Expression(
                str_replace(
                    '###JSON###',
                    $json,
                    self::TEMPLATE_DB_UPDATE
                )
            );
        }

        return [
            $key => $expr,
        ];
    }
}
