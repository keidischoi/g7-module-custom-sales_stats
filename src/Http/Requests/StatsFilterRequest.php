<?php

namespace Modules\Custom\SalesStats\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Custom\SalesStats\Support\Filters;

/**
 * 통계 조회 조건 검증 (권한은 라우트 미들웨어에서 확인)
 */
class StatsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'granularity' => ['nullable', 'in:day,week,month,year'],
            'basis' => ['nullable', 'in:paid,confirmed'],
            'compare' => ['nullable', 'in:0,1,true,false'],
            'q' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'string', 'in:amount,orders,quantity,buyers,commission,settlement_pending,rating,cancel_rate,aov,recent'],
            'dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'type' => ['nullable', 'in:physical,digital'],
            'payment_mode' => ['nullable', 'in:escrow,direct'],
            'category' => ['nullable', 'string', 'max:50'],
            'scope' => ['nullable', 'in:ecommerce,market'],
            'dataset' => ['nullable', 'string', 'max:40'],
            'seller' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): Filters
    {
        return Filters::fromArray($this->validated(), $this->timezone());
    }

    public function timezone(): string
    {
        $tz = $this->user()?->timezone ?: config('app.default_user_timezone', 'Asia/Seoul');

        return is_string($tz) && $tz !== '' ? $tz : 'Asia/Seoul';
    }
}
