<?php

namespace Modules\Custom\SalesStats\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Custom\SalesStats\Support\Filters;
use Modules\Custom\SalesStats\Support\Members;
use Modules\Custom\SalesStats\Support\Money;
use Modules\Custom\SalesStats\Support\Sql;
use Modules\Custom\SalesStats\Support\Stats;

/**
 * 개인 마켓(custom-user_market) 판매 통계 — 읽기 전용
 *
 * 집계 기준
 * - 결제 기준(paid): 입금 확인된(paid_at 있음) 주문 중 paid / shipped / completed / 취소요청 / 보류 상태, 날짜 = 입금 확인일
 * - 확정 기준(confirmed): 거래완료(completed) 주문, 날짜 = 거래완료일
 * - 취소·환불: 입금 후 취소(cancelled)·환불(refunded)된 주문, 날짜 = 환불일/취소일
 * - 수수료·정산액은 주문에 저장된 값(commission_amount / settlement_amount) 그대로 (관리자 입금형만 발생)
 */
class MarketStatsService
{
    use Concerns;

    public const TRADED_STATUSES = ['paid', 'shipped', 'completed', 'cancel_requested', 'on_hold'];

    public const ALL_STATUSES = [
        'pending_payment', 'paid', 'shipped', 'completed', 'cancel_requested', 'on_hold', 'refund_pending', 'refunded', 'cancelled',
    ];

    private const USER_COLS = ['id', 'uuid', 'nickname', 'name', 'email', 'status'];

    public function __construct(private Money $money) {}

    /* ───────────── 기본 쿼리 ───────────── */

    public function dateExpr(string $basis): string
    {
        return $basis === 'confirmed' ? Sql::coalesce(['mo.completed_at', 'mo.paid_at']) : Sql::wrap('mo.paid_at');
    }

    /** 거래로 잡히는 주문 (user_markets_orders as mo) */
    public function orders(Filters $f, ?int $sellerId = null): Builder
    {
        $q = DB::table('user_markets_orders as mo');
        if ($f->basis === 'confirmed') {
            $q->where('mo.status', 'completed');
        } else {
            $q->whereIn('mo.status', self::TRADED_STATUSES)->whereNotNull('mo.paid_at');
        }
        Sql::whereBetweenRaw($q, $this->dateExpr($f->basis), $f->range);

        return $this->applyFilters($q, $f, $sellerId);
    }

    /** 입금 후 취소·환불 (취소/환불일 기준) */
    public function cancelled(Filters $f, ?int $sellerId = null): Builder
    {
        $q = DB::table('user_markets_orders as mo')
            ->whereIn('mo.status', ['cancelled', 'refunded'])
            ->whereNotNull('mo.paid_at');
        Sql::whereBetweenRaw($q, Sql::coalesce(['mo.refunded_at', 'mo.cancelled_at', 'mo.updated_at']), $f->range);

        return $this->applyFilters($q, $f, $sellerId);
    }

    protected function applyFilters(Builder $q, Filters $f, ?int $sellerId): Builder
    {
        if ($sellerId !== null) {
            $q->where('mo.seller_id', $sellerId);
        }
        if ($type = $f->get('type')) {
            $q->where('mo.listing_type', $type);
        }
        if ($mode = $f->get('payment_mode')) {
            $q->where('mo.payment_mode', $mode);
        }
        if ($category = $f->get('category')) {
            $q->whereIn('mo.listing_id', DB::table('user_markets_listings')->select('id')->where('category', $category));
        }

        return $q;
    }

    /* ───────────── 요약 ───────────── */

    /**
     * @return array<string, mixed>
     */
    public function summary(Filters $f, ?int $sellerId = null): array
    {
        return $this->remember($f->key('market.summary.'.(int) $sellerId), function () use ($f, $sellerId) {
            $cur = $this->rawSummary($f, $sellerId);
            $prev = $f->compare ? $this->rawSummary($f->withRange($f->range->previous()), $sellerId) : null;
            $won = fn ($v) => $this->money->formatMarket($v);
            $pct = fn ($v) => number_format((float) $v, 1).'%';

            return [
                'currency' => $this->money->marketCurrency(),
                'raw' => $cur,
                'kpis' => [
                    'gmv' => $this->kpi($cur['gmv'], $prev['gmv'] ?? null, $won),
                    'orders' => $this->kpi($cur['orders'], $prev['orders'] ?? null),
                    'aov' => $this->kpi($cur['aov'], $prev['aov'] ?? null, $won),
                    'quantity' => $this->kpi($cur['quantity'], $prev['quantity'] ?? null),
                    'buyers' => $this->kpi($cur['buyers'], $prev['buyers'] ?? null),
                    'active_sellers' => $this->kpi($cur['active_sellers'], $prev['active_sellers'] ?? null),
                    'commission' => $this->kpi($cur['commission'], $prev['commission'] ?? null, $won),
                    'payout' => $this->kpi($cur['payout'], $prev['payout'] ?? null, $won),
                    'mileage_used' => $this->kpi($cur['mileage_used'], $prev['mileage_used'] ?? null, $won),
                    'settled_amount' => $this->kpi($cur['settled_amount'], $prev['settled_amount'] ?? null, $won),
                    'cancelled_orders' => $this->kpi($cur['cancelled_orders'], $prev['cancelled_orders'] ?? null, null, true),
                    'cancelled_amount' => $this->kpi($cur['cancelled_amount'], $prev['cancelled_amount'] ?? null, $won, true),
                    'cancel_rate' => $this->kpi($cur['cancel_rate'], $prev['cancel_rate'] ?? null, $pct, true),
                    'new_sellers' => $this->kpi($cur['new_sellers'], $prev['new_sellers'] ?? null),
                    'new_listings' => $this->kpi($cur['new_listings'], $prev['new_listings'] ?? null),
                ],
                'snapshot' => $this->snapshot($sellerId),
            ];
        });
    }

