# HANDOFF — custom-sales_stats v2 재구축 (작업 재개용)

> 작성: 2026-09-29 23:41 KST · 브랜치 `feat/v2-redesign` (main c4a12ac 기준) · 상태: **WIP (PR 미생성)**
> 이 문서만으로 다른 계정의 새 에이전트가 이어서 작업할 수 있도록 작성했습니다.

---

## 0. 절대 규칙

- **이 저장소(`keidischoi/g7-module-custom-sales_stats`)만 수정합니다.** 다른 모듈(이커머스, 개인마켓 `custom-user_market`, 쪽지 `custom-note`)은 **읽기 전용**입니다. 테이블을 읽기만 하고 해당 모듈의 라우트로 링크만 겁니다.
- 새 브랜치(`feat/v2-redesign`)에서 작업합니다. 마지막에 **한국어 설명으로 PR을 생성하되 머지하지 않습니다.**
- 보고하는 시간은 KST로 적습니다.
- 푸시 토큰에 `workflow` scope가 없어서 `.github/workflows/*`를 푸시할 수 없습니다.
  - 그래서 릴리스 워크플로는 `.github/workflow-templates/release.yml`에 임시로 두었습니다.
  - workflow 권한이 있는 계정이라면 `.github/workflows/release.yml`로 옮겨서 커밋합니다. 권한이 없으면 PR 본문에 "수동으로 옮겨 주세요"라고 적습니다.

## 1. 승인된 계획 (요약)

### 식별자 변경
- `sirsoft-sales_stats` → **`custom-sales_stats`**
- 네임스페이스: `Modules\Custom\SalesStats`
- API 경로: `/api/modules/custom-sales_stats/...`
- 권한 키: `custom-sales_stats.stats.view` / `custom-sales_stats.stats.export`
- 역할: `custom-sales_stats.viewer`
- `module.json`의 `github_url`: `https://github.com/keidischoi/g7-module-custom-sales_stats`
- 버전: **2.0.0**

### 재설치 안내 (README와 PR에 반드시 적을 것)
1. 기존 `sirsoft-sales_stats`를 제거(uninstall)합니다.
2. 폴더를 삭제합니다.
3. `custom-sales_stats`를 설치하고 활성화합니다.
4. `php artisan optimize:clear`를 실행합니다.
5. 역할과 권한을 부여합니다.

데이터 손실은 없습니다. 구 모듈에는 자체 테이블이나 migrations가 없었고, 이 점은 확인했습니다.

### 새 관리자 URL
- `/admin/sales-stats`
- 레거시 별칭: `/admin/ecommerce/sales-stats`
- 판매자 상세: `/admin/sales-stats/sellers/{userId}`

### 탭
- **전체 / 이커머스 / 개인마켓**
- 모듈이 비활성이면 그 탭을 숨깁니다. 탭 목록은 서버의 `meta.tabs`가 결정합니다.

### 기능
- **보안 수정:** `auth:sanctum` + admin + 권한 미들웨어를 적용합니다. 기존 `/ping`, `web.php`, blade 뷰는 삭제합니다.
- **기간:** 관리자 시간대(기본 Asia/Seoul) 기준이며 양 끝을 포함합니다.
  - 버킷은 일/주/월/년이고, 버킷이 400개를 넘으면 단위를 자동으로 키웁니다.
  - 이전 기간 비교를 지원합니다.
  - 프리셋: 오늘, 어제, 7/30/90일, 이번 달, 지난 달, 올해, 작년
- **상태와 환불 처리 정확화**
  - 이커머스 매출 = `payment_complete`부터 `confirmed`까지의 상태. "구매확정 기준"을 고르면 `confirmed`와 `confirmed_at`만 씁니다.
  - 옵션 중 `cancelled`는 제외합니다.
  - 수량에서는 `parent_option_id`가 있는 행을 제외합니다.
  - 금액 = `subtotal_price` − `subtotal_discount_amount`
