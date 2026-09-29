<?php

namespace Modules\Custom\SalesStats\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Custom\SalesStats\Http\Requests\StatsFilterRequest;
use Modules\Custom\SalesStats\Module;
use Modules\Custom\SalesStats\Services\EcommerceStatsService;
use Modules\Custom\SalesStats\Services\MarketStatsService;
use Modules\Custom\SalesStats\Services\OverviewStatsService;
use Modules\Custom\SalesStats\Support\Availability;
use Modules\Custom\SalesStats\Support\Filters;
use Modules\Custom\SalesStats\Support\PeriodRange;
use Modules\Custom\SalesStats\Support\Money;

/**
 * 판매 통계 관리자 API (읽기 전용)
 *
 * prefix: /api/modules/custom-sales_stats  (권한: custom-sales_stats.stats.view)
 */
class StatsController extends AdminBaseController
{
    public function __construct(
        private Availability $availability,
        private EcommerceStatsService $ecommerce,
        private MarketStatsService $market,
        private OverviewStatsService $overview,
        private Money $money,
    ) {
        parent::__construct();
    }

    /** 화면 구성 정보 — 사용 가능한 탭, 통화, 시간대, 기본 기간, 마켓 카테고리 */
    public function meta(StatsFilterRequest $request): JsonResponse
    {
        $f = $request->filters();
        $user = $request->user();
        $available = $this->availability->toArray();

        return $this->success('common.success', [
            'available' => $available,
            'tabs' => array_values(array_filter([
                ['id' => 'overview', 'label' => __('custom-sales_stats::labels.tabs.overview')],
                $available['ecommerce'] ? ['id' => 'ecommerce', 'label' => __('custom-sales_stats::labels.tabs.ecommerce')] : null,
                $available['market'] ? ['id' => 'market', 'label' => __('custom-sales_stats::labels.tabs.market')] : null,
            ])),
            'filters' => $f->toArray(),
            'presets' => array_map(fn (array $p) => $p + ['label' => __('custom-sales_stats::labels.presets.'.$p['key'])], PeriodRange::presets($f->range->timezone)),
            'currency' => [
                'ecommerce' => $this->money->ecommerceCurrency(),
                'market' => $this->money->marketCurrency(),
            ],
            'market_categories' => $available['market'] ? $this->categoryOptions() : [],
            'can' => [
                'export' => $this->can($user, Module::ID.'.stats.export'),
                'view_members' => $this->availability->canViewMembers($user),
            ],
        ]);
    }

    public function overview(StatsFilterRequest $request): JsonResponse
    {
        return $this->run(fn () => $this->overview->overview($request->filters(), $this->withEmail($request)));
    }

    /* ───── 이커머스 ───── */

    public function ecommerceSummary(StatsFilterRequest $request): JsonResponse
    {
        return $this->ecommerceRun(fn (Filters $f) => $this->ecommerce->summary($f) + ['filters' => $f->toArray()], $request);
    }

    public function ecommerceTimeseries(StatsFilterRequest $request): JsonResponse
    {
        return $this->ecommerceRun(fn (Filters $f) => $this->ecommerce->timeseries($f), $request);
    }

    public function ecommerceProducts(StatsFilterRequest $request): JsonResponse
    {
        return $this->ecommerceRun(fn (Filters $f) => ['rows' => $this->ecommerce->products($f, (int) $f->get('limit', 10))], $request);
    }

    public function ecommerceCategories(StatsFilterRequest $request): JsonResponse
    {
        return $this->ecommerceRun(fn (Filters $f) => ['rows' => $this->ecommerce->categories($f, (int) min(20, $f->get('limit', 10)))], $request);
    }

    public function ecommerceBuyers(StatsFilterRequest $request): JsonResponse
    {
        return $this->ecommerceRun(fn (Filters $f) => $this->ecommerce->buyers($f, $this->withEmail($request), (int) $f->get('limit', 10)), $request);
    }

    public function ecommerceBreakdowns(StatsFilterRequest $request): JsonResponse
    {
        return $this->ecommerceRun(fn (Filters $f) => [
            'statuses' => $this->ecommerce->statuses($f),
            'payment_methods' => $this->ecommerce->paymentMethods($f),
            'devices' => $this->ecommerce->devices($f),
            'top_orders' => $this->ecommerce->topOrders($f, $this->withEmail($request), 8),
        ], $request);
    }

    /* ───── 개인 마켓 ───── */

