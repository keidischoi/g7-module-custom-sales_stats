<?php

namespace Modules\Custom\SalesStats\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Custom\SalesStats\Http\Requests\StatsFilterRequest;
use Modules\Custom\SalesStats\Services\EcommerceStatsService;
use Modules\Custom\SalesStats\Services\MarketStatsService;
use Modules\Custom\SalesStats\Support\Availability;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV 내보내기 (UTF-8 BOM, 엑셀 호환) — 권한: custom-sales_stats.stats.export
 *
 * scope=ecommerce: dataset = period | products | categories | buyers
 * scope=market:    dataset = period | sellers | listings | buyers | settlements | seller_orders (seller 필요)
 */
class ExportController extends AdminBaseController
{
    private const DATASETS = [
        'ecommerce' => ['period', 'products', 'categories', 'buyers'],
        'market' => ['period', 'sellers', 'listings', 'buyers', 'settlements', 'seller_orders'],
    ];

    public function __construct(
        private Availability $availability,
        private EcommerceStatsService $ecommerce,
        private MarketStatsService $market,
    ) {
        parent::__construct();
    }

    public function export(StatsFilterRequest $request): StreamedResponse|JsonResponse
    {
        $scope = (string) $request->input('scope', 'ecommerce');
        $dataset = (string) $request->input('dataset', 'period');
        if (! in_array($dataset, self::DATASETS[$scope] ?? [], true)) {
            return $this->error('custom-sales_stats::messages.invalid_dataset', 422);
        }
        if ($scope === 'ecommerce' && ! $this->availability->ecommerce()) {
            return $this->error('custom-sales_stats::messages.ecommerce_unavailable', 404);
        }
        if ($scope === 'market' && ! $this->availability->market()) {
            return $this->error('custom-sales_stats::messages.market_unavailable', 404);
        }

        $f = $request->filters();
        $withEmail = $this->availability->canViewMembers($request->user());
        $seller = $request->filled('seller') ? (int) $request->input('seller') : null;

        [$header, $rows] = $scope === 'market'
            ? $this->market->exportRows($f, $dataset, $withEmail, $seller)
            : $this->ecommerce->exportRows($f, $dataset, $withEmail);

        $filename = sprintf('sales_stats_%s_%s%s_%s_%s.csv', $scope, $dataset, $seller ? '_'.$seller : '', $f->range->from->format('Ymd'), $f->range->to->format('Ymd'));

        return response()->streamDownload(function () use ($header, $rows) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, $header);
            foreach ($rows as $row) {
                fputcsv($h, array_map(fn ($v) => self::cell($v), $row));
            }
            fclose($h);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** CSV 수식 주입 방지 (=, +, -, @ 로 시작하는 문자열) */
    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }
}