- **카테고리 중복 제거:** 상품마다 대표 카테고리(`is_primary`, 없으면 최소 id) 하나로만 집계합니다. 하위 항목은 "기타"로 묶습니다.
- **개인마켓:** 거래액, 주문, 객단가, 활동 판매자, 수수료, 정산, 취소율, 스냅샷(정산 대기, 심사 대기 등)을 보여 줍니다.
  - 판매자 순위: 페이징, 정렬, 검색 지원
  - 상품 순위, 구매자 순위, 분포(유형, 대금방식, 거래방식, 카테고리, 상태), 정산 대기 목록
- **판매자 상세 페이지:** 프로필, KPI, 추이, 상품, 구매자, 최근 주문(페이징), 후기, 분포, 정산 정보, 주문 CSV
- **회원 연동**
  - 관리자 회원 상세 링크는 `/admin/users/{uuid}`입니다.
  - 쪽지 연동은 회원 셀에 `data-g7-user="{uuid}"`, `data-g7-user-name`, `data-g7-user-click`을 붙이는 방식입니다.
  - "쪽지" 버튼은 커스텀 핸들러 `custom-sales_stats.composeNote {uuid}`를 호출하고, 이 핸들러가 `window.__G7Note.compose(uuid)`를 부릅니다.
  - 쪽지 모듈이 없으면 토스트를 띄운 뒤 회원 페이지를 엽니다.
  - `custom-sales_stats.openMember {uuid}`도 제공합니다.
- **UI:** 코어 관리자 컴포넌트를 사용하고 partial로 분할한 깔끔하고 현대적인 UI
- **CSV 내보내기:** BOM 포함, 수식 인젝션 방지
- **기타:** 언어 파일(ko/en), README, CHANGELOG, feature 테스트

## 2. 완료된 것 (커밋 19eaa81)

**삭제**
- `resources/views/`, `src/routes/web.php`, 구 컨트롤러와 서비스, `/ping`

