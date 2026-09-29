<?php

return [
    'uncategorized' => 'Uncategorized',
    'others' => ':count others',
    'presets' => ['today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'this_month' => 'This month', 'last_month' => 'Last month', 'last90' => 'Last 90 days', 'this_year' => 'This year', 'last_year' => 'Last year'],
    'tabs' => ['overview' => 'Overview', 'ecommerce' => 'Ecommerce', 'market' => 'User Markets'],
    'channel' => ['ecommerce' => 'Ecommerce', 'market' => 'User Markets'],
    'ecommerce_status' => [
        'pending_order' => 'Pending order', 'pending_payment' => 'Awaiting payment', 'payment_complete' => 'Paid',
        'shipping_hold' => 'Shipping on hold', 'preparing' => 'Preparing', 'shipping_ready' => 'Ready to ship',
        'shipping' => 'Shipping', 'delivered' => 'Delivered', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled',
    ],
    'market_status' => [
        'pending_payment' => 'Awaiting deposit', 'paid' => 'Paid', 'shipped' => 'Shipped', 'completed' => 'Completed',
        'cancel_requested' => 'Cancel requested', 'on_hold' => 'On hold', 'refund_pending' => 'Refund pending', 'refunded' => 'Refunded', 'cancelled' => 'Cancelled',
    ],
    'settlement_status' => ['none' => 'N/A', 'pending' => 'Pending', 'done' => 'Settled'],
    'seller_status' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended', 'none' => 'Not a seller'],
    'listing_status' => [
        'pending_review' => 'In review', 'on_sale' => 'On sale', 'reserved' => 'Reserved', 'sold' => 'Sold',
        'hidden' => 'Hidden', 'held' => 'Held', 'rejected' => 'Rejected', 'deleted' => 'Deleted',
    ],
    'listing_type' => ['physical' => 'Physical', 'digital' => 'Digital', 'none' => '-'],
    'payment_mode' => ['escrow' => 'Escrow (admin)', 'direct' => 'Direct', 'none' => '-'],
    'trade_method' => [
        'meet' => 'Meet-up', 'parcel' => 'Parcel', 'delivery' => 'Delivery', 'convenience' => 'Convenience store',
        'quick' => 'Courier', 'freight' => 'Freight', 'none' => 'Digital / unset',
    ],
    'payment_method' => [
        'card' => 'Credit card', 'vbank' => 'Virtual account', 'dbank' => 'Bank deposit', 'bank' => 'Bank transfer', 'phone' => 'Mobile',
        'point' => 'Points', 'deposit' => 'Deposit', 'free' => 'Free (fully discounted)', 'unknown' => 'Other / unknown',
    ],
    'device' => ['pc' => 'PC', 'mobile' => 'Mobile', 'app_ios' => 'iOS app', 'app_android' => 'Android app', 'admin' => 'Admin order', 'api' => 'External API', 'unknown' => 'Unknown'],
    'csv' => [
        'rank' => 'Rank', 'period' => 'Period', 'orders' => 'Orders', 'quantity' => 'Quantity', 'amount' => 'Sales', 'share' => 'Share (%)',
        'product_id' => 'Product ID', 'product_code' => 'Product code', 'product' => 'Product', 'category_id' => 'Category ID', 'category' => 'Category',
        'member_id' => 'Member ID', 'nickname' => 'Nickname', 'email' => 'Email', 'last_at' => 'Last order', 'shop' => 'Shop',
        'seller_status' => 'Seller status', 'buyers' => 'Buyers', 'commission' => 'Commission', 'payout' => 'Seller payout',
        'settlement_pending' => 'Pending settlement', 'rating' => 'Rating', 'cancel_rate' => 'Cancel rate (%)', 'listing_id' => 'Listing ID',
        'listing' => 'Listing', 'type' => 'Type', 'seller' => 'Seller', 'views' => 'Views', 'oldest' => 'Oldest completion',
        'order_no' => 'Order no.', 'ordered_at' => 'Ordered at', 'buyer' => 'Buyer', 'status' => 'Status', 'settlement' => 'Settlement',
    ],
];
