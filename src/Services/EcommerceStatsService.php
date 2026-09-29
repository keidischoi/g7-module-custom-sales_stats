<?php

namespace Modules\Custom\SalesStats\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Custom\SalesStats\Support\Filters;
use Modules\Custom\SalesStats\Support\Members;
use Modules\Custom\SalesStats\Support\Money;
use Modules\Custom\SalesStats\Support\PeriodRange;
use Modules\Custom\SalesStats\Support\Sql;
use Modules\Custom\SalesStats\Support\Stats;

/**
 * 이커머스(sirsoft-ecommerce) 판매 통계 — 읽기 전용
 *
 * 집계 기준
 * - 결제 기준(paid): 결제완료 ~ 구매확정 상태 주문, 날짜 = 결제일(paid_at, 없으면 주문일)
 * - 확정 기준(confirmed): 구매확정 주문, 날짜 = 구매확정일(confirmed_at)
 * - 결제 전(pending_order/pending_payment)·전체 취소(cancelled) 주문은 매출에서 제외
 * - 부분 취소는 이커머스가 주문 합계(total_amount)를 다시 계산하고 취소된 옵션을 cancelled 로 바꾸므로
 *   매출·상품 집계에 자동 반영되고, 취소·환불액은 취소일(cancelled_at) 기준으로 따로 보여 줍니다.
 */
class EcommerceStatsService
{
    use Concerns;

    public const PAID_STATUSES = [
        'payment_complete', 'shipping_hold', 'preparing', 'shipping_ready', 'shipping', 'delivered', 'confirmed',
    ];

    public const ALL_STATUSES = [
        'pending_order', 'pending_payment', 'payment_complete', 'shipping_hold', 'preparing',
        'shipping_ready', 'shipping', 'delivered', 'confirmed', 'cancelled',
    ];

    public function __construct(private Money $money) {}

    /* ───────────── 기본 쿼리 ───────────── */

    /** 매출 날짜 식 */
    public function dateExpr(string $basis): string
    {
        $cols = $basis === 'confirmed'
            ? array_filter(['o.confirmed_at', 'o.paid_at', 'o.ordered_at'], fn ($c) => $this->hasColumn('ecommerce_orders', substr($c, 2)))
            : array_filter(['o.paid_at', 'o.ordered_at'], fn ($c) => $this->hasColumn('ecommerce_orders', substr($c, 2)));

        return Sql::coalesce(array_values($cols) ?: ['o.created_at']);
    }

    /** 매출로 잡히는 주문 (ecommerce_orders as o) */
    public function orders(Filters $f): Builder
    {
        $q = DB::table('ecommerce_orders as o');
        $q->whereIn('o.order_status', $f->basis === 'confirmed' ? ['confirmed'] : self::PAID_STATUSES);

        return Sql::whereBetweenRaw($q, $this->dateExpr($f->basis), $f->range);
    }

    /** 매출로 잡히는 주문 옵션 (취소 옵션 제외) */
    public function options(Filters $f): Builder
    {
        $q = $this->orders($f)->join('ecommerce_order_options as oi', 'oi.order_id', '=', 'o.id');
        if ($this->hasColumn('ecommerce_order_options', 'option_status')) {
            $q->where(function ($w) {
                $w->where('oi.option_status', '!=', 'cancelled')->orWhereNull('oi.option_status');
            });
        }

        return $q;
    }

    /** 옵션 금액 식 (판매가 소계 − 할인 소계) */
    protected function optionAmountExpr(): string
    {
        $price = Sql::wrap('oi.subtotal_price');
        if ($this->hasColumn('ecommerce_order_options', 'subtotal_discount_amount')) {
            return "(COALESCE({$price}, 0) - COALESCE(".Sql::wrap('oi.subtotal_discount_amount').', 0))';
        }

        return "COALESCE({$price}, 0)";
    }

    /** 수량 식 — 추가옵션/구성품(parent_option_id 있음)은 수량에서 제외 */
    protected function optionQtyExpr(): string
    {
        $qty = Sql::wrap('oi.quantity');
        if ($this->hasColumn('ecommerce_order_options', 'parent_option_id')) {
            return 'CASE WHEN '.Sql::wrap('oi.parent_option_id')." IS NULL THEN COALESCE({$qty}, 0) ELSE 0 END";
        }

        return "COALESCE({$qty}, 0)";
    }

    /* ───────────── 요약 ───────────── */

