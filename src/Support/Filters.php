<?php

namespace Modules\Custom\SalesStats\Support;

/**
 * 검증된 조회 조건
 */
final class Filters
{
    public const BASES = ['paid', 'confirmed'];

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly PeriodRange $range,
        public readonly string $basis = 'paid',
        public readonly bool $compare = true,
        public readonly array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $input  검증된 입력
     */
    public static function fromArray(array $input, string $timezone): self
    {
        $range = PeriodRange::make(
            isset($input['from']) ? (string) $input['from'] : null,
            isset($input['to']) ? (string) $input['to'] : null,
            isset($input['granularity']) ? (string) $input['granularity'] : null,
            $timezone,
        );
        $basis = in_array($input['basis'] ?? null, self::BASES, true) ? $input['basis'] : 'paid';
        $compare = ! isset($input['compare']) || filter_var($input['compare'], FILTER_VALIDATE_BOOLEAN);

        $extra = array_filter([
            'q' => isset($input['q']) ? trim((string) $input['q']) : null,
            'sort' => $input['sort'] ?? null,
            'dir' => ($input['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => min(100, max(5, (int) ($input['per_page'] ?? 20))),
            'limit' => min(500, max(1, (int) ($input['limit'] ?? 10))),
            'type' => $input['type'] ?? null,
            'payment_mode' => $input['payment_mode'] ?? null,
            'category' => isset($input['category']) && $input['category'] !== '' ? (string) $input['category'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        return new self($range, $basis, $compare, $extra);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function with(array $extra): self
    {
        return new self($this->range, $this->basis, $this->compare, array_merge($this->extra, $extra));
    }

    public function withRange(PeriodRange $range): self
    {
        return new self($range, $this->basis, $this->compare, $this->extra);
    }

    /** 캐시 키 */
    public function key(string $scope): string
    {
        return 'custom-sales_stats:'.$scope.':'.md5(json_encode([
            $this->range->toArray(), $this->basis, $this->compare, $this->extra, app()->getLocale(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->range->toArray(), [
            'basis' => $this->basis,
            'compare' => $this->compare,
            'previous' => $this->compare ? [
                'from' => $this->range->previous()->from->format('Y-m-d'),
                'to' => $this->range->previous()->to->format('Y-m-d'),
            ] : null,
        ], array_intersect_key($this->extra, array_flip(['q', 'sort', 'dir', 'page', 'per_page', 'type', 'payment_mode', 'category'])));
    }
}
