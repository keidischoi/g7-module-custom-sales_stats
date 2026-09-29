<?php

namespace Modules\Custom\SalesStats\Support;

use Carbon\CarbonImmutable;

/**
 * 조회 기간 (관리자 시간대 기준 날짜 → DB(UTC) 범위)
 *
 * - from/to 는 관리자 시간대(기본 Asia/Seoul)의 날짜(Y-m-d)이며 양 끝 날짜를 포함합니다.
 * - DB 는 app.timezone(UTC) 으로 저장된다고 보고, 조회 범위는 UTC 로 바꿔 씁니다.
 * - 버킷 수가 너무 많으면 집계 단위를 자동으로 올립니다 (일 → 주 → 월).
 */
final class PeriodRange
{
    public const GRANULARITIES = ['day', 'week', 'month', 'year'];

    public const MAX_DAYS = 3660;

    private const MAX_BUCKETS = 400;

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone,
        public readonly string $granularity,
        public readonly string $requestedGranularity,
    ) {}

    public static function make(?string $from, ?string $to, ?string $granularity, string $timezone, ?CarbonImmutable $now = null): self
    {
        $timezone = self::safeTimezone($timezone);
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);

        $toDate = self::parseDate($to, $timezone) ?? $now->startOfDay();
        $fromDate = self::parseDate($from, $timezone) ?? $toDate->subDays(29);

        if ($fromDate->greaterThan($toDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }
        if ($fromDate->diffInDays($toDate) > self::MAX_DAYS) {
            $fromDate = $toDate->subDays(self::MAX_DAYS);
        }

        $requested = in_array($granularity, self::GRANULARITIES, true) ? $granularity : 'day';

        return new self(
            $fromDate->startOfDay(),
            $toDate->endOfDay(),
            $timezone,
            self::fitGranularity($requested, $fromDate, $toDate),
            $requested,
        );
    }

    /**
     * 같은 길이의 바로 앞 기간 (비교용)
     */
    public function previous(): self
    {
        $days = $this->days();
        $prevTo = $this->from->subDay();
        $prevFrom = $prevTo->subDays($days - 1);

        return new self($prevFrom->startOfDay(), $prevTo->endOfDay(), $this->timezone, $this->granularity, $this->requestedGranularity);
    }

    /** 포함 일수 */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /** DB 조회용 UTC 시작 (포함) */
    public function utcStart(): string
    {
        return $this->from->setTimezone(self::dbTimezone())->format('Y-m-d H:i:s');
    }

    /** DB 조회용 UTC 끝 (포함) */
    public function utcEnd(): string
    {
        return $this->to->setTimezone(self::dbTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * DB 시각 → 관리자 시각 오프셋(초). 기간 시작 시점 기준 (한국은 서머타임이 없어 고정 +9h).
     */
    public function offsetSeconds(): int
    {
        $db = $this->from->setTimezone(self::dbTimezone());

        return $this->from->getOffset() - $db->getOffset();
    }

    /**
     * 빈 기간까지 채운 버킷 키 목록
     *
     * @return array<int, string>
     */
    public function bucketKeys(): array
    {
        $keys = [];
        $cursor = match ($this->granularity) {
            'week' => $this->from->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $this->from->startOfMonth(),
            'year' => $this->from->startOfYear(),
            default => $this->from->startOfDay(),
        };

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $keys[] = self::keyFor($cursor, $this->granularity);
            $cursor = match ($this->granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                'year' => $cursor->addYear(),
                default => $cursor->addDay(),
            };
        }

        return $keys;
    }

    public static function keyFor(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            'week' => $date->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            'year' => $date->format('Y'),
            default => $date->format('Y-m-d'),
        };
    }

    /**
     * 기간 프리셋 (관리자 시간대 기준 날짜)
     *
     * @return array<int, array{key: string, from: string, to: string, granularity: string}>
     */
    public static function presets(string $timezone, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone(self::safeTimezone($timezone))->startOfDay();
        $d = fn (CarbonImmutable $c) => $c->format('Y-m-d');
        $lastMonth = $today->startOfMonth()->subMonth();

        return [
            ['key' => 'today', 'from' => $d($today), 'to' => $d($today), 'granularity' => 'day'],
            ['key' => 'yesterday', 'from' => $d($today->subDay()), 'to' => $d($today->subDay()), 'granularity' => 'day'],
            ['key' => 'last7', 'from' => $d($today->subDays(6)), 'to' => $d($today), 'granularity' => 'day'],
            ['key' => 'last30', 'from' => $d($today->subDays(29)), 'to' => $d($today), 'granularity' => 'day'],
            ['key' => 'this_month', 'from' => $d($today->startOfMonth()), 'to' => $d($today), 'granularity' => 'day'],
            ['key' => 'last_month', 'from' => $d($lastMonth), 'to' => $d($lastMonth->endOfMonth()), 'granularity' => 'day'],
            ['key' => 'last90', 'from' => $d($today->subDays(89)), 'to' => $d($today), 'granularity' => 'week'],
            ['key' => 'this_year', 'from' => $d($today->startOfYear()), 'to' => $d($today), 'granularity' => 'month'],
            ['key' => 'last_year', 'from' => $d($today->subYear()->startOfYear()), 'to' => $d($today->subYear()->endOfYear()), 'granularity' => 'month'],
        ];
    }

    /**
     * 현재 기간과 일치하는 프리셋 키 (없으면 custom)
     */
    public function presetKey(?CarbonImmutable $now = null): string
    {
        foreach (self::presets($this->timezone, $now) as $p) {
            if ($p['from'] === $this->from->format('Y-m-d') && $p['to'] === $this->to->format('Y-m-d')) {
                return $p['key'];
            }
        }

        return 'custom';
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'timezone' => $this->timezone,
            'granularity' => $this->granularity,
            'requested_granularity' => $this->requestedGranularity,
            'days' => $this->days(),
            'preset' => $this->presetKey(),
        ];
    }

    public static function dbTimezone(): string
    {
        return self::safeTimezone((string) config('app.timezone', 'UTC'));
    }

    private static function fitGranularity(string $granularity, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = (int) $from->diffInDays($to) + 1;
        $order = self::GRANULARITIES;
        $index = array_search($granularity, $order, true);

        while ($index < count($order) - 1) {
            $buckets = match ($order[$index]) {
                'day' => $days,
                'week' => (int) ceil($days / 7) + 1,
                'month' => (int) ceil($days / 28) + 1,
                default => 1,
            };
            if ($buckets <= self::MAX_BUCKETS) {
                break;
            }
            $index++;
        }

        return $order[$index];
    }

    private static function parseDate(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (\Throwable) {
            return null;
        }

        return ($date && $date->format('Y-m-d') === $value) ? $date : null;
    }

    private static function safeTimezone(string $timezone): string
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'Asia/Seoul';
    }
}
