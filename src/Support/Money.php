<?php

namespace Modules\Custom\SalesStats\Support;

/**
 * 금액 표시
 *
 * - 이커머스: 이커머스 모듈 기본 통화 (설정/CurrencyConversionService 가 있으면 그 규칙)
 * - 개인 마켓: 원(KRW) 고정 (마켓 테이블은 원 단위 정수)
 */
class Money
{
    private const SYMBOLS = [
        'KRW' => '₩', 'USD' => '$', 'EUR' => '€', 'JPY' => '¥',
        'CNY' => '¥', 'GBP' => '£', 'VND' => '₫', 'THB' => '฿',
    ];

    /** @var array<string, mixed>|null */
    private ?array $ecommerce = null;

    /**
     * @return array{code: string, symbol: string, decimals: int}
     */
    public function ecommerceCurrency(): array
    {
        if ($this->ecommerce !== null) {
            return $this->ecommerce;
        }

        $code = 'KRW';
        $decimals = 0;

        try {
            $service = 'Modules\\Sirsoft\\Ecommerce\\Services\\CurrencyConversionService';
            if (class_exists($service)) {
                $svc = app($service);
                $code = (string) $svc->getDefaultCurrency();
                $decimals = (int) $svc->getDecimalPlaces($code);
            } elseif (function_exists('g7_module_settings')) {
                $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency') ?: [];
                $code = (string) ($settings['default_currency'] ?? 'KRW');
                foreach ($settings['currencies'] ?? [] as $c) {
                    if (($c['code'] ?? '') === $code) {
                        $decimals = (int) ($c['decimal_places'] ?? 0);
                    }
                }
            }
        } catch (\Throwable) {
            // 기본값 유지
        }

        if (in_array($code, ['KRW', 'JPY', 'VND'], true)) {
            $decimals = 0;
        }

        return $this->ecommerce = [
            'code' => $code,
            'symbol' => self::SYMBOLS[$code] ?? $code,
            'decimals' => max(0, min(4, $decimals)),
        ];
    }

    /**
     * @return array{code: string, symbol: string, decimals: int}
     */
    public function marketCurrency(): array
    {
        return ['code' => 'KRW', 'symbol' => '₩', 'decimals' => 0];
    }

    /**
     * @param  array{code: string, symbol: string, decimals: int}  $currency
     */
    public function format(float|int|null $amount, array $currency): string
    {
        $amount = (float) ($amount ?? 0);
        $sign = $amount < 0 ? '-' : '';
        $number = number_format(abs($amount), $currency['decimals']);

        return isset(self::SYMBOLS[$currency['code']])
            ? $sign.$currency['symbol'].$number
            : $sign.$number.' '.$currency['code'];
    }

    public function formatEcommerce(float|int|null $amount): string
    {
        return $this->format($amount, $this->ecommerceCurrency());
    }

    public function formatMarket(float|int|null $amount): string
    {
        return $this->format($amount, $this->marketCurrency());
    }

    /**
     * 짧은 표기 (차트 축·카드 보조) — 1.2만 / 3.4억 / 1.2K / 3.4M
     */
    public static function compact(float|int|null $amount, string $locale = 'ko'): string
    {
        $v = (float) ($amount ?? 0);
        $abs = abs($v);
        if ($locale === 'ko') {
            if ($abs >= 1e8) {
                return self::trim($v / 1e8).'억';
            }
            if ($abs >= 1e4) {
                return self::trim($v / 1e4).'만';
            }

            return number_format($v);
        }
        if ($abs >= 1e9) {
            return self::trim($v / 1e9).'B';
        }
        if ($abs >= 1e6) {
            return self::trim($v / 1e6).'M';
        }
        if ($abs >= 1e3) {
            return self::trim($v / 1e3).'K';
        }

        return number_format($v);
    }

    private static function trim(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}