    public function marketSummary(StatsFilterRequest $request): JsonResponse
    {
        return $this->marketRun(fn (Filters $f) => $this->market->summary($f) + ['filters' => $f->toArray()], $request);
    }

    public function marketTimeseries(StatsFilterRequest $request): JsonResponse
    {
        return $this->marketRun(fn (Filters $f) => $this->market->timeseries($f), $request);
    }

    public function marketSellers(StatsFilterRequest $request): JsonResponse
    {
        return $this->marketRun(fn (Filters $f) => $this->market->sellers($f, $this->withEmail($request)), $request);
    }

    public function marketListings(StatsFilterRequest $request): JsonResponse
    {
        return $this->marketRun(fn (Filters $f) => ['rows' => $this->market->listings($f, $this->withEmail($request), (int) $f->get('limit', 10))], $request);
    }

    public function marketBuyers(StatsFilterRequest $request): JsonResponse
    {
        return $this->marketRun(fn (Filters $f) => ['rows' => $this->market->buyers($f, $this->withEmail($request), (int) $f->get('limit', 10))], $request);
    }

    public function marketBreakdowns(StatsFilterRequest $request): JsonResponse
    {
        return $this->marketRun(fn (Filters $f) => $this->market->breakdowns($f), $request);
    }

    public function marketSettlements(StatsFilterRequest $request): JsonResponse
    {
        return $this->marketRun(fn (Filters $f) => [
            'rows' => $this->market->settlements($f, $this->withEmail($request), (int) min(50, $f->get('limit', 10))),
            'snapshot' => $this->market->snapshot(),
        ], $request);
    }

    /** 판매자 상세 (프로필 + 기간 KPI + 추이 + 상품 + 구매자 + 분포 + 후기) */
    public function seller(StatsFilterRequest $request, int $userId): JsonResponse
    {
        return $this->marketRun(function (Filters $f) use ($request, $userId) {
            $withEmail = $this->withEmail($request);
            $profile = $this->market->sellerProfile($userId, $f, $withEmail);
            if ($profile === null) {
                return null;
            }

            return [
                'profile' => $profile,
                'summary' => $this->market->summary($f, $userId),
                'timeseries' => $this->market->timeseries($f, $userId),
                'listings' => $this->market->listings($f, $withEmail, 10, $userId),
                'buyers' => $this->market->buyers($f, $withEmail, 10, $userId),
                'breakdowns' => $this->market->breakdowns($f, $userId),
                'reviews' => $this->market->sellerReviews($userId, $f, $withEmail),
                'filters' => $f->toArray(),
            ];
        }, $request);
    }

    public function sellerOrders(StatsFilterRequest $request, int $userId): JsonResponse
    {
        return $this->marketRun(fn (Filters $f) => $this->market->sellerOrders($userId, $f, $this->withEmail($request)), $request);
    }

    /* ───── 공통 ───── */

    private function ecommerceRun(\Closure $fn, StatsFilterRequest $request): JsonResponse
    {
        if (! $this->availability->ecommerce()) {
            return $this->error('custom-sales_stats::messages.ecommerce_unavailable', 404);
        }

        return $this->run(fn () => $fn($request->filters()));
    }

    private function marketRun(\Closure $fn, StatsFilterRequest $request): JsonResponse
    {
        if (! $this->availability->market()) {
            return $this->error('custom-sales_stats::messages.market_unavailable', 404);
        }

        return $this->run(fn () => $fn($request->filters()));
    }

    private function run(\Closure $fn): JsonResponse
    {
        try {
            $data = $fn();
            if ($data === null) {
                return $this->error('custom-sales_stats::messages.not_found', 404);
            }

            return $this->success('common.success', $data);
        } catch (\Throwable $e) {
            Log::error('[custom-sales_stats] '.$e->getMessage(), ['exception' => get_class($e), 'at' => $e->getFile().':'.$e->getLine()]);

            return $this->error('custom-sales_stats::messages.query_failed', 500, $e);
        }
    }

    private function withEmail(StatsFilterRequest $request): bool
    {
        return $this->availability->canViewMembers($request->user());
    }

    private function can(mixed $user, string $permission): bool
    {
        try {
            return $user !== null && $user->hasPermission($permission, \App\Enums\PermissionType::Admin);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function categoryOptions(): array
    {
        $out = [];
        foreach ($this->market->categoryLabels() as $key => $label) {
            $out[] = ['value' => $key, 'label' => $label];
        }

        return $out;
    }
}