**루트 파일**
- `module.json`: v2.0.0, `g7_version >=7.0.7`, 의존성 `sirsoft-ecommerce >=1.0.0`, assets JS `resources/assets/module.js` → `dist/js/module.iife.js`, `handlers:true`
- `composer.json`: psr-4 `Modules\Custom\SalesStats\` → `src/`
- `module.php`
  - 권한: 카테고리 `stats`, 액션 `view` / `export`
  - 역할: `viewer`
  - 관리자 메뉴: `/admin/sales-stats`, 아이콘 `fas fa-chart-line`, order 45

**`src/Support/`**
- `PeriodRange`: `presets()`, `presetKey()`, `toArray().preset`
- `Sql`: mysql / sqlite / pgsql 버킷 처리
- `Availability`: 모듈 활성 여부와 테이블 존재 여부
- `Money`, `Stats`
- `Members::summary`: uuid(탈퇴 회원은 null), email(`core.users.read` 권한이 있을 때만), `admin_url`
- `Filters`

**`src/Services/`**
- `Concerns`(trait): 캐시, `kpi()`, `rank()`
  - `rank()`는 `rank`, `share`, `bar`, `color`, `name`, `value`를 추가합니다. `name`과 `value`는 DonutChart에 바로 바인딩하기 위한 값입니다.
- `EcommerceStatsService`: `summary` / `timeseries` / `products` / `categories` / `buyers` / `statuses` / `paymentMethods` / `devices` / `topOrders` / `exportRows`
- `MarketStatsService`: `summary`(+`snapshot`) / `timeseries` / `sellers`(페이징) / `listings` / `buyers` / `breakdowns` / `settlements` / `sellerProfile` / `sellerOrders` / `sellerReviews` / `exportRows`
- `OverviewStatsService`: 통화가 KRW로 같을 때만 합산합니다(`combinable`).

**컨트롤러와 요청**
- `src/Http/Controllers/Admin/StatsController.php`
  - `meta` 응답: `available`, `tabs`, `filters`, `presets`(label 포함), `currency`, `market_categories`, `can.export`, `can.view_members`
  - 모듈이 없으면 404, 오류가 나면 로그를 남기고 500을 반환합니다.
- `ExportController.php`
  - `?scope=ecommerce&dataset=period|products|categories|buyers`
  - `?scope=market&dataset=period|sellers|listings|buyers|settlements|seller_orders(&seller=)`
  - 필터 파라미터는 공통으로 받습니다.
- `src/Http/Requests/StatsFilterRequest.php`

**라우트 (`src/routes/api.php`)**
- 모든 라우트: `auth:sanctum`, throttle 120/분, view 권한
- 경로 목록
  - `meta`, `overview`
  - `ecommerce/{summary,timeseries,products,categories,buyers,breakdowns}`
  - `market/{summary,timeseries,sellers,sellers/{id},sellers/{id}/orders,listings,buyers,breakdowns,settlements}`
  - `export`: export 권한 추가, throttle 20/분

**언어와 에셋**
- `src/lang/{ko,en}/messages.php`, `labels.php` (presets 포함)
- `resources/assets/module.js`와 `scripts/build.mjs`(단순 복사 방식), `dist/js/module.iife.js`
  - 핸들러 `composeNote`, `openMember`를 등록합니다.
  - JS 번역 키 `custom-sales_stats.js.no_member`, `js.note_unavailable`를 사용합니다.
- `.github/workflow-templates/release.yml`: market 모듈에서 복사했으며, 위의 workflow scope 문제 때문에 이 위치에 둡니다.

**검증 상태:** 모든 PHP 파일 `php -l` 통과, JSON 파싱 통과. 테스트와 레이아웃은 아직 없습니다.

## 3. 남은 작업 (순서대로)

1. **`resources/routes/admin.json` 재작성**
   - `*/admin/sales-stats` → 레이아웃 `admin_sales_stats`
   - `*/admin/ecommerce/sales-stats` → 같은 레이아웃
   - `*/admin/sales-stats/sellers/:userId` → `admin_sales_stats_seller`
   - 각 항목에 meta title과 breadcrumb를 넣습니다. market 모듈의 `resources/routes/admin.json` 형식을 참고합니다.
2. **`resources/layouts/admin/admin_sales_stats.json` 재작성**
   - 기존 851줄짜리는 구버전이라 교체해야 합니다.
   - 레이아웃 속성: `"extends":"_admin_base"`, `"permissions":["custom-sales_stats.stats.view"]`
   - data_sources
     - `meta`는 항상 불러옵니다.
     - 탭별 소스는 `"if":"{{(query.tab ?? 'overview')==='ecommerce'}}"` 식으로 조건을 겁니다.
     - 전달할 params: `from`, `to`, `granularity`, `basis`, `compare`, `q`, `sort`, `dir`, `page`, `type`, `payment_mode`, `category`
     - `errorHandling`을 설정합니다.
   - partial은 `resources/layouts/admin/partials/admin_sales_stats/` 아래에 둡니다. partial 파일에는 `meta.is_partial:true`와 컴포넌트만 넣고, data_sources와 computed는 부모에 둡니다.
     - `_header`: 제목, 기간 표시, 새로고침(`refetchDataSource {dataSourceId}`), CSV 버튼(`meta.data.can.export`일 때만)
     - `_filters`: 프리셋 칩(`meta.data.presets`, 현재 값은 `meta.data.filters.preset`), 날짜 입력, 일/주/월/년 선택, 결제/구매확정 기준, 비교 토글. 필터 변경은 `navigate` + `mergeQuery`로 처리합니다.
     - `_tabs`: TabNavigation을 씁니다. `tabs={{meta.data.tabs}}`, `activeTabId={{query.tab ?? 'overview'}}`, `onTabChange` → navigate `query.tab=$args[0]`
     - `_tab_overview` / `_tab_ecommerce` / `_tab_market`: KPI 카드(변동률 ▲▼, 이전 기간 값), BarChart, DonutChart, Table과 iteration으로 만든 순위표, 회원 셀의 `data-g7-user`, [회원정보][쪽지][상세][주문] 버튼, EmptyState, Pagination
   - 레이아웃을 손으로 쓰는 양이 많으므로, `scripts/gen_layouts.py` 같은 Python 생성기로 만든 뒤 결과 JSON도 함께 커밋하는 방식을 권장합니다.
3. **`admin_sales_stats_seller.json`과 partial 작성**
   - data source `seller`: `/api/modules/custom-sales_stats/market/sellers/{{route.userId}}` (응답: `profile`, `summary`, `timeseries`, `listings`, `buyers`, `breakdowns`, `reviews`, `filters`)
   - data source `sellerOrders`: `.../sellers/{{route.userId}}/orders` (응답: `rows`, `meta.last_page`)
   - 판매자가 없을 때(404)는 EmptyState를 보여 줍니다.
4. **`resources/lang/ko.json`, `en.json` 작성**: 레이아웃에서 쓰는 `$t:custom-sales_stats.*` 키와 `js.*` 키. 파일 최상위에는 모듈 접두사를 붙이지 않습니다.
5. **테스트 작성 (`tests/`)**
   - market 모듈의 `tests/ModuleTestCase.php`와 `tests/Unit/LayoutValidationTest.php`를 모델로 삼습니다. 이 레이아웃 테스트는 코어 규칙 `SafeLayoutExpressions`, `ValidLayoutStructure`, `WhitelistedEndpoint`, CSS 클래스, `$t` 키를 검사합니다.
   - Feature 테스트 항목
     - 권한이 없으면 403
     - KST 버킷 경계
     - 취소/환불 제외
     - 카테고리 중복 제거
     - 판매자 순위와 상세
     - CSV 내보내기
     - 비활성 모듈에 대해 404
   - 하네스가 실행되지 않으면 최소한 Python으로 JSON과 CSS 클래스를 검증합니다.
6. **README.md(한국어)와 CHANGELOG.md(2.0.0) 작성**: 기능, 설치/재설치 절차, 새 URL, 권한, 제한사항을 적습니다.
7. **검증:** `php -l`, JSON 파싱, `node --check dist/js/module.iife.js`, 테스트
8. **커밋, 푸시, PR**
   - `gh pr create`로 한국어 PR을 만듭니다. 본문 내용: 기능, 화면, 배포·재설치 절차, 제한사항, 실제 설치 환경 미검증 사실, 워크플로 파일 이동 필요 여부
   - **머지 금지.**

## 4. 데이터 소스와 테이블 (모두 읽기 전용)

### 이커머스
- **`ecommerce_orders`**
  - 컬럼: `user_id`, `order_number`, `order_status`, `order_device`, `is_first_order`, `total_amount`(부분 취소 시 재계산됨), `total_discount_amount`, `total_shipping_amount`, `total_points_used_amount`, `total_cancelled_amount`, `ordered_at`, `paid_at`, `confirmed_at`, `cancelled_at`
  - `order_device` 값: `pc`, `mobile`, `app_ios`, `app_android`, `admin`, `api`
- **`ecommerce_order_options`**: `option_status`, `parent_option_id`, `product_id`, `product_name`(다국어 JSON), `quantity`, `subtotal_price`, `subtotal_discount_amount`
- **`ecommerce_order_payments.payment_method`**: `card`, `vbank`, `dbank`, `bank`, `phone`, `point`, `deposit`, `free`
- **`ecommerce_products.product_code`**
- **`ecommerce_product_categories.is_primary`**, **`ecommerce_categories`**
- 링크
  - 주문: `/admin/ecommerce/orders/{order_number}`
  - 상품: `/admin/ecommerce/products/{product_code}/edit`

### 개인마켓 (`custom-user_market` v0.1.11)
- 테이블: `user_markets_orders`, `_sellers`, `_listings`, `_member_stats`, `_reviews`, `_reports`
- 거래 상태: `paid`, `shipped`, `completed`, `cancel_requested`, `on_hold` 중 `paid_at`이 있는 것. 취소는 결제 후 `cancelled` / `refunded`로 판단합니다.
- 링크
  - 주문: `/admin/user-markets/orders/{id}`
  - 정산 대기: `/admin/user-markets/orders?settlement_status=pending`
  - 상점: `/market/members/{id}`
  - 상품: `/market/listings/{id}`
- 로고: `/api/modules/custom-user_market/shops/{userId}/logo?v=<md5(logo_path) 앞 8자>`
- 카테고리: `module_setting('custom-user_market','general.categories')` → `[{key,name}]`
- 통화: KRW 고정

### 회원과 쪽지
- `users`: `uuid`(라우트 키), `nickname`, `name`, `email`, `status`(`withdrawn`)
- 쪽지 모듈(`custom-note` v1.1.5): `data-g7-user` 속성, `window.__G7Note.compose(uuid)`

## 5. 주의사항

**코어와 CSS**
- 코어 버전은 7.0.11입니다. 레이아웃에는 관리자 템플릿 `sirsoft-admin_basic`의 빌드된 CSS(`dist/css/components.css`)에 존재하는 클래스만 써야 합니다.
- 없어서 쓰면 안 되는 클래스: `bg-card`, `to-violet-500`, `bg-*-500/10`, `dark:text-rose-400`, `w-1.5`/`h-1.5`, `ring-indigo-500`, `sm:/md:/lg:table-cell`, `hover:text-indigo-600`, `underline-offset-2`, `dark:bg-gray-900/40`

**컴포넌트**
- **StatCard:** `change`가 null이어도 표시될 수 있습니다(0%로 보임). 기본 Div/Icon/Span으로 KPI 카드를 직접 만드는 것을 권장합니다.
- **Icon:** 이름은 FontAwesome 6이며 `fa-` 접두사는 선택입니다. 예: `chart-line`, `coins`, `users`, `store`, `envelope`, `user`, `download`, `rotate`
- **BarChart:** `datasets[].yAxisID:'y1'`로 보조 축을 쓸 수 있습니다. `showYAxis`, `showYGrid`를 지원합니다. LineChart는 없습니다.
- **DonutChart:** `data` 형식은 `[{name,value,color}]`입니다. `rank()` 결과를 그대로 쓸 수 있습니다.
- **Pagination:** `currentPage`, `totalPages`, 이벤트 `onPageChange` → navigate `query.page=$args[0]`
- **Select:** `change` 이벤트는 `$event.target.value`로 받습니다. Input 검색은 `keypress`(`key:"Enter"`)로 처리합니다. market 레이아웃 `admin_market_orders.json`을 참고합니다.
- **style 바인딩:** `"style":{"width":"{{(row.bar ?? 0) + '%'}}"}` 형태가 동작합니다.

**핸들러**
- CSV 다운로드는 **템플릿 핸들러 `downloadAttachment`**를 씁니다. `params`는 `{url, filename}`이며, `G7Core.api.get(blob)`을 거쳐 Bearer 토큰이 자동으로 붙습니다. url 예: `/api/modules/custom-sales_stats/export?scope=ecommerce&dataset=products&from={{...}}...`
- `callExternal`은 생성자 호출 방식이라 쓸 수 없습니다. 그래서 모듈 자체 핸들러(`module.js`)를 씁니다.

**시간대와 통화**
- `app.timezone`은 UTC이고 관리자 기본 시간대는 `config('app.default_user_timezone')` = Asia/Seoul입니다.
- SQL 버킷은 고정 offset을 쓰므로 DST가 있는 시간대에서는 오차가 생깁니다. 제한사항으로 명시합니다.
- 전체 탭의 합산은 이커머스 통화가 KRW일 때만 가능합니다.

## 6. 검증 환경 참고 (이전 박스 기준, 새 환경에서는 다시 구성)

**준비물**
- G7 코어 소스(7.0.11, vendor 포함)를 복사합니다.
- `.env.testing`에 mysql(`g7test`, 사용자 `g7` / 비밀번호 `g7pass`)을 설정합니다.
- 이 모듈을 `modules/custom-sales_stats`에 둡니다.
- PHP 8.4 확장: mysql, sqlite3, mbstring, xml, intl, bcmath 등

**명령**
```bash
find src -name '*.php' -exec php -l {} \;
find . -name '*.json' -not -path './.git/*' -exec python3 -m json.tool {} \; >/dev/null
node --check dist/js/module.iife.js
php artisan test modules/custom-sales_stats/tests   # 하네스 구성 후
```
