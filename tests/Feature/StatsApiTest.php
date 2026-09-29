<?php

namespace Modules\Custom\SalesStats\Tests\Feature;

require_once dirname(__DIR__).'/ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Custom\SalesStats\Tests\FakeAvailability;
use Modules\Custom\SalesStats\Tests\ModuleTestCase;

/**
 * 판매 통계 API — 권한, 기간(KST) 경계, 취소·환불 제외, 카테고리 중복 제거, 판매자 순위·상세, CSV, 비활성 모듈
 */
class StatsApiTest extends ModuleTestCase
{
    /* ───── 권한 ───── */

    public function test_guest_is_rejected(): void
    {
        $this->getJson($this->api('meta'))->assertStatus(401);
    }

    public function test_admin_without_view_permission_gets_403(): void
    {
        $this->actingAs($this->adminWith([]))->getJson($this->api('meta'))->assertStatus(403);
        $this->actingAs($this->adminWith([]))->getJson($this->api('ecommerce/summary'))->assertStatus(403);
    }

    public function test_non_admin_user_gets_403(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson($this->api('meta'))->assertStatus(403);
    }

    public function test_meta_lists_tabs_presets_and_permissions(): void
    {
        $res = $this->actingAs($this->viewer())->getJson($this->api('meta'))->assertOk();
        $this->assertSame(['overview', 'ecommerce', 'market'], array_column($res->json('data.tabs'), 'id'));
        $this->assertNotEmpty($res->json('data.presets'));
        $this->assertFalse($res->json('data.can.export'));
        $this->assertSame('Asia/Seoul', $res->json('data.filters.timezone'));
    }

    public function test_export_requires_export_permission(): void
    {
        $this->actingAs($this->viewer())->get($this->api('export', ['scope' => 'ecommerce', 'dataset' => 'period']))->assertStatus(403);
    }

    /* ───── 비활성 모듈 ───── */

    public function test_inactive_modules_return_404_and_hide_tabs(): void
    {
        FakeAvailability::$active = ['sirsoft-ecommerce' => false, 'custom-user_market' => false];
        $user = $this->viewer();
        $this->actingAs($user)->getJson($this->api('ecommerce/summary'))->assertStatus(404);
        $this->actingAs($user)->getJson($this->api('market/sellers'))->assertStatus(404);
        $this->actingAs($user)->get($this->api('export', ['scope' => 'market', 'dataset' => 'sellers']))->assertStatus(403);
        $tabs = $this->actingAs($user)->getJson($this->api('meta'))->assertOk()->json('data.tabs');
        $this->assertSame(['overview'], array_column($tabs, 'id'));
        $this->actingAs($user)->getJson($this->api('overview'))->assertOk();
    }

    /* ───── 기간 경계 (KST) ───── */

    public function test_kst_day_boundaries_are_inclusive_and_bucketed_in_admin_timezone(): void
    {
        // 2026-09-01 00:10 KST (= 08-31 15:10 UTC) → 포함, 09-01 버킷
        $this->ecommerceOrder(['paid_at' => $this->utc('2026-09-01 00:10:00'), 'total_amount' => 1000]);
        // 2026-08-31 23:50 KST → 제외
        $this->ecommerceOrder(['paid_at' => $this->utc('2026-08-31 23:50:00'), 'total_amount' => 7000]);
        // 2026-09-02 23:59 KST → 포함 (종료일 포함), 09-02 버킷
        $this->ecommerceOrder(['paid_at' => $this->utc('2026-09-02 23:59:00'), 'total_amount' => 2000]);
        // 2026-09-03 00:00 KST → 제외
        $this->ecommerceOrder(['paid_at' => $this->utc('2026-09-03 00:00:00'), 'total_amount' => 9000]);

        $user = $this->viewer();
        $q = $this->period('2026-09-01', '2026-09-02', ['granularity' => 'day']);
        $sum = $this->actingAs($user)->getJson($this->api('ecommerce/summary', $q))->assertOk();
        $this->assertEquals(3000, $sum->json('data.raw.sales'));
        $this->assertEquals(2, $sum->json('data.raw.orders'));

        $ts = $this->actingAs($user)->getJson($this->api('ecommerce/timeseries', $q))->assertOk();
        $this->assertSame(['2026-09-01', '2026-09-02'], array_column($ts->json('data.rows'), 'period'));
        $this->assertEquals([1000, 2000], $ts->json('data.sales'));
    }

    public function test_previous_period_comparison(): void
    {
        $this->ecommerceOrder(['paid_at' => $this->utc('2026-09-05 12:00:00'), 'total_amount' => 3000]);
        $this->ecommerceOrder(['paid_at' => $this->utc('2026-09-02 12:00:00'), 'total_amount' => 1000]);
        $res = $this->actingAs($this->viewer())
            ->getJson($this->api('ecommerce/summary', ['from' => '2026-09-04', 'to' => '2026-09-06', 'compare' => '1']))->assertOk();
        $this->assertEquals(3000, $res->json('data.kpis.sales.value'));
        $this->assertEquals(1000, $res->json('data.kpis.sales.previous'));
        $this->assertEquals(200.0, $res->json('data.kpis.sales.change'));
        $this->assertSame('up', $res->json('data.kpis.sales.trend'));
    }

