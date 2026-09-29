# custom-sales_stats (판매 통계) v2.0.0

그누보드7(G7) 관리자용 **판매 통계 모듈**입니다. 이커머스(`sirsoft-ecommerce`)와 개인 마켓(`custom-user_market`)의 판매 데이터를 **읽기 전용**으로 모아 탭별로 보여 줍니다.
다른 모듈의 테이블은 조회만 하며, 이 모듈에는 자체 테이블이나 마이그레이션이 없습니다.

## 주요 기능

| 탭 | 내용 |
|----|------|
| **전체** | 이커머스와 개인마켓을 합산한 KPI와 추이 (이커머스 통화가 KRW일 때만 합산) |
| **이커머스** | KPI(주문/매출/객단가/할인/배송비/환불 등)와 이전 기간 대비 증감(▲▼), 기간별 막대 차트(보조 y축), 상품·카테고리·구매자 순위, 상태/결제수단 도넛 차트 |
| **개인마켓** | KPI, 추이, 판매자 순위(정렬·검색), 상품(리스팅) 순위, 구매자 순위, 유형/결제 방식/카테고리 분포, 정산 현황 |
| **판매자 상세** | 판매자 프로필, 기간 KPI, 주문 목록(페이지네이션), 정산 내역 |

- 모듈이 비활성이면 해당 탭은 숨겨집니다. 탭 목록은 서버의 `meta.tabs`가 결정합니다.
- 기간: 프리셋(오늘/어제/7일/30일/90일/이번 달/지난 달/올해/작년) 또는 직접 입력. 관리자 시간대(기본 Asia/Seoul) 기준이며 시작일·종료일을 모두 포함합니다.
- 집계 단위: 일/주/월/년. 버킷이 400개를 넘으면 단위를 자동으로 키웁니다.
- 이전 기간 비교 토글
- 회원 행에서 **[회원정보]**(관리자 회원 상세 `/admin/users/{uuid}`로 이동)와 **[쪽지]**(`custom-note`의 `window.__G7Note.compose`가 있으면 쪽지 작성, 없으면 회원 상세로 이동) 제공. 비회원·탈퇴 회원은 안내 메시지만 표시합니다.
- **CSV 내보내기**: UTF-8 BOM 포함, 수식 주입(`= + - @` 시작 값) 이스케이프. `custom-sales_stats.stats.export` 권한이 있을 때만 버튼이 보입니다.

## URL

| 화면 | URL |
|------|-----|
| 판매 통계 | `/admin/sales-stats` |
| 레거시 별칭 | `/admin/ecommerce/sales-stats` (같은 화면) |
| 판매자 상세 | `/admin/sales-stats/sellers/{userId}` |
| API | `/api/modules/custom-sales_stats/...` (`auth:sanctum` + `admin` + 권한 미들웨어) |

## 권한

| 키 | 설명 |
|----|------|
| `custom-sales_stats.stats.view` | 통계 화면·API 조회 |
| `custom-sales_stats.stats.export` | CSV 내보내기 |

- 설치 시 `admin` 역할과 모듈 역할 **`custom-sales_stats.viewer`** 에 위 권한이 부여됩니다.
- 관리자가 아닌 사용자는 권한이 있어도 API가 403을 반환합니다.

## 설치

> ⚠️ **설치 폴더명은 반드시 `custom-sales_stats`** 입니다 → `modules/custom-sales_stats`
> G7은 모듈 폴더명을 식별자로 사용합니다. 저장소 이름(`g7-module-custom-sales_stats`)이나 GitHub "Download ZIP"의 폴더명(`g7-module-custom-sales_stats-main`)을 그대로 두면 모듈이 인식되지 않습니다.

아래 예시는 Synology NAS 사이트 루트 `/volume1/web/3ds`, PHP 8.2 바이너리 `php82` 기준입니다.

### 방법 1. git clone (권장)

```bash
cd /volume1/web/3ds/modules
git clone https://github.com/keidischoi/g7-module-custom-sales_stats.git custom-sales_stats
cd /volume1/web/3ds
php82 artisan extension:update-autoload
php82 artisan module:install custom-sales_stats
php82 artisan module:activate custom-sales_stats
php82 artisan optimize:clear
```

`git clone` 끝의 `custom-sales_stats`가 폴더명입니다. 빠뜨리면 `g7-module-custom-sales_stats` 폴더가 생기므로 꼭 붙이세요.

### 방법 2. 릴리스 ZIP

GitHub Releases의 **`custom-sales_stats-2.0.0.zip`** 은 최상위 폴더가 `custom-sales_stats/` 입니다.

- 관리자 > 모듈 관리 > ZIP 업로드로 설치하거나,
- 서버에서 직접 풀어 `modules/custom-sales_stats`가 되게 합니다.

```bash
cd /volume1/web/3ds/modules
unzip /path/to/custom-sales_stats-2.0.0.zip      # → modules/custom-sales_stats/
cd /volume1/web/3ds
php82 artisan extension:update-autoload
php82 artisan module:install custom-sales_stats
php82 artisan module:activate custom-sales_stats
php82 artisan optimize:clear
```

GitHub의 "Code > Download ZIP"을 쓴 경우에는 풀린 `g7-module-custom-sales_stats-main` 폴더 이름을 `custom-sales_stats`로 바꾼 뒤 `modules/` 아래에 두세요.

설치용 ZIP을 직접 만들려면(커밋된 내용 기준, `build/custom-sales_stats-<버전>.zip`):

```bash
PHP=php82 bash scripts/package.sh
```

