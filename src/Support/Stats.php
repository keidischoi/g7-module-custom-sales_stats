<?php

namespace Modules\Custom\SalesStats\Support;

/**
 * 공통 계산 도우미
 */
final class Stats
{
    /**
     * 증감률(%) — 이전 값이 0 이면 null (비교 불가)
     */
    public static function change(float|int|null $current, float|int|null $previous): ?float
    {
        $c = (float) ($current ?? 0);
        $p = (float) ($previous ?? 0);
        if ($p == 0.0) {
            return null;
        }

        return round(($c - $p) / abs($p) * 100, 1);
    }

    public static function trend(?float $change): string
    {
        if ($change === null || $change == 0.0) {
            return 'neutral';
        }

        return $change > 0 ? 'up' : 'down';
    }

    public static function ratio(float|int|null $part, float|int|null $total, int $precision = 1): float
    {
        $t = (float) ($total ?? 0);

        return $t == 0.0 ? 0.0 : round((float) ($part ?? 0) / $t * 100, $precision);
    }

    public static function safeDiv(float|int|null $a, float|int|null $b, int $precision = 2): float
    {
        $d = (float) ($b ?? 0);

        return $d == 0.0 ? 0.0 : round((float) ($a ?? 0) / $d, $precision);
    }

    /**
     * 다국어 JSON 문자열 → 현재 로케일 문자열
     */
    public static function localeName(mixed $raw, ?string $fallback = null): string
    {
        if ($raw === null || $raw === '') {
            return $fallback ?? '-';
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return $raw;
            }
            $raw = $decoded;
        }
        if (is_array($raw)) {
            $locale = app()->getLocale();
            foreach ([$locale, 'ko', 'en'] as $key) {
                if (! empty($raw[$key]) && is_string($raw[$key])) {
                    return $raw[$key];
                }
            }
            foreach ($raw as $v) {
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }

        return $fallback ?? '-';
    }

    /**
     * 차트용 색상 팔레트 (라이트/다크 모두 읽히는 중간 톤)
     *
     * @return array<int, string>
     */
    public static function palette(): array
    {
        return ['#6366F1', '#10B981', '#F59E0B', '#EC4899', '#0EA5E9', '#8B5CF6', '#EF4444', '#14B8A6', '#84CC16', '#64748B'];
    }
}
