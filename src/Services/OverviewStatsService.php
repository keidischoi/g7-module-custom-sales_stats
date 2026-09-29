<?php

namespace Modules\Custom\SalesStats\Services;

use Modules\Custom\SalesStats\Support\Availability;
use Modules\Custom\SalesStats\Support\Filters;
use Modules\Custom\SalesStats\Support\Money;
use Modules\Custom\SalesStats\Support\Stats;

/**
 * 전체(이커머스 + 개인 마켓) 한눈에 보기
 *
 * 합계는 이커머스 기본 통화가 원(KRW)일 때만 더합니다 (개인 마켓은 원 고정).
 */
class OverviewStatsService
{
    public function __construct(
        private Availability $availability,
        private EcommerceStatsService $ecommerce,
        private MarketStatsService $market,
        private Money $money,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Filters $f, bool $withEmail): array
    {
        $hasE = $this->availability->ecommerce();
        $hasM = $this->availability->market();

        $e = $hasE ? $this->ecommerce->summary($f) : null;
        $m = $hasM ? $this->market->summary($f) : null;
        $eSeries = $hasE ? $this->ecommerce->timeseries($f) : null;
        $mSeries = $hasM ? $this->market->timeseries($f) : null;

        $currency = $this->money->ecommerceCurrency();
        $combinable = ! $hasE || $currency['code'] === 'KRW';

        $eSales = (float) ($e['raw']['sales'] ?? 0);
        $mGmv = (float) ($m['raw']['gmv'] ?? 0);
        $combined = null;
        if ($combinable) {
            $prevE = $hasE && $f->compare ? (float) ($e['kpis']['sales']['previous'] ?? 0) : null;
            $prevM = $hasM && $f->compare ? (float) ($m['kpis']['gmv']['previous'] ?? 0) : null;
            $prevTotal = $f->compare ? (float) ($prevE ?? 0) + (float) ($prevM ?? 0) : null;
            $prevOrders = $f->compare ? (float) ($e['kpis']['orders']['previous'] ?? 0) + (float) ($m['kpis']['orders']['previous'] ?? 0) : null;
            $total = $eSales + $mGmv;
            $orders = (int) ($e['raw']['orders'] ?? 0) + (int) ($m['raw']['orders'] ?? 0);
            $combined = [
                'total' => $this->card($total, $prevTotal, fn ($v) => $this->money->formatMarket($v)),
                'orders' => $this->card($orders, $prevOrders),
                'share' => [
                    ['name' => __('custom-sales_stats::labels.channel.ecommerce'), 'value' => $eSales, 'color' => '#6366F1', 'formatted' => $this->money->formatMarket($eSales), 'percent' => Stats::ratio($eSales, $total)],
                    ['name' => __('custom-sales_stats::labels.channel.market'), 'value' => $mGmv, 'color' => '#10B981', 'formatted' => $this->money->formatMarket($mGmv), 'percent' => Stats::ratio($mGmv, $total)],
                ],
            ];
        }

        $labels = $eSeries['labels'] ?? $mSeries['labels'] ?? [];
        $rows = [];
        foreach ($labels as $i => $label) {
            $es = (float) ($eSeries['sales'][$i] ?? 0);
            $ms = (float) ($mSeries['gmv'][$i] ?? 0);
            $rows[] = [
                'period' => $eSeries['rows'][$i]['period'] ?? $mSeries['rows'][$i]['period'] ?? $label,
                'label' => $label,
                'ecommerce' => $es,
                'ecommerce_formatted' => $this->money->formatEcommerce($es),
                'ecommerce_orders' => (int) ($eSeries['orders'][$i] ?? 0),
                'market' => $ms,
                'market_formatted' => $this->money->formatMarket($ms),
                'market_orders' => (int) ($mSeries['orders'][$i] ?? 0),
            ];
        }

        return [
            'available' => $this->availability->toArray(),
            'combinable' => $combinable,
            'currency' => $currency,
            'combined' => $combined,
            'ecommerce' => $e ? ['kpis' => $e['kpis']] : null,
            'market' => $m ? ['kpis' => $m['kpis'], 'snapshot' => $m['snapshot']] : null,
            'series' => [
                'granularity' => $f->range->granularity,
                'labels' => $labels,
                'ecommerce' => array_column($rows, 'ecommerce'),
                'market' => array_column($rows, 'market'),
                'rows' => $rows,
            ],
            'top_products' => $hasE ? $this->ecommerce->products($f, 5) : [],
            'top_sellers' => $hasM ? $this->market->sellers($f->with(['page' => 1, 'per_page' => 5, 'sort' => 'amount', 'dir' => 'desc', 'q' => null]), $withEmail)['rows'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(float|int $value, float|int|null $previous, ?callable $formatter = null): array
    {
        $change = $previous === null ? null : Stats::change($value, $previous);

        return [
            'value' => $value,
            'formatted' => $formatter ? $formatter($value) : number_format((float) $value),
            'change' => $change,
            'trend' => Stats::trend($change),
        ];
    }
}