    /**
     * @return array<string, mixed>
     */
    public function summary(Filters $f): array
    {
        return $this->remember($f->key('ecommerce.summary'), function () use ($f) {
            $cur = $this->rawSummary($f);
            $prev = $f->compare ? $this->rawSummary($f->withRange($f->range->previous())) : null;
            $money = fn ($v) => $this->money->formatEcommerce($v);
            $pct = fn ($v) => number_format((float) $v, 1).'%';

            return [
                'currency' => $this->money->ecommerceCurrency(),
                'raw' => $cur,
                'kpis' => [
                    'sales' => $this->kpi($cur['sales'], $prev['sales'] ?? null, $money),
                    'orders' => $this->kpi($cur['orders'], $prev['orders'] ?? null),
                    'aov' => $this->kpi($cur['aov'], $prev['aov'] ?? null, $money),
                    'items' => $this->kpi($cur['items'], $prev['items'] ?? null),
                    'buyers' => $this->kpi($cur['buyers'], $prev['buyers'] ?? null),
                    'first_order_rate' => $this->kpi($cur['first_order_rate'], $prev['first_order_rate'] ?? null, $pct),
                    'discount' => $this->kpi($cur['discount'], $prev['discount'] ?? null, $money),
                    'points_used' => $this->kpi($cur['points_used'], $prev['points_used'] ?? null, $money),
                    'shipping' => $this->kpi($cur['shipping'], $prev['shipping'] ?? null, $money),
                    'cancelled_amount' => $this->kpi($cur['cancelled_amount'], $prev['cancelled_amount'] ?? null, $money, true),
                    'cancelled_orders' => $this->kpi($cur['cancelled_orders'], $prev['cancelled_orders'] ?? null, null, true),
                    'cancel_rate' => $this->kpi($cur['cancel_rate'], $prev['cancel_rate'] ?? null, $pct, true),
                ],
            ];
        });
    }

