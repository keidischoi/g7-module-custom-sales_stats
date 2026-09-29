// 판매 통계 모듈 프론트엔드 빌드 (의존성 없음): node scripts/build.mjs
// resources/assets/module.js → dist/js/module.iife.js (그대로 복사)
import { copyFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
mkdirSync(join(root, 'dist/js'), { recursive: true });
copyFileSync(join(root, 'resources/assets/module.js'), join(root, 'dist/js/module.iife.js'));
console.log('built custom-sales_stats: dist/js/module.iife.js');
