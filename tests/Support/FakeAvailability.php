<?php

namespace Modules\Custom\SalesStats\Tests;

use Modules\Custom\SalesStats\Support\Availability;

/**
 * 테스트용 — 연동 모듈 활성 여부를 직접 지정 (테이블 존재 여부는 실제로 확인)
 */
class FakeAvailability extends Availability
{
    /** @var array<string, bool> */
    public static array $active = [];

    protected function active(string $identifier): bool
    {
        return (bool) (self::$active[$identifier] ?? false);
    }
}
