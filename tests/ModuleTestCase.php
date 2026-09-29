<?php

namespace Modules\Custom\SalesStats\Tests;

use App\Enums\ExtensionStatus;
use App\Models\Module as ModuleRegistration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Custom\SalesStats\Services\EcommerceStatsService;
use Modules\Custom\SalesStats\Support\Availability;
use Tests\TestCase;

/**
 * custom-sales_stats 테스트 베이스
 *
 * - 코어 + 이커머스 마이그레이션 + (테스트 전용) 개인마켓 테이블 픽스처를 한 번에 migrate:fresh
 * - 모듈 오토로드 · 다국어 네임스페이스 · API 라우트를 수동 등록 (모듈 매니저를 거치지 않음)
 * - 연동 모듈 활성 여부는 FakeAvailability 로 제어
 *
 * 실행: G7 코어 루트에서  php vendor/bin/phpunit modules/custom-sales_stats/tests
 */
abstract class ModuleTestCase extends TestCase
{
    use RefreshDatabase;

    public const MOD = 'custom-sales_stats';

    protected function getModuleBasePath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $paths = [base_path('database/migrations')];
        foreach (['modules/sirsoft-ecommerce/database/migrations', 'modules/_bundled/sirsoft-ecommerce/database/migrations'] as $p) {
            if (is_dir(base_path($p))) {
                $paths[] = base_path($p);
                break;
            }
        }
        $paths[] = $this->getModuleBasePath().'/tests/fixtures/migrations';

        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--path' => $paths,
            '--realpath' => true,
        ];
    }

    protected function setUpTraits()
    {
        if (RefreshDatabaseState::$migrated) {
            try {
                if (! Schema::hasTable('user_markets_orders') || ! Schema::hasTable('ecommerce_orders')) {
                    RefreshDatabaseState::$migrated = false;
                }
            } catch (\Throwable) {
            }
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerModuleAutoload();
        require_once __DIR__.'/Support/FakeAvailability.php';
        $this->app['translator']->addNamespace(self::MOD, $this->getModuleBasePath().'/src/lang');
        config(['custom-sales_stats.cache_ttl' => 0, 'app.default_user_timezone' => 'Asia/Seoul']);
        EcommerceStatsService::flushColumnCache();

        FakeAvailability::$active = ['sirsoft-ecommerce' => true, 'custom-user_market' => true, 'custom-note' => false];
        $this->app->singleton(Availability::class, FakeAvailability::class);

        foreach (['sirsoft-ecommerce' => 'Ecommerce', self::MOD => 'Sales Stats'] as $id => $name) {
            ModuleRegistration::updateOrCreate(['identifier' => $id], [
                'vendor' => explode('-', $id)[0], 'name' => ['ko' => $name, 'en' => $name],
                'status' => ExtensionStatus::Active->value, 'version' => '2.0.0',
            ]);
        }

        foreach (['admin' => '관리자', 'user' => '사용자', 'guest' => '비회원'] as $identifier => $name) {
            Role::firstOrCreate(['identifier' => $identifier], ['name' => ['ko' => $name, 'en' => $identifier]]);
        }

        $this->registerModuleRoutes();

        // 픽스처는 필요한 컬럼만 넣으므로(상품 옵션 등 연관 행 생략) 외래키 검사를 끕니다 — 트랜잭션과 함께 롤백됨
        Schema::disableForeignKeyConstraints();
    }

    protected function registerModuleAutoload(): void
    {
        $base = $this->getModuleBasePath();
        spl_autoload_register(function ($class) use ($base) {
            $prefix = 'Modules\\Custom\\SalesStats\\';
            if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
                return;
            }
            $rel = substr($class, strlen($prefix));
            $file = str_starts_with($rel, 'Tests\\')
                ? $base.'/tests/'.str_replace('\\', '/', substr($rel, 6)).'.php'
                : $base.'/src/'.str_replace('\\', '/', $rel).'.php';
            if (file_exists($file) && ! class_exists($class, false) && ! trait_exists($class, false)) {
                require_once $file;
            }
        });
        if (! class_exists('Modules\\Custom\\SalesStats\\Module', false)) {
            require_once $base.'/module.php';
        }
    }

    protected function registerModuleRoutes(): void
    {
        Route::prefix('api/modules/'.self::MOD)
            ->name('api.modules.'.self::MOD.'.')
            ->middleware('api')
            ->group($this->getModuleBasePath().'/src/routes/api.php');
        app('router')->getRoutes()->refreshNameLookups();
    }

    /**
     * 관리자 (지정 권한 + 관리자 판정용 기본 admin 권한)
     *
     * @param  array<int, string>  $permissions
     */
    protected function adminWith(array $permissions): User
    {
        $role = Role::create([
            'identifier' => 'test_role_'.Str::random(8),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
        ]);
        $ids = [];
        foreach (array_merge(['core.dashboard.read'], $permissions) as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin',
            ])->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    protected function viewer(): User
    {
        return $this->adminWith([self::MOD.'.stats.view']);
    }

    protected function exporter(): User
    {
        return $this->adminWith([self::MOD.'.stats.view', self::MOD.'.stats.export']);
    }

    /**
     * NOT NULL · 기본값 없는 컬럼을 자동으로 채워 넣는 insert (스키마 변화에 덜 민감하게)
     *
     * @param  array<string, mixed>  $data
     */
    protected function insertRow(string $table, array $data): int
    {
        foreach (Schema::getColumns($table) as $col) {
            $name = $col['name'];
            if (array_key_exists($name, $data) || $col['nullable'] || $col['default'] !== null || ! empty($col['auto_increment'])) {
                continue;
            }
            $type = strtolower((string) ($col['type_name'] ?? ''));
            $data[$name] = match (true) {
                str_contains($type, 'int'), str_contains($type, 'decimal'), str_contains($type, 'double'), str_contains($type, 'float') => 0,
                str_contains($type, 'json') => '{}',
                str_contains($type, 'timestamp'), str_contains($type, 'datetime') => now('UTC')->format('Y-m-d H:i:s'),
                $type === 'date' => now('UTC')->format('Y-m-d'),
                str_contains($type, 'enum') => $this->firstEnumValue((string) ($col['type'] ?? '')),
                default => substr(Str::random(12), 0, 12),
            };
        }

        return (int) DB::table($table)->insertGetId($data);
    }

    private function firstEnumValue(string $type): string
    {
        return preg_match("/enum\\('([^']*)'/i", $type, $m) ? $m[1] : '';
    }

    /** KST 시각 문자열 → DB(UTC) 저장 문자열 */
    protected function utc(string $kst): string
    {
        return CarbonImmutable::parse($kst, 'Asia/Seoul')->utc()->format('Y-m-d H:i:s');
    }

    /* ───── 이커머스 픽스처 ───── */

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $options
     */
    protected function ecommerceOrder(array $attrs, array $options = []): int
    {
        $number = $attrs['order_number'] ?? 'T'.Str::upper(Str::random(10));
        $id = $this->insertRow('ecommerce_orders', array_merge([
            'order_number' => $number,
            'order_status' => 'payment_complete',
            'total_amount' => 0,
            'ordered_at' => $attrs['paid_at'] ?? now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ], $attrs));
        foreach ($options as $opt) {
            $this->insertRow('ecommerce_order_options', array_merge([
                'order_id' => $id,
                'product_id' => 1,
                'product_name' => json_encode(['ko' => '상품', 'en' => 'Product']),
                'quantity' => 1,
                'subtotal_price' => 0,
                'subtotal_discount_amount' => 0,
                'option_status' => 'payment_complete',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ], $opt));
        }

        return $id;
    }

    /* ───── 개인마켓 픽스처 ───── */

    protected function marketSeller(User $user, array $attrs = []): int
    {
        return (int) DB::table('user_markets_sellers')->insertGetId(array_merge([
            'user_id' => $user->id, 'display_name' => '테스트상점', 'status' => 'approved',
            'approved_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ], $attrs));
    }

    protected function marketListing(User $seller, array $attrs = []): int
    {
        return (int) DB::table('user_markets_listings')->insertGetId(array_merge([
            'user_id' => $seller->id, 'title' => '상품', 'type' => 'physical', 'status' => 'on_sale',
            'category' => 'etc', 'view_count' => 10, 'like_count' => 0,
            'published_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ], $attrs));
    }

    protected function marketOrder(User $seller, User $buyer, int $listingId, array $attrs = []): int
    {
        return (int) DB::table('user_markets_orders')->insertGetId(array_merge([
            'order_no' => 'M'.Str::upper(Str::random(8)), 'seller_id' => $seller->id, 'buyer_id' => $buyer->id,
            'listing_id' => $listingId, 'listing_title' => '상품', 'listing_type' => 'physical', 'quantity' => 1,
            'total_amount' => 10000, 'commission_amount' => 0, 'settlement_amount' => 0, 'mileage_used' => 0,
            'status' => 'paid', 'settlement_status' => 'none', 'payment_mode' => 'direct', 'trade_method' => 'delivery',
            'paid_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ], $attrs));
    }

    /**
     * @return array<string, mixed>
     */
    protected function period(string $from, string $to, array $extra = []): array
    {
        return array_merge(['from' => $from, 'to' => $to, 'compare' => '0'], $extra);
    }

    protected function api(string $path, array $query = []): string
    {
        return '/api/modules/'.self::MOD.'/'.$path.($query ? '?'.http_build_query($query) : '');
    }
}
