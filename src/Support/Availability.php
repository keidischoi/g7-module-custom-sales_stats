<?php

namespace Modules\Custom\SalesStats\Support;

use App\Enums\PermissionType;
use App\Extension\ModuleManager;
use Illuminate\Support\Facades\Schema;

/**
 * 연동 대상 모듈의 사용 가능 여부 (읽기 전용 연동)
 *
 * 모듈이 "활성" 이고 필요한 테이블이 있을 때만 해당 탭/지표를 켭니다.
 * 테스트에서는 컨테이너에 다른 구현을 바인딩해 바꿀 수 있습니다.
 */
class Availability
{
    public const ECOMMERCE = 'sirsoft-ecommerce';

    public const MARKET = 'custom-user_market';

    public const NOTE = 'custom-note';

    /** @var array<string, bool> */
    private array $cache = [];

    public function ecommerce(): bool
    {
        return $this->cache['ecommerce'] ??= $this->active(self::ECOMMERCE)
            && $this->tables(['ecommerce_orders', 'ecommerce_order_options']);
    }

    public function market(): bool
    {
        return $this->cache['market'] ??= $this->active(self::MARKET)
            && $this->tables(['user_markets_orders', 'user_markets_sellers', 'user_markets_listings']);
    }

    public function note(): bool
    {
        return $this->cache['note'] ??= $this->active(self::NOTE);
    }

    /**
     * 관리자가 회원 정보(이메일 등)를 볼 권한이 있는지
     */
    public function canViewMembers(mixed $user): bool
    {
        try {
            return $user !== null && method_exists($user, 'hasPermission')
                && $user->hasPermission('core.users.read', PermissionType::Admin);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'ecommerce' => $this->ecommerce(),
            'market' => $this->market(),
            'note' => $this->note(),
        ];
    }

    protected function active(string $identifier): bool
    {
        try {
            return in_array($identifier, ModuleManager::getActiveModuleIdentifiers(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $tables
     */
    protected function tables(array $tables): bool
    {
        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
