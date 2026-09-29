<?php

namespace Modules\Custom\SalesStats;

use App\Extension\AbstractModule;

/**
 * 판매 통계 모듈 (custom-sales_stats)
 *
 * 이커머스(sirsoft-ecommerce)와 개인 마켓(custom-user_market)의 판매 데이터를
 * 읽기 전용으로 집계해 관리자 대시보드로 보여 줍니다.
 * 이 모듈은 자체 테이블이 없고, 다른 모듈의 테이블에는 쓰지 않습니다.
 */
class Module extends AbstractModule
{
    public const ID = 'custom-sales_stats';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRoles(): array
    {
        return [
            [
                'identifier' => self::ID.'.viewer',
                'name' => ['ko' => '판매 통계 열람자', 'en' => 'Sales Stats Viewer'],
                'description' => [
                    'ko' => '판매 통계 대시보드를 조회할 수 있습니다',
                    'en' => 'Can view the sales statistics dashboard',
                ],
            ],
        ];
    }

    /**
     * 권한 — custom-sales_stats.stats.view / custom-sales_stats.stats.export
     *
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        $roles = ['admin', self::ID.'.viewer'];

        return [
            'name' => ['ko' => '판매 통계', 'en' => 'Sales Statistics'],
            'description' => ['ko' => '판매 통계 권한', 'en' => 'Sales statistics permissions'],
            'categories' => [
                [
                    'identifier' => 'stats',
                    'name' => ['ko' => '통계', 'en' => 'Statistics'],
                    'description' => ['ko' => '판매 통계 조회·내보내기 권한', 'en' => 'View and export sales statistics'],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => '판매 통계 조회', 'en' => 'View Sales Statistics'],
                            'description' => ['ko' => '판매 통계 대시보드와 판매자 상세를 조회합니다', 'en' => 'View dashboards and seller details'],
                            'type' => 'admin',
                            'roles' => $roles,
                        ],
                        [
                            'action' => 'export',
                            'name' => ['ko' => '판매 통계 내보내기', 'en' => 'Export Sales Statistics'],
                            'description' => ['ko' => '판매 통계를 CSV 로 내려받습니다', 'en' => 'Download statistics as CSV'],
                            'type' => 'admin',
                            'roles' => $roles,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'slug' => self::ID.'-main',
                'name' => ['ko' => '판매 통계', 'en' => 'Sales Statistics'],
                'url' => '/admin/sales-stats',
                'icon' => 'fas fa-chart-line',
                'order' => 45,
                'permission' => self::ID.'.stats.view',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'keidischoi',
            'license' => 'MIT',
        ];
    }
}
