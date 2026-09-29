<?php

namespace Modules\Custom\SalesStats\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\Custom\SalesStats\Support\Filters;
use Modules\Custom\SalesStats\Support\PeriodRange;
use Modules\Custom\SalesStats\Support\Stats;

/**
 * 통계 서비스 공통 기능
 */
trait Concerns
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

    /** 짧은 캐시 (초). 테스트/개발은 0 으로 끌 수 있음: config('custom-sales_stats.cache_ttl') */
    protected function remember(string $key, \Closure $callback): mixed
    {
        $ttl = (int) config('custom-sales_stats.cache_ttl', 60);
        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember($key, $ttl, $callback);
    }

    protected function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;
        if (! array_key_exists($key, self::$columnCache)) {
            try {
                self::$columnCache[$key] = Schema::hasColumn($table, $column);
            } catch (\Throwable) {
                self::$columnCache[$key] = false;
            }
        }

        return self::$columnCache[$key];
    }

    public static function flushColumnCache(): void
    {
        self::$columnCache = [];
    }

    protected function hasTable(string $table): bool
    {
        $key = $table.'.*';
        if (! array_key_exists($key, self::$columnCache)) {
            try {
                self::$columnCache[$key] = Schema::hasTable($table);
            } catch (\Throwable) {
                self::$columnCache[$key] = false;
            }
        }

        return self::$columnCache[$key];
    }

    /**
     * KPI 한 칸 (현재 값 · 이전 값 · 증감률)
     *
     * @return array<string, mixed>
     */
    protected function kpi(float|int $value, float|int|null $previous, ?callable $formatter = null, bool $lowerIsBetter = false): array
    {
        $change = $previous === null ? null : Stats::change($value, $previous);
        $trend = Stats::trend($change);
        if ($lowerIsBetter && $trend !== 'neutral') {
            $trend = $trend === 'up' ? 'down' : 'up';
        }

        return [
            'value' => is_float($value) ? round($value, 2) : $value,
            'formatted' => $formatter ? $formatter($value) : number_format((float) $value, is_float($value) && floor($value) != $value ? 1 : 0),
            'previous' => $previous,
            'previous_formatted' => $previous === null ? null : ($formatter ? $formatter($previous) : number_format((float) $previous)),
            'change' => $change,
            'trend' => $trend,
        ];
    }

    /**
     * 버킷 키 → 값 맵을 빈 기간까지 채운 시계열로
     *
     * @param  array<string, array<string, float|int>>  $byKey
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    protected function fillSeries(PeriodRange $range, array $byKey, array $fields): array
    {
        $rows = [];
        foreach ($range->bucketKeys() as $key) {
            $row = ['period' => $key, 'label' => $this->bucketLabel($key, $range->granularity)];
            foreach ($fields as $field) {
                $row[$field] = $byKey[$key][$field] ?? 0;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    protected function bucketLabel(string $key, string $granularity): string
    {
        return match ($granularity) {
            'day' => substr($key, 5),
            'week' => substr($key, 5).'~',
            default => $key,
        };
    }

    /** DB(UTC) 시각 → 관리자 시간대 문자열 */
    protected function localTime(?string $value, Filters $filters, string $format = 'Y-m-d H:i'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, PeriodRange::dbTimezone())
                ->setTimezone($filters->range->timezone)->format($format);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 순위·점유율·색상 붙이기
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function rank(array $rows, string $amountField, float|int|null $total = null, int $offset = 0): array
    {
        $total ??= array_sum(array_map(fn ($r) => (float) ($r[$amountField] ?? 0), $rows));
        $max = 0.0;
        foreach ($rows as $r) {
            $max = max($max, (float) ($r[$amountField] ?? 0));
        }
        $palette = Stats::palette();
        foreach ($rows as $i => &$r) {
            $r['rank'] = $offset + $i + 1;
            $r['share'] = Stats::ratio($r[$amountField] ?? 0, $total);
            $r['bar'] = $max > 0 ? round((float) ($r[$amountField] ?? 0) / $max * 100, 1) : 0;
            $r['color'] = $palette[($offset + $i) % count($palette)];
            // 차트(DonutChart) 바인딩용 공통 키
            $r['name'] ??= (string) ($r['label'] ?? '');
            $r['value'] ??= $r[$amountField] ?? 0;
        }

        return $rows;
    }
}