    /* ───── 취소·환불 ───── */

    public function test_cancelled_orders_options_and_child_options_are_excluded(): void
    {
        $at = $this->utc('2026-09-10 12:00:00');
        $this->ecommerceOrder(['paid_at' => $at, 'total_amount' => 5000], [
            ['product_id' => 11, 'quantity' => 2, 'subtotal_price' => 5000, 'subtotal_discount_amount' => 1000],
            ['product_id' => 11, 'quantity' => 3, 'subtotal_price' => 0, 'parent_option_id' => 1],
            ['product_id' => 12, 'quantity' => 1, 'subtotal_price' => 4000, 'option_status' => 'cancelled'],
        ]);
        $this->ecommerceOrder(['paid_at' => $at, 'order_status' => 'cancelled', 'total_amount' => 0], [
            ['product_id' => 13, 'quantity' => 1, 'subtotal_price' => 9000],
        ]);
        $this->ecommerceOrder(['paid_at' => $at, 'order_status' => 'pending_payment', 'total_amount' => 8000]);

        $user = $this->viewer();
        $q = $this->period('2026-09-10', '2026-09-10');
        $raw = $this->actingAs($user)->getJson($this->api('ecommerce/summary', $q))->assertOk()->json('data.raw');
        $this->assertEquals(5000, $raw['sales']);
        $this->assertEquals(1, $raw['orders']);
        $this->assertEquals(2, $raw['items']);

        $rows = $this->actingAs($user)->getJson($this->api('ecommerce/products', $q))->assertOk()->json('data.rows');
        $this->assertSame([11], array_column($rows, 'product_id'));
        $this->assertEquals(4000, $rows[0]['amount']);
        $this->assertEquals(2, $rows[0]['quantity']);
    }

    /* ───── 카테고리 중복 제거 ───── */

