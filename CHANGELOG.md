# Changelog

## [2.0.0] - 2026-09-30

### 변경 (Breaking)
- 모듈 식별자 `sirsoft-sales_stats` → `custom-sales_stats`, 네임스페이스 `Modules\Custom\SalesStats`. 기존 모듈을 제거하고 재설치해야 합니다(README 참고, 데이터 손실 없음).
- 관리자 URL `/admin/sales-stats` (레거시 `/admin/ecommerce/sales-stats` 유지), API `/api/modules/custom-sales_stats/...`
- 권한 `custom-sales_stats.stats.view` / `custom-sales_stats.stats.export`, 역할 `custom-sales_stats.viewer`

### 보안
- 모든 API에 `auth:sanctum` + `admin` + 권한 미들웨어 적용
- 인증 없는 `/ping`, `web.php`, blade 뷰 삭제
- CSV 수식 주입 이스케이프

### 추가
- 전체 / 이커머스 / 개인마켓 탭 (비활성 모듈 탭 자동 숨김)
- 기간 프리셋, 일·주·월·년 버킷(400개 초과 시 자동 확대), 관리자 시간대 기준 기간, 이전 기간 비교
- KPI 카드(증감 ▲▼), 막대 차트, 도넛 차트
- 상품·카테고리·구매자·판매자·리스팅 순위, 결제수단·상태·유형 분포, 정산 현황
- 판매자 상세 화면 `/admin/sales-stats/sellers/{userId}` (프로필, KPI, 주문, 정산, 404 안내)
- 회원정보·쪽지(custom-note) 연결
- CSV 내보내기(UTF-8 BOM)
- ko/en 언어 파일
- 레이아웃 생성기(`scripts/gen_layouts.py`)와 정적 검증기(`scripts/validate.py`)
- Feature/Unit 테스트
- 설치용 ZIP 패키지 스크립트 `scripts/package.sh` — `custom-sales_stats-<버전>.zip`, 최상위 폴더 `custom-sales_stats/` (설치 위치 `modules/custom-sales_stats`). 개발 파일(`tests/`, `.github/`, `HANDOFF.md`)은 ZIP에서 제외(`.gitattributes`)

### 제거
- 구 v1 레이아웃과 blade 화면
