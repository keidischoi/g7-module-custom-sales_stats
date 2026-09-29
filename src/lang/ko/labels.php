<?php

return [
    'uncategorized' => '미분류',
    'others' => '기타 :count개',
    'presets' => ['today' => '오늘', 'yesterday' => '어제', 'last7' => '최근 7일', 'last30' => '최근 30일', 'this_month' => '이번 달', 'last_month' => '지난 달', 'last90' => '최근 90일', 'this_year' => '올해', 'last_year' => '작년'],
    'tabs' => ['overview' => '전체', 'ecommerce' => '이커머스', 'market' => '개인마켓'],
    'channel' => ['ecommerce' => '이커머스', 'market' => '개인마켓'],
    'ecommerce_status' => [
        'pending_order' => '주문대기', 'pending_payment' => '결제대기', 'payment_complete' => '결제완료',
        'shipping_hold' => '배송보류', 'preparing' => '상품준비중', 'shipping_ready' => '배송준비완료',
        'shipping' => '배송중', 'delivered' => '배송완료', 'confirmed' => '구매확정', 'cancelled' => '주문취소',
    ],
    'market_status' => [
        'pending_payment' => '입금대기', 'paid' => '입금확인', 'shipped' => '발송완료', 'completed' => '거래완료',
        'cancel_requested' => '취소요청', 'on_hold' => '보류', 'refund_pending' => '환불대기', 'refunded' => '환불완료', 'cancelled' => '취소',
    ],
    'settlement_status' => ['none' => '대상 아님', 'pending' => '정산대기', 'done' => '정산완료'],
    'seller_status' => ['pending' => '승인대기', 'approved' => '승인', 'rejected' => '반려', 'suspended' => '정지', 'none' => '판매자 아님'],
    'listing_status' => [
        'pending_review' => '심사대기', 'on_sale' => '판매중', 'reserved' => '예약중', 'sold' => '판매완료',
        'hidden' => '숨김', 'held' => '보류', 'rejected' => '반려', 'deleted' => '삭제됨',
    ],
    'listing_type' => ['physical' => '실물', 'digital' => '디지털', 'none' => '-'],
    'payment_mode' => ['escrow' => '관리자 입금형', 'direct' => '직거래형', 'none' => '-'],
    'trade_method' => [
        'meet' => '직거래', 'parcel' => '일반택배', 'delivery' => '택배', 'convenience' => '편의점택배',
        'quick' => '퀵서비스', 'freight' => '화물·용달', 'none' => '디지털/미지정',
    ],
    'payment_method' => [
        'card' => '신용카드', 'vbank' => '가상계좌', 'dbank' => '무통장입금', 'bank' => '계좌이체', 'phone' => '휴대폰결제',
        'point' => '포인트결제', 'deposit' => '예치금결제', 'free' => '무료(전액 할인)', 'unknown' => '기타/미확인',
    ],
    'device' => ['pc' => 'PC', 'mobile' => '모바일', 'app_ios' => 'iOS 앱', 'app_android' => 'Android 앱', 'admin' => '관리자 대리주문', 'api' => '외부 API', 'unknown' => '미확인'],
    'csv' => [
        'rank' => '순위', 'period' => '기간', 'orders' => '주문수', 'quantity' => '수량', 'amount' => '매출액', 'share' => '비중(%)',
        'product_id' => '상품ID', 'product_code' => '상품코드', 'product' => '상품명', 'category_id' => '카테고리ID', 'category' => '카테고리',
        'member_id' => '회원ID', 'nickname' => '닉네임', 'email' => '이메일', 'last_at' => '최근 주문', 'shop' => '상점명',
        'seller_status' => '판매자 상태', 'buyers' => '구매자수', 'commission' => '수수료', 'payout' => '판매자 정산액',
        'settlement_pending' => '정산대기액', 'rating' => '평점', 'cancel_rate' => '취소율(%)', 'listing_id' => '상품ID',
        'listing' => '상품', 'type' => '유형', 'seller' => '판매자', 'views' => '조회수', 'oldest' => '가장 오래된 거래완료일',
        'order_no' => '주문번호', 'ordered_at' => '주문일시', 'buyer' => '구매자', 'status' => '상태', 'settlement' => '정산',
    ],
];
