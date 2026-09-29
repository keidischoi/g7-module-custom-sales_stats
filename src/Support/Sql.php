<?php

namespace Modules\Custom\SalesStats\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * DB 드라이버별 SQL 조각 (MySQL/MariaDB · PostgreSQL · SQLite)
 *
 * 컬럼은 항상 wrap() 으로 감싸 테이블 접두사(prefix)가 붙은 별칭까지 올바르게 씁니다.
 */
final class Sql
{
    public static function wrap(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }

    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * DB 시각 컬럼 → 관리자 시간대 기준 버킷 키 식 (day=Y-m-d, week=월요일 Y-m-d, month=Y-m, year=Y)
     *
     * @param  string  $expr  이미 wrap 된 컬럼/식
     */
    public static function bucket(string $expr, string $granularity, int $offsetSeconds): string
    {
        $offset = (int) $offsetSeconds;

        return match (self::driver()) {
            'sqlite' => self::sqliteBucket("datetime({$expr}, '".($offset >= 0 ? '+' : '')."{$offset} seconds')", $granularity),
            'pgsql' => self::pgsqlBucket("({$expr} + interval '{$offset} seconds')", $granularity),
            default => self::mysqlBucket("DATE_ADD({$expr}, INTERVAL {$offset} SECOND)", $granularity),
        };
    }

    /**
     * COALESCE(a, b, ...) (wrap 포함)
     *
     * @param  array<int, string>  $columns
     */
    public static function coalesce(array $columns): string
    {
        $wrapped = array_map(fn ($c) => self::wrap($c), $columns);

        return count($wrapped) === 1 ? $wrapped[0] : 'COALESCE('.implode(', ', $wrapped).')';
    }

    /**
     * 기간 조건 (UTC 범위, 양 끝 포함)
     */
    public static function whereBetweenRaw(Builder $query, string $expr, PeriodRange $range): Builder
    {
        return $query->whereRaw("{$expr} >= ?", [$range->utcStart()])
            ->whereRaw("{$expr} <= ?", [$range->utcEnd()]);
    }

    private static function mysqlBucket(string $local, string $granularity): string
    {
        return match ($granularity) {
            'week' => "DATE_FORMAT(DATE_SUB({$local}, INTERVAL WEEKDAY({$local}) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT({$local}, '%Y-%m')",
            'year' => "DATE_FORMAT({$local}, '%Y')",
            default => "DATE_FORMAT({$local}, '%Y-%m-%d')",
        };
    }

    private static function sqliteBucket(string $local, string $granularity): string
    {
        return match ($granularity) {
            'week' => "date({$local}, '-6 days', 'weekday 1')",
            'month' => "strftime('%Y-%m', {$local})",
            'year' => "strftime('%Y', {$local})",
            default => "strftime('%Y-%m-%d', {$local})",
        };
    }

    private static function pgsqlBucket(string $local, string $granularity): string
    {
        return match ($granularity) {
            'week' => "to_char(date_trunc('week', {$local}), 'YYYY-MM-DD')",
            'month' => "to_char({$local}, 'YYYY-MM')",
            'year' => "to_char({$local}, 'YYYY')",
            default => "to_char({$local}, 'YYYY-MM-DD')",
        };
    }
}