    public function test_categories_count_each_product_once_under_primary_category(): void
    {
        $catA = $this->insertRow('ecommerce_categories', ['name' => json_encode(['ko' => 'A', 'en' => 'A']), 'slug' => 'a-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $catB = $this->insertRow('ecommerce_categories', ['name' => json_encode(['ko' => 'B', 'en' => 'B']), 'slug' => 'b-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $pid = $this->insertRow('ecommerce_products', ['product_code' => 'P-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->insertRow('ecommerce_product_categories', ['product_id' => $pid, 'category_id' => $catA, 'is_primary' => 0]);
        $this->insertRow('ecommerce_product_categories', ['product_id' => $pid, 'category_id' => $catB, 'is_primary' => 1]);
        $this->ecommerceOrder(['paid_at' => $this->utc('2026-09-10 12:00:00'), 'total_amount' => 6000], [
            ['product_id' => $pid, 'quantity' => 1, 'subtotal_price' => 6000],
        ]);

        $rows = $this->actingAs($this->viewer())
            ->getJson($this->api('ecommerce/categories', $this->period('2026-09-10', '2026-09-10')))->assertOk()->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame($catB, $rows[0]['category_id']);
        $this->assertEquals(6000, $rows[0]['amount']);
        $this->assertEquals(100.0, $rows[0]['share']);
    }

    /* ───── 개인마켓: 판매자 순위 · 상세 ───── */

    public function test_market_seller_ranking_detail_and_orders(): void
    {
        $s1 = User::factory()->create(['nickname' => '가게주인']);
        $s2 = User::factory()->create(['nickname' => '둘째']);
        $buyer = User::factory()->create();
        $this->marketSeller($s1, ['display_name' => '첫가게']);
        $this->marketSeller($s2, ['display_name' => '둘가게']);
        $l1 = $this->marketListing($s1);
        $l2 = $this->marketListing($s2);
        $at = $this->utc('2026-09-10 12:00:00');
        $this->marketOrder($s1, $buyer, $l1, ['total_amount' => 30000, 'paid_at' => $at, 'created_at' => $at, 'commission_amount' => 1000, 'settlement_status' => 'pending', 'settlement_amount' => 29000]);
        $this->marketOrder($s1, $buyer, $l1, ['total_amount' => 10000, 'paid_at' => $at, 'created_at' => $at]);
        $this->marketOrder($s2, $buyer, $l2, ['total_amount' => 5000, 'paid_at' => $at, 'created_at' => $at]);
        $this->marketOrder($s2, $buyer, $l2, ['total_amount' => 99000, 'paid_at' => $at, 'created_at' => $at, 'status' => 'refunded', 'refunded_at' => $at]);

        $user = $this->viewer();
        $q = $this->period('2026-09-10', '2026-09-10');
        $res = $this->actingAs($user)->getJson($this->api('market/sellers', $q))->assertOk();
        $this->assertSame([$s1->id, $s2->id], array_column($res->json('data.rows'), 'seller_id'));
        $this->assertEquals(40000, $res->json('data.rows.0.amount'));
        $this->assertSame(1, $res->json('data.rows.1.cancelled'));
        $this->assertSame('/admin/sales-stats/sellers/'.$s1->id, $res->json('data.rows.0.detail_url'));
        $this->assertSame($s1->uuid, $res->json('data.rows.0.member.uuid'));
        $this->assertSame(2, $res->json('data.meta.total'));

        $asc = $this->actingAs($user)->getJson($this->api('market/sellers', $q + ['sort' => 'amount', 'dir' => 'asc']))->assertOk();
        $this->assertSame($s2->id, $asc->json('data.rows.0.seller_id'));
        $search = $this->actingAs($user)->getJson($this->api('market/sellers', $q + ['q' => '둘가게']))->assertOk();
        $this->assertSame([$s2->id], array_column($search->json('data.rows'), 'seller_id'));

        $summary = $this->actingAs($user)->getJson($this->api('market/summary', $q))->assertOk();
        $this->assertEquals(45000, $summary->json('data.raw.gmv'));
        $this->assertEquals(1, $summary->json('data.raw.cancelled_orders'));

        $detail = $this->actingAs($user)->getJson($this->api('market/sellers/'.$s1->id, $q))->assertOk();
        $this->assertSame('첫가게', $detail->json('data.profile.shop_name'));
        $this->assertEquals(40000, $detail->json('data.summary.raw.gmv'));
        $this->assertSame(1, $detail->json('data.summary.snapshot.settlement_pending_orders'));

        $orders = $this->actingAs($user)->getJson($this->api('market/sellers/'.$s1->id.'/orders', $q + ['per_page' => 5]))->assertOk();
        $this->assertCount(2, $orders->json('data.rows'));
        $this->assertSame(1, $orders->json('data.meta.last_page'));

        $this->actingAs($user)->getJson($this->api('market/sellers/'.$buyer->id, $q))->assertStatus(404);
        $this->actingAs($user)->getJson($this->api('market/sellers/999999', $q))->assertStatus(404);

        $settle = $this->actingAs($user)->getJson($this->api('market/settlements', $q))->assertOk();
        $this->assertSame([$s1->id], array_column($settle->json('data.rows'), 'seller_id'));
    }

    public function test_withdrawn_member_has_no_uuid_link(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        DB::table('users')->where('id', $buyer->id)->update(['status' => 'withdrawn']);
        $this->marketSeller($seller);
        $l = $this->marketListing($seller);
        $at = $this->utc('2026-09-10 12:00:00');
        $this->marketOrder($seller, $buyer, $l, ['paid_at' => $at]);
        $rows = $this->actingAs($this->viewer())->getJson($this->api('market/buyers', $this->period('2026-09-10', '2026-09-10')))->assertOk()->json('data.rows');
        $this->assertNull($rows[0]['member']['uuid']);
        $this->assertNull($rows[0]['member']['admin_url']);
    }

    /* ───── CSV ───── */

    public function test_csv_export_has_bom_and_blocks_formula_injection(): void
    {
        $at = $this->utc('2026-09-10 12:00:00');
        $this->ecommerceOrder(['paid_at' => $at, 'total_amount' => 1000], [
            ['product_id' => 21, 'product_name' => json_encode(['ko' => '=HYPERLINK("x")', 'en' => '=HYPERLINK("x")']), 'subtotal_price' => 1000],
        ]);
        $res = $this->actingAs($this->exporter())
            ->get($this->api('export', ['scope' => 'ecommerce', 'dataset' => 'products', 'from' => '2026-09-10', 'to' => '2026-09-10']));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);

        $this->actingAs($this->exporter())->get($this->api('export', ['scope' => 'ecommerce', 'dataset' => 'nope']))->assertStatus(422);
    }

    public function test_market_seller_orders_csv(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $this->marketSeller($seller);
        $l = $this->marketListing($seller);
        $at = $this->utc('2026-09-10 12:00:00');
        $this->marketOrder($seller, $buyer, $l, ['paid_at' => $at, 'created_at' => $at, 'order_no' => 'M-CSV-1']);
        $res = $this->actingAs($this->exporter())->get($this->api('export', [
            'scope' => 'market', 'dataset' => 'seller_orders', 'seller' => $seller->id, 'from' => '2026-09-10', 'to' => '2026-09-10',
        ]))->assertOk();
        $this->assertStringContainsString('M-CSV-1', $res->streamedContent());
    }

    /* ───── 전체 탭 ───── */

    public function test_overview_combines_channels_in_krw(): void
    {
        $at = $this->utc('2026-09-10 12:00:00');
        $this->ecommerceOrder(['paid_at' => $at, 'total_amount' => 1000]);
        $seller = User::factory()->create();
        $this->marketSeller($seller);
        $this->marketOrder($seller, User::factory()->create(), $this->marketListing($seller), ['paid_at' => $at, 'total_amount' => 2000]);
        $res = $this->actingAs($this->viewer())->getJson($this->api('overview', $this->period('2026-09-10', '2026-09-10')))->assertOk();
        if ($res->json('data.combinable')) {
            $this->assertEquals(3000, $res->json('data.combined.total.value'));
        } else {
            $this->assertNull($res->json('data.combined'));
        }
        $this->assertCount(1, $res->json('data.series.labels'));
    }
}