### 업데이트 (같은 식별자 `custom-sales_stats` 사이)

**git으로 설치한 경우** (자체 테이블·마이그레이션이 없으므로 코드와 레이아웃만 갱신):

```bash
cd /volume1/web/3ds/modules/custom-sales_stats
git pull
cd /volume1/web/3ds
php82 artisan extension:update-autoload
php82 artisan module:refresh-layout custom-sales_stats
php82 artisan optimize:clear
```

**릴리스 ZIP으로 업데이트하는 경우:**

```bash
cd /volume1/web/3ds
php82 artisan module:update custom-sales_stats --zip=/path/to/custom-sales_stats-2.0.0.zip
php82 artisan optimize:clear
```

업데이트 후 브라우저에서 강력 새로고침(Ctrl+Shift+R)을 하세요.

## 기존 `sirsoft-sales_stats`(v1.x)에서 재설치

식별자가 `sirsoft-sales_stats` → **`custom-sales_stats`** 로 바뀌었으므로 업데이트가 아니라 **재설치**가 필요합니다.

```bash
cd /volume1/web/3ds
php82 artisan module:uninstall sirsoft-sales_stats      # 또는 관리자 > 모듈 관리에서 제거
rm -rf modules/sirsoft-sales_stats
cd modules
git clone https://github.com/keidischoi/g7-module-custom-sales_stats.git custom-sales_stats
cd /volume1/web/3ds
php82 artisan extension:update-autoload
php82 artisan module:install custom-sales_stats
php82 artisan module:activate custom-sales_stats
php82 artisan optimize:clear
```

그다음 필요한 관리자/역할에 `custom-sales_stats.viewer` 역할 또는 `custom-sales_stats.stats.view` / `.stats.export` 권한을 부여합니다.

**데이터 손실은 없습니다.** 구 모듈에는 자체 테이블이나 마이그레이션이 없었습니다.

## 개발

### 레이아웃 생성·검증
레이아웃 JSON은 생성기로 만듭니다. JSON을 직접 고치지 말고 생성기를 고친 뒤 다시 생성하세요.

```bash
python3 scripts/gen_layouts.py   # resources/layouts/admin/** 생성
python3 scripts/validate.py      # 정적 검증
```

`validate.py` 검사 항목: JSON 파싱, partial 규칙, `{{ }}` 짝, **`?.` / `??` 사용 금지**, 표현식 JS 문법(node), CSS 클래스가 sirsoft-admin_basic 빌드 CSS에 있는지(`tests/fixtures/admin_basic_classes.txt`), 콘텐츠 래퍼(`admin-page-content-responsive`)에 폭 유틸리티 금지, 엔드포인트·핸들러 화이트리스트, `$t` 키의 ko/en 존재와 일치, 라우트의 레이아웃 존재, `dist/js/module.iife.js` = `resources/assets/module.js`.

### 테스트
G7 코어(7.0.x) 체크아웃의 `modules/custom-sales_stats`에 이 저장소를 두고, `.env.testing`에 MySQL/MariaDB 테스트 DB를 설정한 뒤:

```bash
php82 vendor/bin/phpunit modules/custom-sales_stats/tests
```

- `tests/Feature/StatsApiTest.php`: 인증·권한, 모듈 비활성, KST 경계·버킷, 이전 기간 비교, 취소 주문/옵션 제외, 카테고리 중복 제거, 판매자 순위·상세·주문·정산, 탈퇴 회원, CSV(BOM·수식 이스케이프·422)
- `tests/Unit/PackagingTest.php`: 식별자 = 설치 폴더명(`custom-sales_stats`), 릴리스/패키지 zip 최상위 폴더, README 설치 경로
- `tests/Unit/LayoutValidationTest.php`: 코어 레이아웃 규칙(`ValidLayoutStructure`, `WhitelistedEndpoint`, `NoExternalUrls`, `SafeLayoutExpressions`) + `?.`/`??` 금지, CSS 클래스, 번역 키, 라우트
- 개인마켓 테이블은 `tests/fixtures/migrations`의 테스트용 스키마를 씁니다.

### 릴리스 워크플로
`.github/workflow-templates/release.yml`에 있습니다. `main`의 버전 태그가 없으면 `custom-sales_stats-<버전>.zip`(최상위 폴더 `custom-sales_stats/`)을 만들어 릴리스합니다. 사용하려면 `.github/workflows/release.yml`로 옮기세요. 옮기려면 `workflow` 권한이 필요합니다.

## 제한사항

- SQL 버킷은 고정 UTC offset으로 계산하므로 **DST가 있는 시간대**에서는 경계 전후에 오차가 생길 수 있습니다. (Asia/Seoul은 영향 없음)
- **전체 탭 합산**은 이커머스 통화가 KRW일 때만 합니다.
- CSV 내보내기 URL의 검색어(`q`)·카테고리 값은 URL 인코딩되지 않습니다. `&`, `#` 같은 특수문자가 들어가면 필터가 잘릴 수 있습니다.
- 라이브 G7 7.0.x의 표현식 엔진 호환성을 위해 레이아웃에서 `?.` / `??`를 쓰지 않고 `&&` / `||`로 썼습니다.
- 개인마켓 스키마는 `custom-user_market` 모듈의 구조를 가정했습니다. 테스트는 픽스처 스키마를 사용합니다.
- **실제 설치 환경에서는 아직 검증하지 않았습니다.** (코어 7.0.11 테스트 환경에서 테스트만 통과)

## 라이선스
MIT
