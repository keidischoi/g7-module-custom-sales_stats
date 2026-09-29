<?php

namespace Modules\Custom\SalesStats\Support;

/**
 * 회원 요약 (회원정보·쪽지 연결용)
 *
 * - uuid: 관리자 회원 상세(/admin/users/{uuid}) 와 쪽지(data-g7-user / __G7Note.compose) 에 사용
 * - email: 회원 조회 권한(core.users.read)이 있을 때만 포함
 */
final class Members
{
    /**
     * @return array<string, mixed>|null
     */
    public static function summary(?object $row, string $prefix, bool $withEmail): ?array
    {
        if ($row === null) {
            return null;
        }
        $id = $row->{$prefix.'id'} ?? null;
        if ($id === null) {
            return null;
        }
        $uuid = $row->{$prefix.'uuid'} ?? null;
        $nickname = $row->{$prefix.'nickname'} ?? null;
        $name = $row->{$prefix.'name'} ?? null;
        $status = $row->{$prefix.'status'} ?? null;
        $withdrawn = $status === 'withdrawn';

        return [
            'id' => (int) $id,
            'uuid' => $withdrawn ? null : ($uuid ?: null),
            'nickname' => (string) ($nickname ?: ($name ?: '#'.$id)),
            'email' => $withEmail ? ($row->{$prefix.'email'} ?? null) : null,
            'status' => $status,
            'admin_url' => $uuid && ! $withdrawn ? '/admin/users/'.$uuid : null,
        ];
    }
}