    /**
     * @return array<string, float|int>
     */
    public function rawSummary(Filters $f): array
    {
        $o = fn (string $c) => Sql::wrap('o.'.$c);
        $sum = fn (string $c) => $this->hasColumn('ecommerce_orders', $c) ? "COALESCE(SUM({$o($c)}), 0)" : '0';

        $row = $this->orders($f)
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw("{$sum('total_amount')} as sales")
            ->selectRaw("{$sum('total_discount_amount')} as discount")
            ->selectRaw("{$sum('total_shipping_amount')} as shipping")
            ->selectRaw("{$sum('total_points_used_amount')} as points_used")
            ->selectRaw("COUNT(DISTINCT {$o('user_id')}) as buyers")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$o('user_id')} IS NULL THEN 1 ELSE 0 END), 0) as guest_orders")
            ->selectRaw(($this->hasColumn('ecommerce_orders', 'is_first_order') ? "COALESCE(SUM(CASE WHEN {$o('is_first_order')} = 1 THEN 1 ELSE 0 END), 0)" : '0').' as first_orders')
            ->first();

        $items = (int) ($this->options($f)->selectRaw('COALESCE(SUM('.$this->optionQtyExpr().'), 0) as q')->value('q') ?? 0);

        [$cancelledAmount, $cancelledOrders] = $this->cancellations($f);

        $orders = (int) ($row->orders ?? 0);
        $sales = (float) ($row->sales ?? 0);

        return [
            'orders' => $orders,
            'sales' => round($sales, 2),
            'discount' => round((float) ($row->discount ?? 0), 2),
            'shipping' => round((float) ($row->shipping ?? 0), 2),
            'points_used' => round((float) ($row->points_used ?? 0), 2),
            'buyers' => (int) ($row->buyers ?? 0),
            'guest_orders' => (int) ($row->guest_orders ?? 0),
            'first_orders' => (int) ($row->first_orders ?? 0),
            'first_order_rate' => Stats::ratio($row->first_orders ?? 0, $orders),
            'items' => $items,
            'aov' => round(Stats::safeDiv($sales, $orders), 2),
            'cancelled_amount' => $cancelledAmount,
            'cancelled_orders' => $cancelledOrders,
            'cancel_rate' => Stats::ratio($cancelledOrders, $orders + $cancelledOrders),
        ];
    }

    /**
     * 기간 내 취소·환불 (취소일 기준, 결제 후 취소만 금액이 있음)
     *
     * @return array{0: float, 1: int} [취소·환불 금액, 전체 취소 주문 수]
     */
    protected function cancellations(Filters $f): array
    {
        if (! $this->hasColumn('ecommerce_orders', 'total_cancelled_amount')) {
            return [0.0, 0];
        }
        $dateCol = $this->hasColumn('ecommerce_orders', 'cancelled_at') ? 'o.cancelled_at' : 'o.updated_at';
        $q = DB::table('ecommerce_orders as o')->where('o.total_cancelled_amount', '>', 0);
        Sql::whereBetweenRaw($q, Sql::wrap($dateCol), $f->range);

        $row = $q->selectRaw('COALESCE(SUM('.Sql::wrap('o.total_cancelled_amount').'), 0) as amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.Sql::wrap('o.order_status')." = 'cancelled' THEN 1 ELSE 0 END), 0) as full_cancelled")
            ->first();

        return [round((float) ($row->amount ?? 0), 2), (int) ($row->full_cancelled ?? 0)];
    }

    /* ───────────── 시계열 ───────────── */

    /**
     * @return array<string, mixed>
     */
    public function timeseries(Filters $f): array
    {
        return $this->remember($f->key('ecommerce.timeseries'), function () use ($f) {
            $range = $f->range;
            $bucket = Sql::bucket($this->dateExpr($f->basis), $range->granularity, $range->offsetSeconds());

            $byKey = [];
            foreach ($this->orders($f)->selectRaw("{$bucket} as k")
                ->selectRaw('COUNT(*) as orders')
                ->selectRaw('COALESCE(SUM('.Sql::wrap('o.total_amount').'), 0) as sales')
                ->groupBy(DB::raw($bucket))->get() as $r) {
                $byKey[(string) $r->k] = ['orders' => (int) $r->orders, 'sales' => round((float) $r->sales, 2)];
            }
            foreach ($this->options($f)->selectRaw("{$bucket} as k")
                ->selectRaw('COALESCE(SUM('.$this->optionQtyExpr().'), 0) as items')
                ->groupBy(DB::raw($bucket))->get() as $r) {
                $byKey[(string) $r->k]['items'] = (int) $r->items;
            }

            $rows = $this->fillSeries($range, $byKey, ['sales', 'orders', 'items']);
            foreach ($rows as &$row) {
                $row['sales_formatted'] = $this->money->formatEcommerce($row['sales']);
                $row['aov_formatted'] = $this->money->formatEcommerce(Stats::safeDiv($row['sales'], $row['orders']));
            }
            unset($row);

            return [
                'granularity' => $range->granularity,
                'labels' => array_column($rows, 'label'),
                'sales' => array_column($rows, 'sales'),
                'orders' => array_column($rows, 'orders'),
                'items' => array_column($rows, 'items'),
                'rows' => $rows,
            ];
        });
    }

    /* ───────────── 상품 · 카테고리 ───────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function products(Filters $f, int $limit = 10): array
    {
        return $this->remember($f->key('ecommerce.products.'.$limit), function () use ($f, $limit) {
            $amount = $this->optionAmountExpr();
            $q = $this->options($f)
                ->selectRaw(Sql::wrap('oi.product_id').' as product_id')
                ->selectRaw('MAX('.Sql::wrap('oi.product_name').') as product_name')
                ->selectRaw('COALESCE(SUM('.$this->optionQtyExpr().'), 0) as quantity')
                ->selectRaw('COUNT(DISTINCT '.Sql::wrap('o.id').') as orders')
                ->selectRaw("COALESCE(SUM({$amount}), 0) as amount")
                ->groupBy('oi.product_id')
                ->orderByDesc('amount')
                ->limit($limit);

            $hasProducts = $this->hasTable('ecommerce_products');
            if ($hasProducts && $this->hasColumn('ecommerce_products', 'product_code')) {
                $q->leftJoin('ecommerce_products as p', 'p.id', '=', 'oi.product_id')
                    ->selectRaw('MAX('.Sql::wrap('p.product_code').') as product_code');
            }

            $total = (float) ($this->options($f)->selectRaw("COALESCE(SUM({$amount}), 0) as t")->value('t') ?? 0);

            $rows = [];
            foreach ($q->get() as $r) {
                $code = $r->product_code ?? null;
                $rows[] = [
                    'product_id' => (int) $r->product_id,
                    'product_code' => $code,
                    'name' => Stats::localeName($r->product_name, '#'.$r->product_id),
                    'quantity' => (int) $r->quantity,
                    'orders' => (int) $r->orders,
                    'amount' => round((float) $r->amount, 2),
                    'amount_formatted' => $this->money->formatEcommerce($r->amount),
                    'admin_url' => '/admin/ecommerce/products/'.($code ?: $r->product_id).'/edit',
                ];
            }

            return $this->rank($rows, 'amount', $total);
        });
    }

    /**
     * 카테고리별 — 상품마다 대표 카테고리(is_primary, 없으면 가장 작은 ID) 하나로만 집계 (중복 합산 없음)
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(Filters $f, int $limit = 12): array
    {
        return $this->remember($f->key('ecommerce.categories.'.$limit), function () use ($f, $limit) {
            $amount = $this->optionAmountExpr();
            $q = $this->options($f);

            $canJoin = $this->hasTable('ecommerce_product_categories') && $this->hasTable('ecommerce_categories');
            if ($canJoin) {
                $primary = $this->hasColumn('ecommerce_product_categories', 'is_primary')
                    ? 'COALESCE(MAX(CASE WHEN '.Sql::wrap('is_primary').' = 1 THEN '.Sql::wrap('category_id').' END), MIN('.Sql::wrap('category_id').'))'
                    : 'MIN('.Sql::wrap('category_id').')';
                $map = DB::table('ecommerce_product_categories')
                    ->select('product_id')
                    ->selectRaw("{$primary} as category_id")
                    ->groupBy('product_id');
                $q->leftJoinSub($map, 'pcm', 'pcm.product_id', '=', 'oi.product_id')
                    ->leftJoin('ecommerce_categories as c', 'c.id', '=', 'pcm.category_id')
                    ->selectRaw('COALESCE('.Sql::wrap('c.id').', 0) as category_id')
                    ->selectRaw('MAX('.Sql::wrap('c.name').') as category_name')
                    ->groupBy(DB::raw('COALESCE('.Sql::wrap('c.id').', 0)'));
            } else {
                $q->selectRaw('0 as category_id')->selectRaw('NULL as category_name');
            }

            $rows = [];
            foreach ($q->selectRaw('COALESCE(SUM('.$this->optionQtyExpr().'), 0) as quantity')
                ->selectRaw('COUNT(DISTINCT '.Sql::wrap('o.id').') as orders')
                ->selectRaw("COALESCE(SUM({$amount}), 0) as amount")
                ->orderByDesc('amount')->get() as $r) {
                $rows[] = [
                    'category_id' => (int) $r->category_id,
                    'name' => (int) $r->category_id ? Stats::localeName($r->category_name, '#'.$r->category_id) : __('custom-sales_stats::labels.uncategorized'),
                    'quantity' => (int) $r->quantity,
                    'orders' => (int) $r->orders,
                    'amount' => round((float) $r->amount, 2),
                    'amount_formatted' => $this->money->formatEcommerce($r->amount),
                ];
            }

            return $this->rank($this->collapseTail($rows, $limit), 'amount');
        });
    }

    /* ───────────── 구매자 ───────────── */

    /**
     * @return array<string, mixed>
     */
    public function buyers(Filters $f, bool $withEmail, int $limit = 10): array
    {
        return $this->remember($f->key('ecommerce.buyers.'.$limit.'.'.(int) $withEmail), function () use ($f, $withEmail, $limit) {
            $dateExpr = $this->dateExpr($f->basis);
            $q = $this->orders($f)
                ->whereNotNull('o.user_id')
                ->join('users as u', 'u.id', '=', 'o.user_id')
                ->select('o.user_id')
                ->selectRaw('MAX('.Sql::wrap('u.id').') as u_id, MAX('.Sql::wrap('u.uuid').') as u_uuid, MAX('.Sql::wrap('u.nickname').') as u_nickname, MAX('.Sql::wrap('u.name').') as u_name, MAX('.Sql::wrap('u.email').') as u_email, MAX('.Sql::wrap('u.status').') as u_status')
                ->selectRaw('COUNT(*) as orders')
                ->selectRaw('COALESCE(SUM('.Sql::wrap('o.total_amount').'), 0) as amount')
                ->selectRaw("MAX({$dateExpr}) as last_at")
                ->groupBy('o.user_id')
                ->orderByDesc('amount')
                ->limit($limit);

            $rows = [];
            foreach ($q->get() as $r) {
                $rows[] = [
                    'member' => Members::summary($r, 'u_', $withEmail),
                    'orders' => (int) $r->orders,
                    'amount' => round((float) $r->amount, 2),
                    'amount_formatted' => $this->money->formatEcommerce($r->amount),
                    'aov_formatted' => $this->money->formatEcommerce(Stats::safeDiv($r->amount, $r->orders)),
                    'last_at' => $this->localTime($r->last_at, $f),
                ];
            }

            $guest = $this->orders($f)->whereNull('o.user_id')
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM('.Sql::wrap('o.total_amount').'), 0) as amount')->first();

            return [
                'rows' => $this->rank($rows, 'amount'),
                'guest' => [
                    'orders' => (int) ($guest->orders ?? 0),
                    'amount_formatted' => $this->money->formatEcommerce($guest->amount ?? 0),
                ],
            ];
        });
    }

    /* ───────────── 분포 ───────────── */

    /**
     * 주문 상태 분포 (주문일 기준, 결제 전·취소 포함 전체 파이프라인)
     *
     * @return array<int, array<string, mixed>>
     */
    public function statuses(Filters $f): array
    {
        return $this->remember($f->key('ecommerce.statuses'), function () use ($f) {
            $col = $this->hasColumn('ecommerce_orders', 'ordered_at') ? 'o.ordered_at' : 'o.created_at';
            $q = DB::table('ecommerce_orders as o');
            Sql::whereBetweenRaw($q, Sql::wrap($col), $f->range);
            $counts = $q->selectRaw(Sql::wrap('o.order_status').' as s, COUNT(*) as c')->groupBy('o.order_status')->pluck('c', 's')->all();

            $rows = [];
            foreach (self::ALL_STATUSES as $status) {
                if (! empty($counts[$status])) {
                    $rows[] = ['key' => $status, 'label' => __('custom-sales_stats::labels.ecommerce_status.'.$status), 'value' => (int) $counts[$status]];
                    unset($counts[$status]);
                }
            }
            foreach ($counts as $status => $c) {
                $rows[] = ['key' => (string) $status, 'label' => (string) $status, 'value' => (int) $c];
            }

            return $this->rank($rows, 'value');
        });
    }

    /**
     * 결제수단 분포 (주문당 1건으로 계산)
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentMethods(Filters $f): array
    {
        if (! $this->hasTable('ecommerce_order_payments')) {
            return [];
        }

        return $this->remember($f->key('ecommerce.payments'), function () use ($f) {
            $perOrder = DB::table('ecommerce_order_payments')
                ->select('order_id')->selectRaw('MAX('.Sql::wrap('payment_method').') as method')->groupBy('order_id');

            $rows = [];
            foreach ($this->orders($f)->leftJoinSub($perOrder, 'pm', 'pm.order_id', '=', 'o.id')
                ->selectRaw('COALESCE('.Sql::wrap('pm.method').", 'unknown') as method")
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM('.Sql::wrap('o.total_amount').'), 0) as amount')
                ->groupBy(DB::raw('COALESCE('.Sql::wrap('pm.method').", 'unknown')"))
                ->orderByDesc('amount')->get() as $r) {
                $key = (string) $r->method;
                $label = __('custom-sales_stats::labels.payment_method.'.$key);
                $rows[] = [
                    'key' => $key,
                    'label' => str_starts_with($label, 'custom-sales_stats::') ? $key : $label,
                    'value' => round((float) $r->amount, 2),
                    'orders' => (int) $r->orders,
                    'amount_formatted' => $this->money->formatEcommerce($r->amount),
                ];
            }

            return $this->rank($rows, 'value');
        });
    }

    /**
     * 주문 기기 분포
     *
     * @return array<int, array<string, mixed>>
     */
    public function devices(Filters $f): array
    {
        if (! $this->hasColumn('ecommerce_orders', 'order_device')) {
            return [];
        }

        return $this->remember($f->key('ecommerce.devices'), function () use ($f) {
            $rows = [];
            $expr = 'COALESCE('.Sql::wrap('o.order_device').", 'unknown')";
            foreach ($this->orders($f)->selectRaw("{$expr} as d, COUNT(*) as orders, COALESCE(SUM(".Sql::wrap('o.total_amount').'), 0) as amount')
                ->groupBy(DB::raw($expr))->orderByDesc('orders')->get() as $r) {
                $key = (string) $r->d;
                $label = __('custom-sales_stats::labels.device.'.$key);
                $rows[] = [
                    'key' => $key,
                    'label' => str_starts_with($label, 'custom-sales_stats::') ? $key : $label,
                    'value' => (int) $r->orders,
                    'amount_formatted' => $this->money->formatEcommerce($r->amount),
                ];
            }

            return $this->rank($rows, 'value');
        });
    }

    /**
     * 기간 내 큰 주문 (주문 상세로 이동)
     *
     * @return array<int, array<string, mixed>>
     */
    public function topOrders(Filters $f, bool $withEmail, int $limit = 10): array
    {
        return $this->remember($f->key('ecommerce.top_orders.'.$limit.'.'.(int) $withEmail), function () use ($f, $withEmail, $limit) {
            $dateExpr = $this->dateExpr($f->basis);
            $rows = [];
            foreach ($this->orders($f)
                ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
                ->select('o.order_number', 'o.order_status', 'o.total_amount')
                ->selectRaw("{$dateExpr} as at")
                ->addSelect('u.id as u_id', 'u.uuid as u_uuid', 'u.nickname as u_nickname', 'u.name as u_name', 'u.email as u_email', 'u.status as u_status')
                ->orderByDesc('o.total_amount')->limit($limit)->get() as $r) {
                $rows[] = [
                    'order_number' => $r->order_number,
                    'status' => $r->order_status,
                    'status_label' => __('custom-sales_stats::labels.ecommerce_status.'.$r->order_status),
                    'amount_formatted' => $this->money->formatEcommerce($r->total_amount),
                    'at' => $this->localTime($r->at, $f),
                    'member' => Members::summary($r, 'u_', $withEmail),
                    'admin_url' => '/admin/ecommerce/orders/'.$r->order_number,
                ];
            }

            return $rows;
        });
    }

    /**
     * CSV 내보내기용 전체 행
     *
     * @return array{0: array<int, string>, 1: iterable<int, array<int, mixed>>}
     */
    public function exportRows(Filters $f, string $dataset, bool $withEmail): array
    {
        $cur = $this->money->ecommerceCurrency()['code'];
        $t = fn (string $k) => __('custom-sales_stats::labels.csv.'.$k);

        return match ($dataset) {
            'products' => [
                [$t('rank'), $t('product_id'), $t('product_code'), $t('product'), $t('quantity'), $t('orders'), $t('amount').' ('.$cur.')', $t('share')],
                array_map(fn ($r) => [$r['rank'], $r['product_id'], $r['product_code'], $r['name'], $r['quantity'], $r['orders'], $r['amount'], $r['share']], $this->products($f, 500)),
            ],
            'categories' => [
                [$t('rank'), $t('category_id'), $t('category'), $t('quantity'), $t('orders'), $t('amount').' ('.$cur.')', $t('share')],
                array_map(fn ($r) => [$r['rank'], $r['category_id'], $r['name'], $r['quantity'], $r['orders'], $r['amount'], $r['share']], $this->categories($f, 500)),
            ],
            'buyers' => [
                [$t('rank'), $t('member_id'), $t('nickname'), $t('email'), $t('orders'), $t('amount').' ('.$cur.')', $t('last_at')],
                array_map(fn ($r) => [$r['rank'], $r['member']['id'] ?? '', $r['member']['nickname'] ?? '', $r['member']['email'] ?? '', $r['orders'], $r['amount'], $r['last_at']], $this->buyers($f, $withEmail, 500)['rows']),
            ],
            default => [
                [$t('period'), $t('orders'), $t('quantity'), $t('amount').' ('.$cur.')'],
                array_map(fn ($r) => [$r['period'], $r['orders'], $r['items'], $r['sales']], $this->timeseries($f)['rows']),
            ],
        };
    }

    /**
     * 상위 N 개 + 나머지 "기타" 하나로
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function collapseTail(array $rows, int $limit): array
    {
        if (count($rows) <= $limit) {
            return $rows;
        }
        $head = array_slice($rows, 0, $limit - 1);
        $tail = array_slice($rows, $limit - 1);
        $amount = array_sum(array_column($tail, 'amount'));
        $head[] = [
            'category_id' => -1,
            'name' => __('custom-sales_stats::labels.others', ['count' => count($tail)]),
            'quantity' => array_sum(array_column($tail, 'quantity')),
            'orders' => array_sum(array_column($tail, 'orders')),
            'amount' => round($amount, 2),
            'amount_formatted' => $this->money->formatEcommerce($amount),
        ];

        return $head;
    }
}