    /**
     * @return array<string, float|int>
     */
    public function rawSummary(Filters $f, ?int $sellerId = null): array
    {
        $w = fn (string $c) => Sql::wrap('mo.'.$c);
        $row = $this->orders($f, $sellerId)
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw("COALESCE(SUM({$w('total_amount')}), 0) as gmv")
            ->selectRaw("COALESCE(SUM({$w('quantity')}), 0) as quantity")
            ->selectRaw("COUNT(DISTINCT {$w('buyer_id')}) as buyers")
            ->selectRaw("COUNT(DISTINCT {$w('seller_id')}) as sellers")
            ->selectRaw("COALESCE(SUM({$w('commission_amount')}), 0) as commission")
            ->selectRaw("COALESCE(SUM({$w('settlement_amount')}), 0) as payout")
            ->selectRaw(($this->hasColumn('user_markets_orders', 'mileage_used') ? "COALESCE(SUM({$w('mileage_used')}), 0)" : '0').' as mileage_used')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$w('listing_type')} = 'digital' THEN 1 ELSE 0 END), 0) as digital_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$w('payment_mode')} = 'escrow' THEN 1 ELSE 0 END), 0) as escrow_orders")
            ->first();

        $cancel = $this->cancelled($f, $sellerId)
            ->selectRaw("COUNT(*) as c, COALESCE(SUM({$w('total_amount')}), 0) as amount")->first();

        $settledQ = DB::table('user_markets_orders as mo')->where('mo.settlement_status', 'done');
        Sql::whereBetweenRaw($settledQ, $w('settled_at'), $f->range);
        $this->applyFilters($settledQ, $f, $sellerId);
        $settled = $settledQ->selectRaw("COUNT(*) as c, COALESCE(SUM({$w('settlement_amount')}), 0) as amount")->first();

        $newSellers = 0;
        if ($sellerId === null) {
            $ns = DB::table('user_markets_sellers as ms')->where('ms.status', 'approved');
            Sql::whereBetweenRaw($ns, Sql::wrap('ms.approved_at'), $f->range);
            $newSellers = (int) $ns->count();
        }

        $nl = DB::table('user_markets_listings as ml')->whereNull('ml.deleted_at');
        Sql::whereBetweenRaw($nl, Sql::coalesce(['ml.published_at', 'ml.created_at']), $f->range);
        if ($sellerId !== null) {
            $nl->where('ml.user_id', $sellerId);
        }
        if ($type = $f->get('type')) {
            $nl->where('ml.type', $type);
        }
        if ($category = $f->get('category')) {
            $nl->where('ml.category', $category);
        }

        $orders = (int) ($row->orders ?? 0);
        $gmv = (int) ($row->gmv ?? 0);
        $cancelled = (int) ($cancel->c ?? 0);

        return [
            'orders' => $orders,
            'gmv' => $gmv,
            'quantity' => (int) ($row->quantity ?? 0),
            'buyers' => (int) ($row->buyers ?? 0),
            'active_sellers' => (int) ($row->sellers ?? 0),
            'commission' => (int) ($row->commission ?? 0),
            'payout' => (int) ($row->payout ?? 0),
            'mileage_used' => (int) ($row->mileage_used ?? 0),
            'digital_orders' => (int) ($row->digital_orders ?? 0),
            'escrow_orders' => (int) ($row->escrow_orders ?? 0),
            'aov' => (int) round(Stats::safeDiv($gmv, $orders)),
            'cancelled_orders' => $cancelled,
            'cancelled_amount' => (int) ($cancel->amount ?? 0),
            'cancel_rate' => Stats::ratio($cancelled, $orders + $cancelled),
            'settled_orders' => (int) ($settled->c ?? 0),
            'settled_amount' => (int) ($settled->amount ?? 0),
            'new_sellers' => $newSellers,
            'new_listings' => (int) $nl->count(),
        ];
    }

    /**
     * 지금 시점 현황 (기간과 무관)
     *
     * @return array<string, mixed>
     */
    public function snapshot(?int $sellerId = null): array
    {
        $w = fn (string $c) => Sql::wrap('mo.'.$c);
        $pendingQ = DB::table('user_markets_orders as mo')->where('mo.settlement_status', 'pending');
        if ($sellerId !== null) {
            $pendingQ->where('mo.seller_id', $sellerId);
        }
        $pending = $pendingQ->selectRaw("COUNT(*) as c, COALESCE(SUM({$w('settlement_amount')}), 0) as amount, COALESCE(SUM({$w('commission_amount')}), 0) as commission")->first();

        $listingQ = DB::table('user_markets_listings as ml')->whereNull('ml.deleted_at');
        if ($sellerId !== null) {
            $listingQ->where('ml.user_id', $sellerId);
        }
        $listings = $listingQ->selectRaw(Sql::wrap('ml.status').' as s, COUNT(*) as c')->groupBy('ml.status')->pluck('c', 's')->all();

        $out = [
            'settlement_pending_orders' => (int) ($pending->c ?? 0),
            'settlement_pending_amount' => (int) ($pending->amount ?? 0),
            'settlement_pending_amount_formatted' => $this->money->formatMarket($pending->amount ?? 0),
            'settlement_pending_commission_formatted' => $this->money->formatMarket($pending->commission ?? 0),
            'listings_on_sale' => (int) ($listings['on_sale'] ?? 0),
            'listings_pending_review' => (int) ($listings['pending_review'] ?? 0),
            'listings_total' => (int) array_sum($listings),
        ];

        if ($sellerId === null) {
            $sellers = DB::table('user_markets_sellers')->selectRaw('status as s, COUNT(*) as c')->groupBy('status')->pluck('c', 's')->all();
            $out['sellers_approved'] = (int) ($sellers['approved'] ?? 0);
            $out['sellers_pending'] = (int) ($sellers['pending'] ?? 0);
            $out['sellers_suspended'] = (int) ($sellers['suspended'] ?? 0);
            $out['open_orders'] = (int) DB::table('user_markets_orders')->whereIn('status', ['pending_payment', 'paid', 'shipped', 'cancel_requested', 'on_hold', 'refund_pending'])->count();
        }

        return $out;
    }

    /* ───────────── 시계열 ───────────── */

    /**
     * @return array<string, mixed>
     */
    public function timeseries(Filters $f, ?int $sellerId = null): array
    {
        return $this->remember($f->key('market.timeseries.'.(int) $sellerId), function () use ($f, $sellerId) {
            $range = $f->range;
            $bucket = Sql::bucket($this->dateExpr($f->basis), $range->granularity, $range->offsetSeconds());
            $byKey = [];
            foreach ($this->orders($f, $sellerId)->selectRaw("{$bucket} as k")
                ->selectRaw('COUNT(*) as orders')
                ->selectRaw('COALESCE(SUM('.Sql::wrap('mo.total_amount').'), 0) as gmv')
                ->selectRaw('COALESCE(SUM('.Sql::wrap('mo.quantity').'), 0) as quantity')
                ->selectRaw('COALESCE(SUM('.Sql::wrap('mo.commission_amount').'), 0) as commission')
                ->groupBy(DB::raw($bucket))->get() as $r) {
                $byKey[(string) $r->k] = [
                    'orders' => (int) $r->orders, 'gmv' => (int) $r->gmv,
                    'quantity' => (int) $r->quantity, 'commission' => (int) $r->commission,
                ];
            }
            $rows = $this->fillSeries($range, $byKey, ['gmv', 'orders', 'quantity', 'commission']);
            foreach ($rows as &$row) {
                $row['gmv_formatted'] = $this->money->formatMarket($row['gmv']);
                $row['commission_formatted'] = $this->money->formatMarket($row['commission']);
            }
            unset($row);

            return [
                'granularity' => $range->granularity,
                'labels' => array_column($rows, 'label'),
                'gmv' => array_column($rows, 'gmv'),
                'orders' => array_column($rows, 'orders'),
                'commission' => array_column($rows, 'commission'),
                'rows' => $rows,
            ];
        });
    }

    /* ───────────── 판매자 순위 ───────────── */

    /**
     * @return array<string, mixed>
     */
    public function sellers(Filters $f, bool $withEmail): array
    {
        return $this->remember($f->key('market.sellers.'.(int) $withEmail), function () use ($f, $withEmail) {
            $w = fn (string $c) => Sql::wrap($c);
            $agg = $this->orders($f)
                ->whereNotNull('mo.seller_id')
                ->select('mo.seller_id')
                ->selectRaw('COUNT(*) as orders')
                ->selectRaw('COALESCE(SUM('.$w('mo.total_amount').'), 0) as gmv')
                ->selectRaw('COALESCE(SUM('.$w('mo.quantity').'), 0) as qty')
                ->selectRaw('COUNT(DISTINCT '.$w('mo.buyer_id').') as buyers')
                ->selectRaw('COALESCE(SUM('.$w('mo.commission_amount').'), 0) as commission')
                ->selectRaw('COALESCE(SUM('.$w('mo.settlement_amount').'), 0) as payout')
                ->selectRaw('MAX('.$this->dateExpr($f->basis).') as last_at')
                ->groupBy('mo.seller_id');

            $cancel = $this->cancelled($f)->select('mo.seller_id')->selectRaw('COUNT(*) as c')->groupBy('mo.seller_id');
            $pending = DB::table('user_markets_orders as mo')->where('mo.settlement_status', 'pending')
                ->select('mo.seller_id')->selectRaw('COUNT(*) as c, COALESCE(SUM('.$w('mo.settlement_amount').'), 0) as amount')->groupBy('mo.seller_id');
            $onSale = DB::table('user_markets_listings as ml')->whereNull('ml.deleted_at')->where('ml.status', 'on_sale')
                ->select('ml.user_id')->selectRaw('COUNT(*) as c')->groupBy('ml.user_id');

            $q = DB::query()->fromSub($agg, 'a')
                ->join('users as u', 'u.id', '=', 'a.seller_id')
                ->leftJoin('user_markets_sellers as s', 's.user_id', '=', 'a.seller_id')
                ->leftJoinSub($cancel, 'cx', 'cx.seller_id', '=', 'a.seller_id')
                ->leftJoinSub($pending, 'px', 'px.seller_id', '=', 'a.seller_id')
                ->leftJoinSub($onSale, 'lx', 'lx.user_id', '=', 'a.seller_id');
            $hasStats = $this->hasTable('user_markets_member_stats');
            if ($hasStats) {
                $q->leftJoin('user_markets_member_stats as st', 'st.user_id', '=', 'a.seller_id');
            }

            if ($term = $f->get('q')) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(function ($x) use ($like, $withEmail) {
                    $x->where('u.nickname', 'like', $like)->orWhere('u.name', 'like', $like)->orWhere('s.display_name', 'like', $like);
                    if ($withEmail) {
                        $x->orWhere('u.email', 'like', $like);
                    }
                });
            }

            $total = (clone $q)->count();
            $totalGmv = (int) ($this->orders($f)->selectRaw('COALESCE(SUM('.$w('mo.total_amount').'), 0) as t')->value('t') ?? 0);

            $sortMap = [
                'amount' => 'a.gmv', 'orders' => 'a.orders', 'quantity' => 'a.qty', 'buyers' => 'a.buyers',
                'commission' => 'a.commission', 'settlement_pending' => 'px.amount', 'recent' => 'a.last_at',
                'rating' => $hasStats ? 'st.seller_rating' : 'a.gmv', 'cancel_rate' => 'cx.c',
            ];
            $sort = $f->get('sort', 'amount');
            $dir = $f->get('dir', 'desc');
            if ($sort === 'aov') {
                $q->orderByRaw('('.$w('a.gmv').' / NULLIF('.$w('a.orders').', 0)) '.($dir === 'asc' ? 'asc' : 'desc'));
            } else {
                $q->orderBy($sortMap[$sort] ?? 'a.gmv', $dir);
            }
            $q->orderByDesc('a.gmv')->orderBy('a.seller_id');

            $perPage = (int) $f->get('per_page', 20);
            $page = (int) $f->get('page', 1);

            $cols = ['a.*'];
            foreach (self::USER_COLS as $c) {
                $cols[] = 'u.'.$c.' as u_'.$c;
            }
            $cols = array_merge($cols, [
                's.display_name', 's.logo_path', 's.status as seller_status', 's.is_designated',
                'cx.c as cancelled', 'px.c as pending_orders', 'px.amount as pending_amount', 'lx.c as on_sale',
            ]);
            if ($hasStats) {
                $cols = array_merge($cols, ['st.seller_rating', 'st.seller_review_count']);
            }

            $rows = [];
            foreach ($q->select($cols)->forPage($page, $perPage)->get() as $r) {
                $rows[] = $this->sellerRow($r, $f, $withEmail);
            }

            return [
                'rows' => $this->rank($rows, 'amount', $totalGmv, ($page - 1) * $perPage),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'sort' => $sort,
                    'dir' => $dir,
                ],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function sellerRow(object $r, Filters $f, bool $withEmail): array
    {
        $orders = (int) $r->orders;
        $cancelled = (int) ($r->cancelled ?? 0);
        $member = Members::summary($r, 'u_', $withEmail);

        return [
            'seller_id' => (int) $r->seller_id,
            'member' => $member,
            'shop_name' => (string) ($r->display_name ?: ($member['nickname'] ?? '#'.$r->seller_id)),
            'logo_url' => $this->logoUrl((int) $r->seller_id, $r->logo_path ?? null),
            'seller_status' => $r->seller_status ?? null,
            'seller_status_label' => $r->seller_status ? __('custom-sales_stats::labels.seller_status.'.$r->seller_status) : __('custom-sales_stats::labels.seller_status.none'),
            'is_designated' => (bool) ($r->is_designated ?? false),
            'orders' => $orders,
            'quantity' => (int) $r->qty,
            'buyers' => (int) $r->buyers,
            'amount' => (int) $r->gmv,
            'amount_formatted' => $this->money->formatMarket($r->gmv),
            'aov_formatted' => $this->money->formatMarket(Stats::safeDiv($r->gmv, $orders, 0)),
            'commission_formatted' => $this->money->formatMarket($r->commission),
            'payout_formatted' => $this->money->formatMarket($r->payout),
            'pending_orders' => (int) ($r->pending_orders ?? 0),
            'pending_amount_formatted' => $this->money->formatMarket($r->pending_amount ?? 0),
            'on_sale' => (int) ($r->on_sale ?? 0),
            'rating' => round((float) ($r->seller_rating ?? 0), 1),
            'review_count' => (int) ($r->seller_review_count ?? 0),
            'cancelled' => $cancelled,
            'cancel_rate' => Stats::ratio($cancelled, $orders + $cancelled),
            'last_at' => $this->localTime($r->last_at ?? null, $f),
            'detail_url' => '/admin/sales-stats/sellers/'.(int) $r->seller_id,
            'shop_url' => '/market/members/'.(int) $r->seller_id,
        ];
    }

    public function logoUrl(int $userId, ?string $logoPath): ?string
    {
        return $logoPath ? '/api/modules/custom-user_market/shops/'.$userId.'/logo?v='.substr(md5($logoPath), 0, 8) : null;
    }

    /* ───────────── 상품 · 구매자 · 분포 ───────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listings(Filters $f, bool $withEmail, int $limit = 10, ?int $sellerId = null): array
    {
        return $this->remember($f->key('market.listings.'.$limit.'.'.(int) $sellerId.'.'.(int) $withEmail), function () use ($f, $withEmail, $limit, $sellerId) {
            $w = fn (string $c) => Sql::wrap($c);
            $agg = $this->orders($f, $sellerId)
                ->select('mo.listing_id')
                ->selectRaw('MAX('.$w('mo.listing_title').') as title, MAX('.$w('mo.listing_type').') as listing_type, MAX('.$w('mo.seller_id').') as seller_id')
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM('.$w('mo.quantity').'), 0) as qty, COALESCE(SUM('.$w('mo.total_amount').'), 0) as gmv')
                ->groupBy('mo.listing_id');

            $cols = ['a.*', 'ml.status as listing_status', 'ml.view_count', 'ml.like_count', 'ml.category', 'ml.deleted_at'];
            foreach (self::USER_COLS as $c) {
                $cols[] = 'u.'.$c.' as u_'.$c;
            }
            $total = (int) ($this->orders($f, $sellerId)->selectRaw('COALESCE(SUM('.$w('mo.total_amount').'), 0) as t')->value('t') ?? 0);
            $categories = $this->categoryLabels();

            $rows = [];
            foreach (DB::query()->fromSub($agg, 'a')
                ->leftJoin('user_markets_listings as ml', 'ml.id', '=', 'a.listing_id')
                ->leftJoin('users as u', 'u.id', '=', 'a.seller_id')
                ->select($cols)->orderByDesc('a.gmv')->limit($limit)->get() as $r) {
                $rows[] = [
                    'listing_id' => $r->listing_id ? (int) $r->listing_id : null,
                    'title' => (string) $r->title,
                    'type' => $r->listing_type,
                    'type_label' => __('custom-sales_stats::labels.listing_type.'.$r->listing_type),
                    'category' => $r->category ? ($categories[$r->category] ?? $r->category) : null,
                    'status_label' => $r->listing_status && ! $r->deleted_at ? __('custom-sales_stats::labels.listing_status.'.$r->listing_status) : __('custom-sales_stats::labels.listing_status.deleted'),
                    'orders' => (int) $r->orders,
                    'quantity' => (int) $r->qty,
                    'amount' => (int) $r->gmv,
                    'amount_formatted' => $this->money->formatMarket($r->gmv),
                    'views' => (int) ($r->view_count ?? 0),
                    'likes' => (int) ($r->like_count ?? 0),
                    'conversion' => Stats::ratio($r->orders, $r->view_count ?? 0),
                    'seller' => Members::summary($r, 'u_', $withEmail),
                    'public_url' => $r->listing_id && ! $r->deleted_at ? '/market/listings/'.(int) $r->listing_id : null,
                ];
            }

            return $this->rank($rows, 'amount', $total);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buyers(Filters $f, bool $withEmail, int $limit = 10, ?int $sellerId = null): array
    {
        return $this->remember($f->key('market.buyers.'.$limit.'.'.(int) $sellerId.'.'.(int) $withEmail), function () use ($f, $withEmail, $limit, $sellerId) {
            $w = fn (string $c) => Sql::wrap($c);
            $cols = [];
            foreach (self::USER_COLS as $c) {
                $cols[] = 'MAX('.$w('u.'.$c).') as u_'.$c;
            }
            $rows = [];
            foreach ($this->orders($f, $sellerId)->whereNotNull('mo.buyer_id')
                ->join('users as u', 'u.id', '=', 'mo.buyer_id')
                ->select('mo.buyer_id')->selectRaw(implode(', ', $cols))
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM('.$w('mo.total_amount').'), 0) as gmv')
                ->selectRaw('COUNT(DISTINCT '.$w('mo.seller_id').') as sellers')
                ->selectRaw('MAX('.$this->dateExpr($f->basis).') as last_at')
                ->groupBy('mo.buyer_id')->orderByDesc('gmv')->limit($limit)->get() as $r) {
                $rows[] = [
                    'member' => Members::summary($r, 'u_', $withEmail),
                    'orders' => (int) $r->orders,
                    'sellers' => (int) $r->sellers,
                    'amount' => (int) $r->gmv,
                    'amount_formatted' => $this->money->formatMarket($r->gmv),
                    'last_at' => $this->localTime($r->last_at, $f),
                ];
            }

            return $this->rank($rows, 'amount');
        });
    }

    /**
     * 분포 — 상품 유형 / 대금 방식 / 거래 방법 / 카테고리 / 주문 상태
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function breakdowns(Filters $f, ?int $sellerId = null): array
    {
        return $this->remember($f->key('market.breakdowns.'.(int) $sellerId), function () use ($f, $sellerId) {
            $w = fn (string $c) => Sql::wrap($c);
            $group = function (string $col, string $labelGroup) use ($f, $sellerId, $w) {
                $expr = 'COALESCE('.$w($col).", 'none')";
                $rows = [];
                foreach ($this->orders($f, $sellerId)->selectRaw("{$expr} as k, COUNT(*) as orders, COALESCE(SUM(".$w('mo.total_amount').'), 0) as gmv')
                    ->groupBy(DB::raw($expr))->orderByDesc('gmv')->get() as $r) {
                    $rows[] = $this->labelled((string) $r->k, $labelGroup, (int) $r->gmv, (int) $r->orders);
                }

                return $this->rank($rows, 'value');
            };

            $categories = $this->categoryLabels();
            $catExpr = 'COALESCE('.$w('ml.category').", 'none')";
            $catRows = [];
            foreach ($this->orders($f, $sellerId)->leftJoin('user_markets_listings as ml', 'ml.id', '=', 'mo.listing_id')
                ->selectRaw("{$catExpr} as k, COUNT(*) as orders, COALESCE(SUM(".$w('mo.total_amount').'), 0) as gmv')
                ->groupBy(DB::raw($catExpr))->orderByDesc('gmv')->get() as $r) {
                $key = (string) $r->k;
                $catRows[] = [
                    'key' => $key,
                    'label' => $key === 'none' ? __('custom-sales_stats::labels.uncategorized') : ($categories[$key] ?? $key),
                    'value' => (int) $r->gmv,
                    'orders' => (int) $r->orders,
                    'amount_formatted' => $this->money->formatMarket($r->gmv),
                ];
            }

            $statusQ = DB::table('user_markets_orders as mo');
            Sql::whereBetweenRaw($statusQ, $w('mo.created_at'), $f->range);
            $this->applyFilters($statusQ, $f, $sellerId);
            $counts = $statusQ->selectRaw($w('mo.status').' as s, COUNT(*) as c')->groupBy('mo.status')->pluck('c', 's')->all();
            $statusRows = [];
            foreach (self::ALL_STATUSES as $s) {
                if (! empty($counts[$s])) {
                    $statusRows[] = ['key' => $s, 'label' => __('custom-sales_stats::labels.market_status.'.$s), 'value' => (int) $counts[$s]];
                }
            }

            return [
                'types' => $group('mo.listing_type', 'listing_type'),
                'payment_modes' => $group('mo.payment_mode', 'payment_mode'),
                'trade_methods' => $group('mo.trade_method', 'trade_method'),
                'categories' => $this->rank($catRows, 'value'),
                'statuses' => $this->rank($statusRows, 'value'),
            ];
        });
    }

    /**
     * 정산 대기 — 판매자별 (지금 시점)
     *
     * @return array<int, array<string, mixed>>
     */
    public function settlements(Filters $f, bool $withEmail, int $limit = 20): array
    {
        return $this->remember($f->key('market.settlements.'.$limit.'.'.(int) $withEmail), function () use ($f, $withEmail, $limit) {
            $w = fn (string $c) => Sql::wrap($c);
            $cols = [];
            foreach (self::USER_COLS as $c) {
                $cols[] = 'MAX('.$w('u.'.$c).') as u_'.$c;
            }
            $rows = [];
            foreach (DB::table('user_markets_orders as mo')->where('mo.settlement_status', 'pending')->whereNotNull('mo.seller_id')
                ->join('users as u', 'u.id', '=', 'mo.seller_id')
                ->leftJoin('user_markets_sellers as s', 's.user_id', '=', 'mo.seller_id')
                ->select('mo.seller_id')->selectRaw(implode(', ', $cols))
                ->selectRaw('MAX('.$w('s.display_name').') as display_name')
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM('.$w('mo.settlement_amount').'), 0) as amount, COALESCE(SUM('.$w('mo.commission_amount').'), 0) as commission')
                ->selectRaw('MIN('.$w('mo.completed_at').') as oldest')
                ->groupBy('mo.seller_id')->orderByDesc('amount')->limit($limit)->get() as $r) {
                $member = Members::summary($r, 'u_', $withEmail);
                $rows[] = [
                    'seller_id' => (int) $r->seller_id,
                    'member' => $member,
                    'shop_name' => (string) ($r->display_name ?: ($member['nickname'] ?? '')),
                    'orders' => (int) $r->orders,
                    'amount' => (int) $r->amount,
                    'amount_formatted' => $this->money->formatMarket($r->amount),
                    'commission_formatted' => $this->money->formatMarket($r->commission),
                    'oldest' => $this->localTime($r->oldest, $f, 'Y-m-d'),
                    'detail_url' => '/admin/sales-stats/sellers/'.(int) $r->seller_id,
                ];
            }

            return $rows;
        });
    }

    /* ───────────── 판매자 상세 ───────────── */

    /**
     * @return array<string, mixed>|null
     */
    public function sellerProfile(int $userId, Filters $f, bool $withEmail): ?array
    {
        $cols = [];
        foreach (self::USER_COLS as $c) {
            $cols[] = 'u.'.$c.' as u_'.$c;
        }
        $cols = array_merge($cols, ['u.created_at as u_created_at', 's.id as seller_row', 's.display_name', 's.intro', 's.logo_path', 's.status as seller_status', 's.is_designated', 's.approved_at', 's.status_reason']);
        $q = DB::table('users as u')->leftJoin('user_markets_sellers as s', 's.user_id', '=', 'u.id')->where('u.id', $userId);
        if ($this->hasTable('user_markets_member_stats')) {
            $q->leftJoin('user_markets_member_stats as st', 'st.user_id', '=', 'u.id');
            $cols = array_merge($cols, ['st.seller_rating', 'st.seller_review_count', 'st.sales_count', 'st.buyer_rating', 'st.purchase_count']);
        }
        $r = $q->select($cols)->first();
        if (! $r) {
            return null;
        }
        $hasOrders = DB::table('user_markets_orders')->where('seller_id', $userId)->exists();
        if (! $r->seller_row && ! $hasOrders) {
            return null;
        }

        $member = Members::summary($r, 'u_', $withEmail);
        $settledAll = DB::table('user_markets_orders')->where('seller_id', $userId)->where('settlement_status', 'done')
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(settlement_amount), 0) as amount, MAX(settled_at) as last_at')->first();
        $reports = 0;
        if ($this->hasTable('user_markets_reports')) {
            $reports = (int) DB::table('user_markets_reports as rp')
                ->join('user_markets_listings as ml', 'ml.id', '=', 'rp.listing_id')
                ->where('ml.user_id', $userId)->where('rp.status', 'pending')->count();
        }

        return [
            'seller_id' => $userId,
            'member' => $member,
            'shop_name' => (string) ($r->display_name ?: ($member['nickname'] ?? '#'.$userId)),
            'intro' => $r->intro ? mb_strimwidth((string) $r->intro, 0, 200, '…') : null,
            'logo_url' => $this->logoUrl($userId, $r->logo_path ?? null),
            'seller_status' => $r->seller_status,
            'seller_status_label' => $r->seller_status ? __('custom-sales_stats::labels.seller_status.'.$r->seller_status) : __('custom-sales_stats::labels.seller_status.none'),
            'status_reason' => $r->status_reason,
            'is_designated' => (bool) ($r->is_designated ?? false),
            'joined_at' => $this->localTime($r->u_created_at, $f, 'Y-m-d'),
            'approved_at' => $this->localTime($r->approved_at, $f, 'Y-m-d'),
            'rating' => round((float) ($r->seller_rating ?? 0), 1),
            'review_count' => (int) ($r->seller_review_count ?? 0),
            'sales_count' => (int) ($r->sales_count ?? 0),
            'settled_total_formatted' => $this->money->formatMarket($settledAll->amount ?? 0),
            'settled_total_orders' => (int) ($settledAll->c ?? 0),
            'last_settled_at' => $this->localTime($settledAll->last_at ?? null, $f, 'Y-m-d'),
            'pending_reports' => $reports,
            'shop_url' => '/market/members/'.$userId,
        ];
    }

    /**
     * 판매자 주문 목록 (주문일 기준, 모든 상태)
     *
     * @return array<string, mixed>
     */
    public function sellerOrders(int $userId, Filters $f, bool $withEmail): array
    {
        $cols = ['mo.id', 'mo.order_no', 'mo.listing_title', 'mo.listing_type', 'mo.total_amount', 'mo.commission_amount', 'mo.settlement_amount', 'mo.status', 'mo.settlement_status', 'mo.payment_mode', 'mo.created_at', 'mo.paid_at'];
        foreach (self::USER_COLS as $c) {
            $cols[] = 'u.'.$c.' as u_'.$c;
        }
        $q = DB::table('user_markets_orders as mo')->where('mo.seller_id', $userId)
            ->leftJoin('users as u', 'u.id', '=', 'mo.buyer_id');
        Sql::whereBetweenRaw($q, Sql::wrap('mo.created_at'), $f->range);
        $this->applyFilters($q, $f, null);

        $total = (clone $q)->count();
        $perPage = (int) $f->get('per_page', 20);
        $page = (int) $f->get('page', 1);

        $rows = [];
        foreach ($q->select($cols)->orderByDesc('mo.id')->forPage($page, $perPage)->get() as $r) {
            $rows[] = [
                'id' => (int) $r->id,
                'order_no' => $r->order_no,
                'title' => $r->listing_title,
                'type_label' => __('custom-sales_stats::labels.listing_type.'.$r->listing_type),
                'buyer' => Members::summary($r, 'u_', $withEmail),
                'amount_formatted' => $this->money->formatMarket($r->total_amount),
                'commission_formatted' => $this->money->formatMarket($r->commission_amount),
                'payout_formatted' => $this->money->formatMarket($r->settlement_amount),
                'status' => $r->status,
                'status_label' => __('custom-sales_stats::labels.market_status.'.$r->status),
                'settlement_label' => __('custom-sales_stats::labels.settlement_status.'.($r->settlement_status ?: 'none')),
                'payment_mode_label' => __('custom-sales_stats::labels.payment_mode.'.$r->payment_mode),
                'created_at' => $this->localTime($r->created_at, $f),
                'admin_url' => '/admin/user-markets/orders/'.(int) $r->id,
            ];
        }

        return [
            'rows' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /**
     * 판매자가 받은 최근 후기
     *
     * @return array<int, array<string, mixed>>
     */
    public function sellerReviews(int $userId, Filters $f, bool $withEmail, int $limit = 8): array
    {
        if (! $this->hasTable('user_markets_reviews')) {
            return [];
        }
        $cols = ['rv.id', 'rv.rating', 'rv.content', 'rv.is_hidden', 'rv.created_at'];
        foreach (self::USER_COLS as $c) {
            $cols[] = 'u.'.$c.' as u_'.$c;
        }
        $rows = [];
        foreach (DB::table('user_markets_reviews as rv')->where('rv.reviewee_id', $userId)->where('rv.direction', 'to_seller')
            ->leftJoin('users as u', 'u.id', '=', 'rv.reviewer_id')
            ->select($cols)->orderByDesc('rv.id')->limit($limit)->get() as $r) {
            $rating = max(0, min(5, (int) $r->rating));
            $rows[] = [
                'id' => (int) $r->id,
                'rating' => $rating,
                'stars' => str_repeat('★', $rating).str_repeat('☆', 5 - $rating),
                'content' => mb_strimwidth((string) $r->content, 0, 140, '…'),
                'is_hidden' => (bool) $r->is_hidden,
                'reviewer' => Members::summary($r, 'u_', $withEmail),
                'created_at' => $this->localTime($r->created_at, $f, 'Y-m-d'),
            ];
        }

        return $rows;
    }

    /**
     * CSV 내보내기용 전체 행
     *
     * @return array{0: array<int, string>, 1: iterable<int, array<int, mixed>>}
     */
    public function exportRows(Filters $f, string $dataset, bool $withEmail, ?int $sellerId = null): array
    {
        $t = fn (string $k) => __('custom-sales_stats::labels.csv.'.$k);

        switch ($dataset) {
            case 'sellers':
                $all = [];
                $page = 1;
                do {
                    $chunk = $this->sellers($f->with(['page' => $page, 'per_page' => 100]), $withEmail);
                    foreach ($chunk['rows'] as $r) {
                        $all[] = [$r['rank'], $r['seller_id'], $r['shop_name'], $r['member']['nickname'] ?? '', $r['member']['email'] ?? '', $r['seller_status_label'], $r['orders'], $r['quantity'], $r['buyers'], $r['amount'], $r['share'], $r['commission_formatted'], $r['payout_formatted'], $r['pending_amount_formatted'], $r['rating'], $r['cancel_rate']];
                    }
                    $page++;
                } while ($page <= $chunk['meta']['last_page'] && $page <= 100);

                return [[$t('rank'), $t('member_id'), $t('shop'), $t('nickname'), $t('email'), $t('seller_status'), $t('orders'), $t('quantity'), $t('buyers'), $t('amount').' (KRW)', $t('share'), $t('commission'), $t('payout'), $t('settlement_pending'), $t('rating'), $t('cancel_rate')], $all];
            case 'listings':
                return [
                    [$t('rank'), $t('listing_id'), $t('listing'), $t('type'), $t('category'), $t('seller'), $t('orders'), $t('quantity'), $t('amount').' (KRW)', $t('views'), $t('share')],
                    array_map(fn ($r) => [$r['rank'], $r['listing_id'], $r['title'], $r['type_label'], $r['category'], $r['seller']['nickname'] ?? '', $r['orders'], $r['quantity'], $r['amount'], $r['views'], $r['share']], $this->listings($f, $withEmail, 500, $sellerId)),
                ];
            case 'buyers':
                return [
                    [$t('rank'), $t('member_id'), $t('nickname'), $t('email'), $t('orders'), $t('amount').' (KRW)', $t('last_at')],
                    array_map(fn ($r) => [$r['rank'], $r['member']['id'] ?? '', $r['member']['nickname'] ?? '', $r['member']['email'] ?? '', $r['orders'], $r['amount'], $r['last_at']], $this->buyers($f, $withEmail, 500, $sellerId)),
                ];
            case 'settlements':
                return [
                    [$t('member_id'), $t('shop'), $t('nickname'), $t('orders'), $t('settlement_pending'), $t('commission'), $t('oldest')],
                    array_map(fn ($r) => [$r['seller_id'], $r['shop_name'], $r['member']['nickname'] ?? '', $r['orders'], $r['amount'], $r['commission_formatted'], $r['oldest']], $this->settlements($f, $withEmail, 500)),
                ];
            case 'seller_orders':
                $rows = [];
                if ($sellerId !== null) {
                    $page = 1;
                    do {
                        $chunk = $this->sellerOrders($sellerId, $f->with(['page' => $page, 'per_page' => 100]), $withEmail);
                        foreach ($chunk['rows'] as $r) {
                            $rows[] = [$r['order_no'], $r['created_at'], $r['title'], $r['type_label'], $r['buyer']['nickname'] ?? '', $r['amount_formatted'], $r['commission_formatted'], $r['payout_formatted'], $r['status_label'], $r['settlement_label']];
                        }
                        $page++;
                    } while ($page <= $chunk['meta']['last_page'] && $page <= 100);
                }

                return [[$t('order_no'), $t('ordered_at'), $t('listing'), $t('type'), $t('buyer'), $t('amount'), $t('commission'), $t('payout'), $t('status'), $t('settlement')], $rows];
            default:
                return [
                    [$t('period'), $t('orders'), $t('quantity'), $t('amount').' (KRW)', $t('commission').' (KRW)'],
                    array_map(fn ($r) => [$r['period'], $r['orders'], $r['quantity'], $r['gmv'], $r['commission']], $this->timeseries($f, $sellerId)['rows']),
                ];
        }
    }

    /**
     * 마켓 환경설정의 카테고리 이름 (읽기 전용)
     *
     * @return array<string, string>
     */
    public function categoryLabels(): array
    {
        $list = [];
        try {
            if (function_exists('module_setting')) {
                $list = module_setting('custom-user_market', 'general.categories', []) ?: [];
            }
        } catch (\Throwable) {
            $list = [];
        }
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_array($item) && isset($item['key'])) {
                $out[(string) $item['key']] = Stats::localeName($item['name'] ?? null, (string) $item['key']);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function labelled(string $key, string $group, int $gmv, int $orders): array
    {
        $label = __('custom-sales_stats::labels.'.$group.'.'.$key);

        return [
            'key' => $key,
            'label' => str_starts_with($label, 'custom-sales_stats::') ? $key : $label,
            'value' => $gmv,
            'orders' => $orders,
            'amount_formatted' => $this->money->formatMarket($gmv),
        ];
    }
}
