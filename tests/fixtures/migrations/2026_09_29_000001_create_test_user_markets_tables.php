<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 테스트 전용 — 개인마켓(custom-user_market) 테이블 최소 스키마
 *
 * 실제 설치에서는 개인마켓 모듈이 만든 테이블을 읽기만 하며, 이 파일은 tests/ 에서만 쓰입니다.
 * (모듈 설치 시 실행되는 database/migrations 가 아닙니다.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_markets_sellers')) {
            Schema::create('user_markets_sellers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->unique();
                $t->string('display_name')->nullable();
                $t->text('intro')->nullable();
                $t->string('logo_path')->nullable();
                $t->string('status', 20)->default('pending');
                $t->boolean('is_designated')->default(false);
                $t->timestamp('approved_at')->nullable();
                $t->string('status_reason')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('user_markets_listings')) {
            Schema::create('user_markets_listings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('title');
                $t->string('type', 20)->default('physical');
                $t->string('category', 50)->nullable();
                $t->string('status', 20)->default('on_sale');
                $t->unsignedInteger('view_count')->default(0);
                $t->unsignedInteger('like_count')->default(0);
                $t->timestamp('published_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('user_markets_orders')) {
            Schema::create('user_markets_orders', function (Blueprint $t) {
                $t->id();
                $t->string('order_no', 40)->nullable();
                $t->unsignedBigInteger('seller_id')->nullable();
                $t->unsignedBigInteger('buyer_id')->nullable();
                $t->unsignedBigInteger('listing_id')->nullable();
                $t->string('listing_title')->nullable();
                $t->string('listing_type', 20)->default('physical');
                $t->unsignedInteger('quantity')->default(1);
                $t->unsignedBigInteger('total_amount')->default(0);
                $t->unsignedBigInteger('commission_amount')->default(0);
                $t->unsignedBigInteger('settlement_amount')->default(0);
                $t->unsignedBigInteger('mileage_used')->default(0);
                $t->string('status', 30)->default('pending_payment');
                $t->string('settlement_status', 20)->nullable();
                $t->string('payment_mode', 20)->default('direct');
                $t->string('trade_method', 20)->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('refunded_at')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->timestamp('settled_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('user_markets_member_stats')) {
            Schema::create('user_markets_member_stats', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->unique();
                $t->decimal('seller_rating', 3, 2)->default(0);
                $t->unsignedInteger('seller_review_count')->default(0);
                $t->unsignedInteger('sales_count')->default(0);
                $t->decimal('buyer_rating', 3, 2)->default(0);
                $t->unsignedInteger('purchase_count')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('user_markets_reviews')) {
            Schema::create('user_markets_reviews', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('reviewer_id')->nullable();
                $t->unsignedBigInteger('reviewee_id');
                $t->string('direction', 20)->default('to_seller');
                $t->unsignedTinyInteger('rating')->default(5);
                $t->text('content')->nullable();
                $t->boolean('is_hidden')->default(false);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('user_markets_reports')) {
            Schema::create('user_markets_reports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('listing_id');
                $t->string('status', 20)->default('pending');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['user_markets_reports', 'user_markets_reviews', 'user_markets_member_stats', 'user_markets_orders', 'user_markets_listings', 'user_markets_sellers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
