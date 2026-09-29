#!/usr/bin/env bash
# G7 설치용 zip 생성: build/custom-sales_stats-<버전>.zip (최상위 폴더 = custom-sales_stats/)
# 사용법: bash scripts/package.sh [ref]   (기본 HEAD, 커밋된 내용만 포함 · NAS 에서는 PHP=php82 bash scripts/package.sh)
set -euo pipefail
cd "$(dirname "$0")/.."
ID=$(${PHP:-php} -r 'echo json_decode(file_get_contents("module.json"), true)["identifier"];')
VER=$(${PHP:-php} -r 'echo json_decode(file_get_contents("module.json"), true)["version"];')
if [ "$ID" != "custom-sales_stats" ]; then
  echo "module.json identifier 가 custom-sales_stats 가 아닙니다: $ID" >&2; exit 1
fi
REF="${1:-HEAD}"
OUT="${OUT_DIR:-build}"
mkdir -p "$OUT"
ZIP="$OUT/$ID-$VER.zip"
rm -f "$ZIP"
git archive --format=zip --prefix="$ID/" -o "$ZIP" "$REF"
# 모든 항목이 custom-sales_stats/ 아래에 있는지 확인
${PHP:-php} -r '$z=new ZipArchive; if($z->open($argv[1])!==true){fwrite(STDERR,"zip 열기 실패\n");exit(1);}
for($i=0;$i<$z->numFiles;$i++){ if(strpos($z->getNameIndex($i),$argv[2]."/")!==0){fwrite(STDERR,"zip 최상위 폴더가 ".$argv[2]."/ 가 아닌 항목: ".$z->getNameIndex($i)."\n");exit(1);} }' "$ZIP" "$ID"
echo "$ZIP"
