<?php

use Illuminate\Support\Facades\Route;
use Modules\Custom\SalesStats\Http\Controllers\Admin\ExportController;
use Modules\Custom\SalesStats\Http\Controllers\Admin\StatsController;

/*
|--------------------------------------------------------------------------
| 판매 통계 API (관리자 전용 · 읽기 전용)
|--------------------------------------------------------------------------
|
| ModuleRouteServiceProvider 가 prefix 를 자동 적용합니다.
| - URL prefix: 'api/modules/custom-sales_stats'
| - Name prefix: 'api.modules.custom-sales_stats.'
|
| 모든 라우트: auth:sanctum + admin + permission:admin,custom-sales_stats.stats.view
| 내보내기는 추가로 custom-sales_stats.stats.export 권한이 필요합니다.
|
*/

$view = 'permission:admin,custom-sales_stats.stats.view';
$export = 'permission:admin,custom-sales_stats.stats.export';

Route::middleware(['auth:sanctum', 'admin', 'throttle:120,1', $view])->group(function () use ($export) {
    Route::get('meta', [StatsController::class, 'meta'])->name('meta');
    Route::get('overview', [StatsController::class, 'overview'])->name('overview');

    Route::prefix('ecommerce')->name('ecommerce.')->group(function () {
        Route::get('summary', [StatsController::class, 'ecommerceSummary'])->name('summary');
        Route::get('timeseries', [StatsController::class, 'ecommerceTimeseries'])->name('timeseries');
        Route::get('products', [StatsController::class, 'ecommerceProducts'])->name('products');
        Route::get('categories', [StatsController::class, 'ecommerceCategories'])->name('categories');
        Route::get('buyers', [StatsController::class, 'ecommerceBuyers'])->name('buyers');
        Route::get('breakdowns', [StatsController::class, 'ecommerceBreakdowns'])->name('breakdowns');
    });

    Route::prefix('market')->name('market.')->group(function () {
        Route::get('summary', [StatsController::class, 'marketSummary'])->name('summary');
        Route::get('timeseries', [StatsController::class, 'marketTimeseries'])->name('timeseries');
        Route::get('sellers', [StatsController::class, 'marketSellers'])->name('sellers');
        Route::get('sellers/{userId}', [StatsController::class, 'seller'])->whereNumber('userId')->name('sellers.show');
        Route::get('sellers/{userId}/orders', [StatsController::class, 'sellerOrders'])->whereNumber('userId')->name('sellers.orders');
        Route::get('listings', [StatsController::class, 'marketListings'])->name('listings');
        Route::get('buyers', [StatsController::class, 'marketBuyers'])->name('buyers');
        Route::get('breakdowns', [StatsController::class, 'marketBreakdowns'])->name('breakdowns');
        Route::get('settlements', [StatsController::class, 'marketSettlements'])->name('settlements');
    });

    Route::get('export', [ExportController::class, 'export'])->middleware([$export, 'throttle:20,1'])->name('export');
});
