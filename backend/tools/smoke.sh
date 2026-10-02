#!/usr/bin/env bash
# EduVision end-to-end smoke test (local machine only).
#
# Drives the whole teacher/student loop over HTTP with curl against a local
# server that uses the FAKE Gemini client and no Google credentials:
# register -> approve -> login -> classroom + 3 students -> assignment with
# mcq / numeric short / show_work / open (+ rubric) -> layout -> worksheet PDF
# -> scans without an AI key (manual/ai_key_missing) -> PUT /me/ai-key ->
# requeue -> grading -> review queue -> override -> approve-confident ->
# publish -> student PIN login -> results -> appeal -> resolve -> mastery /
# analytics -> practice generate/approve/attempt -> ml model -> Google
# endpoints without credentials (503 google_not_configured).
#
# Phase 8/9 (DESIGN §19, §20): a course with a unit and a lesson plan bound
# to the classroom (every assignment needs one) -> freeform assignment whose
# key is read once from a photo (cache on the second estimate) -> drafted
# rubrics approved -> indicator suggestions from the plan -> mapping -> key
# approved -> teacher whole-page upload + student in-app hand-in (a draft
# assignment answers 409) -> one Gemini call per page -> review -> publish ->
# page image for the student -> chart endpoints (teacher and student) ->
# "analyse now" -> edit -> approve -> the student's shared text.
#
# Phase 10/11 (DESIGN §22, §23): the course's gradebook from a template ->
# an app-graded exam with two versions (sections, key, indicators, approval)
# -> key sheet, booklet and answer sheets printed -> scan kit -> answer
# sheets read "by the phone" (tools/smoke-exam-sheet.php) -> a double mark
# resolved -> publish -> option analysis and exam observations -> the
# student's exam result -> a manual exam and a custom item with scores ->
# gradebook table, CSV, publish -> the student's grade.
#
# Google Classroom with Google faked: tools/smoke-google.php runs those
# requests and their worker passes in its own PHP process with Http::fake
# (OAuth, Classroom, Drive) read from a JSON state file, because the server
# started here has no Google client. Connect -> course list -> import
# preview -> import (409 when imported again) -> roster sync adds a new
# student -> "sync now" mirrors coursework created on the Classroom website
# with an AI-drafted key -> a hand-in synced before approval waits
# (waiting_key) -> approve -> graded -> publish -> private announcement
# posted (INDIVIDUAL_STUDENTS), no grade pushed to website coursework.
#
# School-wide accounts, shared homerooms and Google sign-in (DESIGN §24):
# student 1 found by student code and enrolled in a second classroom with
# the same PIN (PIN login through either room) -> roster copied from the
# first classroom -> a duplicate account listed and merged -> a second
# teacher asks to teach the first classroom, the homeroom teacher approves,
# the subject teacher sees only their own course and cannot edit the
# roster, the homeroom teacher reads but cannot edit their work -> Google
# sign-in off on the server (503) -> with ID tokens signed by
# tools/smoke-google.php (faked JWKS): a teacher linked by e-mail, an
# unknown account gets a link ticket, a student confirms with the PIN once
# and then signs in with Google, a Classroom student is linked from the
# roster, unlink by the homeroom teacher and the student (the school's
# switch is restored afterwards) -> the subject teacher imports their own
# Google course: the existing classroom is suggested (100%), link-existing
# makes a request, approval links the course, no new student accounts ->
# the student's combined view over an open and a closed classroom.
#
# The general limit is 120 requests/minute per user; a 429 is waited out once.
# Every run creates new rows in the dev database (a new teacher each time).
#
# Prerequisites: MariaDB container running, `php artisan migrate --seed`
# done (the demo school with SEED_TEACHER_JOIN_CODE), QR_SIGNING_KEY set in
# .env, the PHP gd extension (test pages are real JPEGs), indicators of
# grade 5 (the seeder's demo rows, or eduvision:import-skills) and a
# model registered with eduvision:register-model (else the ml step expects
# 404). Run from anywhere:  bash backend/tools/smoke.sh
# With a dev server already on port 8000, pick another port:
#   SMOKE_PORT=8010 bash backend/tools/smoke.sh
#
# The server is started here with `php artisan serve --no-reload` so the
# overrides below reach it (without --no-reload artisan serve removes every
# variable PHP read from .env from the child PHP server's environment; when
# php.ini's variables_order includes E that drops the shell overrides too,
# and the child reads .env again). Set
# SMOKE_START_SERVER=0 to use a server you started yourself with the same
# environment. The worker is `php artisan eduvision:queue-work`, the same
# command Plesk's cron runs.
#
# GEMINI_API_KEY is forced empty so the server key in .env (if any) is never
# used: the "no key yet" step needs no key at all, and the fake never calls
# Google anyway.
set -euo pipefail

BACKEND="$(cd "$(dirname "$0")/.." && pwd)"
cd "$BACKEND"
PORT="${SMOKE_PORT:-8000}"
BASE="http://127.0.0.1:${PORT}/api/v1"
SCHOOL_CODE="${SMOKE_SCHOOL_CODE:-$(grep -E '^SEED_TEACHER_JOIN_CODE=' .env | cut -d= -f2- | tr -d '"' || true)}"
SCHOOL_CODE="${SCHOOL_CODE:-DEMO2569}"
RUN="$(date +%s)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/eduvision-smoke.XXXXXX")"

export GEMINI_FAKE=true
export GEMINI_API_KEY=
export GOOGLE_OAUTH_CLIENT_ID=
export GOOGLE_OAUTH_CLIENT_SECRET=
export FIREBASE_CREDENTIALS=

SERVER_PID=""
STEPS=0
cleanup() {
  # The school's Google sign-in settings changed for the sign-in steps go back as they were.
  if declare -F restore_school >/dev/null; then restore_school || true; fi
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    pkill -f "php -S 127.0.0.1:${PORT}" 2>/dev/null || true
  fi
  if [ "${SMOKE_KEEP:-0}" = "1" ]; then echo "work dir kept: $WORK"; else rm -rf "$WORK"; fi
}
trap cleanup EXIT

step() { STEPS=$((STEPS + 1)); printf '\n== %02d %s\n' "$STEPS" "$*"; }
fail() { printf '\nFAIL (step %02d): %s\n' "$STEPS" "$*" >&2; exit 1; }
ok() { printf '   ok %s\n' "$*"; }

TOKEN=""
# api METHOD PATH [JSON]  -> STATUS, BODY (uses $TOKEN when set)
api() {
  local method=$1 path=$2 data=${3:-}
  local args=(-s -o "$WORK/body" -D "$WORK/headers" -w '%{http_code}' -X "$method" -H 'Accept: application/json')
  [ -n "$TOKEN" ] && args+=(-H "Authorization: Bearer $TOKEN")
  [ -n "$data" ] && args+=(-H 'Content-Type: application/json' --data-binary "$data")
  STATUS=$(curl "${args[@]}" "$BASE$path")
  BODY=$(cat "$WORK/body")
  if [ "$STATUS" = "429" ] && [ "${API_RETRY:-1}" = "1" ]; then
    # The run sends more than the general 120 requests/minute per user (DESIGN §7.4): wait it out once.
    local wait; wait=$(tr -d '\r' <"$WORK/headers" | awk 'tolower($1) == "retry-after:" {print $2}')
    printf '   (429, waiting %ss for the rate limit)\n' "${wait:-60}"
    sleep "${wait:-60}"
    API_RETRY=0 api "$@"
  fi
}
expect() {
  local s
  for s in "$@"; do [ "$STATUS" = "$s" ] && return 0; done
  fail "expected HTTP $* but got $STATUS: $(head -c 800 <<<"$BODY")"
}
check() {
  jq -e "$1" <<<"$BODY" >/dev/null 2>&1 || fail "check failed: $1 :: $(head -c 800 <<<"$BODY")"
}
j() { jq -r "$1" <<<"$BODY"; }
work() {
  perl -e 'alarm 180; exec @ARGV' php artisan eduvision:queue-work >>"$WORK/worker.log" 2>&1 \
    || fail "queue worker failed, see log: $(tail -20 "$WORK/worker.log")"
}
tinker() { perl -e 'alarm 60; exec @ARGV' php artisan tinker --execute "$1"; }
# upload PATH FILE...  -> multipart files[] (uses $TOKEN)
upload() {
  local path=$1 f type
  shift
  local args=(-s -o "$WORK/body" -w '%{http_code}' -H 'Accept: application/json')
  [ -n "$TOKEN" ] && args+=(-H "Authorization: Bearer $TOKEN")
  for f in "$@"; do
    case "$f" in *.jpg) type=image/jpeg ;; *.pdf) type=application/pdf ;; *) type=text/plain ;; esac
    args+=(-F "files[]=@$f;type=$type")
  done
  STATUS=$(curl "${args[@]}" -D "$WORK/headers" "$BASE$path")
  BODY=$(cat "$WORK/body")
  if [ "$STATUS" = "429" ] && [ "${API_RETRY:-1}" = "1" ]; then
    local wait; wait=$(tr -d '\r' <"$WORK/headers" | awk 'tolower($1) == "retry-after:" {print $2}')
    printf '   (429, waiting %ss for the rate limit)\n' "${wait:-60}"
    sleep "${wait:-60}"
    API_RETRY=0 upload "$path" "$@"
  fi
}
# jpeg FILE LABEL -> a real JPEG of a handwritten-looking page (GD); the label makes each file unique
jpeg() {
  php -r '$i = imagecreatetruecolor(900, 1200); imagefill($i, 0, 0, imagecolorallocate($i, 255, 255, 255));
    $k = imagecolorallocate($i, 20, 20, 60);
    foreach ([$argv[2], "1) 42", "2) 4 x 15 = 60", "3) 60"] as $n => $line) { imagestring($i, 5, 60, 80 + 60 * $n, $line, $k); }
    imagejpeg($i, $argv[1], 80);' "$1" "$2"
}

# ---------------------------------------------------------------- server
if [ "${SMOKE_START_SERVER:-1}" = "1" ]; then
  if curl -s -o /dev/null "http://127.0.0.1:${PORT}"; then
    fail "port ${PORT} is already in use; stop that server or set SMOKE_START_SERVER=0"
  fi
  php artisan serve --no-reload --host=127.0.0.1 --port="$PORT" >"$WORK/server.log" 2>&1 &
  SERVER_PID=$!
  for _ in $(seq 1 50); do
    curl -s -o /dev/null "$BASE/health" && break
    sleep 0.2
  done
fi

step "health"
api GET /health
expect 200
check '.db == "ok"'
ok "$(j '.status')"

# ---------------------------------------------------------------- teacher
EMAIL="smoke-${RUN}@example.com"
PASSWORD="smoke-pass-${RUN}"
step "the sign-up form's school list (names only, no join codes)"
api GET /auth/schools
expect 200
check '(.data | length) >= 1 and all(.data[]; (keys == ["id", "name"]))'
ok "$(j '.data | length') school(s)"

# The app sends school_id; school_code is the path of older app builds and still
# picks the demo school here (the dev database may hold several schools).
step "teacher register ($EMAIL) with the legacy school_code"
api POST /auth/teacher/register "$(jq -nc --arg c "$SCHOOL_CODE" --arg e "$EMAIL" --arg p "$PASSWORD" \
  '{school_code:$c, name:"ครูทดสอบ smoke", email:$e, password:$p}')"
expect 201
check '.user.status == "pending"'
TEACHER_ID=$(j '.user.id')
SCHOOL_ID=$(j '.user.school.id')
ok "teacher #$TEACHER_ID pending in school #$SCHOOL_ID"

step "login before approval is refused"
api POST /auth/teacher/login "$(jq -nc --arg e "$EMAIL" --arg p "$PASSWORD" '{email:$e, password:$p}')"
expect 403
check '.code == "account_not_active"'
ok "403 account_not_active"

step "admin approves the teacher (same fields as the Filament action)"
tinker "\$a = App\\Models\\User::where('role','admin')->value('id'); App\\Models\\User::findOrFail(${TEACHER_ID})->forceFill(['status' => 'active', 'approved_by' => \$a])->save(); echo 'approved';" | tail -1

step "teacher login"
api POST /auth/teacher/login "$(jq -nc --arg e "$EMAIL" --arg p "$PASSWORD" '{email:$e, password:$p, device_name:"smoke"}')"
expect 200
TEACHER_TOKEN=$(j '.token')
TOKEN=$TEACHER_TOKEN
api GET /me
expect 200
check '.data.role == "teacher" and .data.status == "active"'
api GET /me/ai-key
expect 200
check '.data.configured == false or .configured == false'
ok "logged in, no AI key"

# ---------------------------------------------------------------- classroom
step "classroom + 3 students"
api POST /classrooms '{"name":"ป.5/1 smoke","grade_level":5,"academic_year":2569}'
expect 201
CLASSROOM_ID=$(j '.data.id')
CLASS_CODE=$(j '.data.class_code')
api POST "/classrooms/$CLASSROOM_ID/students" '{"students":[{"name":"ด.ช. หนึ่ง ทดสอบ","student_number":1},{"name":"ด.ญ. สอง ทดสอบ","student_number":2},{"name":"ด.ช. สาม ทดสอบ","student_number":3}]}'
expect 201
check '(.data | length) == 3 and all(.data[]; (.pin | tostring | length) == 6)'
STUDENTS=($(j '.data[].student_id'))
PIN1=$(j '.data[0].pin')
api GET "/classrooms/$CLASSROOM_ID/roster"
expect 200
ok "classroom #$CLASSROOM_ID ($CLASS_CODE), students ${STUDENTS[*]}"

# ---------------------------------------------------------------- course (Phase 9)
step "course, unit and lesson plan bound to the classroom"
api GET /subjects
expect 200
# Any assessable level (§20.2): the seeder's demo rows are indicators, an imported curriculum may add sub-indicators.
api GET "/skills?level=indicator,sub_indicator&grade=5"
expect 200
check '(.data | length) >= 2'
SUBJECT_ID=$(j '.data[0].subject_id')
SKILL1=$(j '.data[0].id')
SKILL2=$(jq -r --argjson s "$SUBJECT_ID" '[.data[] | select(.subject_id == $s)][1].id // .data[0].id' <<<"$BODY")
api POST /courses "$(jq -nc --argjson s "$SUBJECT_ID" --argjson c "$CLASSROOM_ID" --argjson k1 "$SKILL1" --argjson k2 "$SKILL2" \
  '{code:"ค15101", name:"คณิตศาสตร์ 5 (smoke)", subject_id:$s, grade_level:5, semester:1, academic_year:2569, hours:160, classroom_ids:[$c], skill_ids:[$k1,$k2]}')"
expect 201
COURSE_ID=$(j '.data.id')
api POST /courses "$(jq -nc --argjson s "$SUBJECT_ID" '{code:"ค15101", name:"ซ้ำ", subject_id:$s, grade_level:5, semester:1, academic_year:2569}')"
expect 422
api POST "/courses/$COURSE_ID/units" "$(jq -nc --argjson k1 "$SKILL1" --argjson k2 "$SKILL2" '{title:"การคูณ", hours:12, skill_ids:[$k1,$k2]}')"
expect 201
UNIT_ID=$(j '.data.id')
api POST "/courses/$COURSE_ID/lesson-plans" "$(jq -nc --argjson u "$UNIT_ID" --argjson k1 "$SKILL1" --argjson k2 "$SKILL2" \
  '{unit_id:$u, title:"การคูณจำนวนสองหลัก", hours:2, objectives:"คูณจำนวนสองหลักได้", content:"การคูณ", activities:"ใบงาน", assessment:"การบ้าน", skill_ids:[$k1,$k2]}')"
expect 201
PLAN_ID=$(j '.data.id')
api PATCH "/lesson-plans/$PLAN_ID" "$(jq -nc --arg d "$(date +%F)" '{taught_on:$d}')"
expect 200
api GET "/courses?classroom_id=$CLASSROOM_ID"
expect 200
check "any(.data[]; .id == $COURSE_ID)"
api GET "/courses/$COURSE_ID"
expect 200
ok "course #$COURSE_ID, unit #$UNIT_ID, lesson plan #$PLAN_ID (skills $SKILL1, $SKILL2); duplicate code -> 422"

# ---------------------------------------------------------------- assignment
step "assignment with mcq, numeric short, show_work, open"
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" '{classroom_id:$c, title:"ไม่มีรายวิชา"}')"
expect 422
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson k "$COURSE_ID" --argjson p "$PLAN_ID" '{classroom_id:$c, course_id:$k, lesson_plan_id:$p, title:"แบบฝึกหัด smoke การคูณ"}')"
expect 201
check ".data.subject.id == $SUBJECT_ID or .data.subject_id == $SUBJECT_ID"
ASSIGNMENT_ID=$(j '.data.id')

api POST "/assignments/$ASSIGNMENT_ID/questions" "$(jq -nc --argjson k "$SKILL1" \
  '{type:"mcq", prompt_text:"12 + 30 เท่ากับเท่าใด  A) 32  B) 42  C) 52  D) 62", max_points:1, answer_key:{correct:"B"}, skill_ids:[$k]}')"
expect 201
Q_MCQ=$(j '.data.id')
api POST "/assignments/$ASSIGNMENT_ID/questions" "$(jq -nc --argjson k "$SKILL1" \
  '{type:"short", prompt_text:"25 × 5 = ?", max_points:2, is_numeric:true, answer_key:{accepted:["125"], numeric:{value:125, abs_tol:0}}, skill_ids:[$k]}')"
expect 201
Q_SHORT=$(j '.data.id')
api POST "/assignments/$ASSIGNMENT_ID/questions" "$(jq -nc --argjson k "$SKILL2" \
  '{type:"show_work", prompt_text:"แม่ค้ามีส้ม 4 ลัง ลังละ 15 ผล มีส้มทั้งหมดกี่ผล แสดงวิธีทำ", max_points:5, answer_lines:4, is_numeric:true,
    answer_key:{final:{accepted:["60"], numeric:{value:60, abs_tol:0}}, reference_steps:["ส้ม 4 ลัง ลังละ 15 ผล","4 × 15 = 60","ตอบ 60 ผล"]}, skill_ids:[$k]}')"
expect 201
Q_WORK=$(j '.data.id')
api POST "/assignments/$ASSIGNMENT_ID/questions" "$(jq -nc --argjson k "$SKILL2" \
  '{type:"open", prompt_text:"อธิบายว่าการคูณเกี่ยวข้องกับการบวกซ้ำอย่างไร", max_points:4, answer_lines:5, skill_ids:[$k]}')"
expect 201
Q_OPEN=$(j '.data.id')
ok "questions mcq=$Q_MCQ short=$Q_SHORT show_work=$Q_WORK open=$Q_OPEN"

step "layout is refused before the rubrics are approved"
api POST "/assignments/$ASSIGNMENT_ID/layout"
expect 422
check '.code == "rubric_not_approved"'

step "rubric draft without any AI key -> 422 ai_key_missing"
api POST "/questions/$Q_OPEN/rubric/draft"
expect 422
check '.code == "ai_key_missing"'

step "approve rubrics by hand (show_work steps, open criteria)"
api PUT "/questions/$Q_WORK/rubric" '{"criteria":[{"description":"ตั้งการคูณ 4 × 15 ได้ถูกต้อง","points":3,"is_core":true},{"description":"ตอบ 60 ผล พร้อมหน่วย","points":2}],"reference_steps":["ส้ม 4 ลัง ลังละ 15 ผล","4 × 15 = 60","ตอบ 60 ผล"]}'
expect 200
api PUT "/questions/$Q_OPEN/rubric" '{"criteria":[{"description":"บอกว่าการคูณคือการบวกจำนวนเดิมซ้ำ","points":2,"is_core":true},{"description":"ยกตัวอย่างถูกต้อง เช่น 3 × 4 = 4 + 4 + 4","points":2}]}'
expect 200
ok "rubrics approved"

step "layout"
api POST "/assignments/$ASSIGNMENT_ID/layout"
expect 201
LAYOUT_VERSION=$(j '.data.version')
api GET "/assignments/$ASSIGNMENT_ID/layouts?version=$LAYOUT_VERSION"
expect 200
jq '.data' <<<"$BODY" >"$WORK/layout.json"
PAGE_COUNT=$(jq '.page_count' "$WORK/layout.json")
check '[.data.pages[].regions[].question_id] | sort == ([ '"$Q_MCQ,$Q_SHORT,$Q_WORK,$Q_OPEN"' ] | sort)'
api POST "/assignments/$ASSIGNMENT_ID/layout"
expect 200
ok "layout v$LAYOUT_VERSION, $PAGE_COUNT page(s); rebuilding unchanged -> 200"

step "worksheet print"
api POST "/assignments/$ASSIGNMENT_ID/worksheets"
expect 202
PRINT_ID=$(j '.data.id')
work
api GET "/worksheet-prints/$PRINT_ID"
expect 200
check '.data.status == "ready" and .data.download_url != null'
curl -s -f -H "Authorization: Bearer $TOKEN" -o "$WORK/worksheets.pdf" "http://127.0.0.1:${PORT}$(j '.data.download_url')" || fail "PDF download failed"
PDF_BYTES=$(wc -c <"$WORK/worksheets.pdf" | tr -d ' ')
PDF_PAGES=$(php -r '$s = file_get_contents($argv[1]); echo preg_match_all("#/Type\s*/Page(?![s])#", $s);' "$WORK/worksheets.pdf")
head -c 5 "$WORK/worksheets.pdf" | grep -q '%PDF-' || fail "not a PDF"
[ "$PDF_PAGES" -eq $((3 * PAGE_COUNT)) ] || fail "PDF has $PDF_PAGES pages, expected $((3 * PAGE_COUNT))"
ok "PDF ${PDF_BYTES} bytes, ${PDF_PAGES} pages (3 students x ${PAGE_COUNT})"

# ---------------------------------------------------------------- scans
# Student 1 gets the mcq and the short answer wrong (low mastery on SKILL1 ->
# practice recommendation later); students 2 and 3 answer correctly.
answers() { # $1 option, $2 short, $3 final, $4 tag
  jq -nc --arg o "$1" --arg s "$2" --arg f "$3" --arg t "$4" \
    --arg qm "$Q_MCQ" --arg qs "$Q_SHORT" --arg qw "$Q_WORK" --arg qo "$Q_OPEN" \
    '{($qm): {option:$o}, ($qs): {text:$s, cnn:$s}, ($qw): {lines:["4 ลัง ลังละ 15 ผล", ("4 × 15 = " + $f), ("ตอบ " + $f + " ผล"), $t], final:$f, cnn:$f},
      ($qo): {lines:["การคูณคือการบวกจำนวนเดิมซ้ำ ๆ", "เช่น 3 × 4 = 4 + 4 + 4 = 12", $t]}}'
}
upload_pages() { # $1 student id, $2 answers json file -> uploads every page
  local sid=$1 dir="$WORK/scan-$1" page files
  php tools/smoke-scan.php "$WORK/layout.json" "$sid" "$2" "$dir" >"$WORK/pages-$sid"
  while read -r page; do
    files=(-F "meta=<$page/meta.json" -F "page=@$page/page.webp;type=image/webp")
    for f in "$page"/crop_*.webp; do
      files+=(-F "$(basename "$f" .webp)=@$f;type=image/webp")
    done
    STATUS=$(curl -s -o "$WORK/body" -w '%{http_code}' -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" "${files[@]}" "$BASE/scans")
    BODY=$(cat "$WORK/body")
    expect 201
    check '.state == "active"'
    # a retry with the same client_scan_id answers 200 with the same body
    if [ "$sid" = "${STUDENTS[0]}" ]; then
      STATUS=$(curl -s -o "$WORK/body" -w '%{http_code}' -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" "${files[@]}" "$BASE/scans")
      BODY=$(cat "$WORK/body")
      expect 200
    fi
  done <"$WORK/pages-$sid"
}
step "scan upload for 3 students (no AI key configured)"
answers A 120 64 "นักเรียน 1" >"$WORK/answers1.json"
answers B 125 60 "นักเรียน 2" >"$WORK/answers2.json"
answers B 125 60 "นักเรียน 3 [ครบ]" >"$WORK/answers3.json"
upload_pages "${STUDENTS[0]}" "$WORK/answers1.json"
upload_pages "${STUDENTS[1]}" "$WORK/answers2.json"
upload_pages "${STUDENTS[2]}" "$WORK/answers3.json"
ok "uploaded $((3 * PAGE_COUNT)) page(s); duplicate client_scan_id -> 200"

step "bad QR is refused"
jq '.qr = "EV1.1.1.1.1.AAAAAAAA" | .client_scan_id = "00000000-0000-4000-8000-000000000001"' "$(head -1 "$WORK/pages-${STUDENTS[0]}")/meta.json" >"$WORK/badmeta.json"
P1=$(head -1 "$WORK/pages-${STUDENTS[0]}")
files=(-F "meta=<$WORK/badmeta.json" -F "page=@$P1/page.webp;type=image/webp")
for f in "$P1"/crop_*.webp; do files+=(-F "$(basename "$f" .webp)=@$f;type=image/webp"); done
STATUS=$(curl -s -o "$WORK/body" -w '%{http_code}' -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" "${files[@]}" "$BASE/scans")
BODY=$(cat "$WORK/body")
expect 422
check '.code == "qr_invalid"'
ok "422 qr_invalid"

step "worker without a key -> manual / ai_key_missing"
work
api GET "/assignments/$ASSIGNMENT_ID/review-queue?per_page=50"
expect 200
check '.meta.missing_ai_key_count == 9'
check '[.data[] | select(.question_type != "mcq")] | all(.grading_state == "manual" and .manual_reason == "ai_key_missing")'
check '[.data[] | select(.question_type == "mcq")] | length == 3 and all(.ai_score != null)'
ok "9 responses wait for a key, mcq graded on ingest"

step "requeue without a key -> 422; invalid key -> 422; fake key accepted"
api POST "/assignments/$ASSIGNMENT_ID/requeue-missing-key"
expect 422
check '.code == "ai_key_missing"'
api PUT /me/ai-key '{"gemini_api_key":"fake-invalid-key-000000000000"}'
expect 422
check '.code == "ai_key_invalid"'
api PUT /me/ai-key '{"gemini_api_key":"fake-smoke-key-1234567890abcd"}'
expect 200
api GET /me/ai-key
expect 200
check '(.data // .) | .configured == true and .key_last4 == "abcd"'
grep -q 'fake-smoke-key-1234567890abcd' <<<"$BODY" && fail "GET /me/ai-key leaks the key"
ok "key stored, last4 abcd"

step "requeue + worker -> graded"
api POST "/assignments/$ASSIGNMENT_ID/requeue-missing-key"
expect 200 202
ok "$(j -c '.data // .')"
work
api GET "/assignments/$ASSIGNMENT_ID/review-queue?per_page=50"
expect 200
jq '.' <<<"$BODY" >"$WORK/queue.json"
check '.meta.missing_ai_key_count == 0'
check '(.data | length) == 12 and all(.data[]; .ai_score != null and .review_priority != null and .priority_band != null)'
ok "$(j '[.data[] | "\(.question_type):\(.ai_score)/\(.max_points) \(.priority_band) p=\(.review_priority)"] | join(", ")')"

step "response detail has extraction, why and explanation"
NONFULL=$(j '[.data[] | select(.question_type != "mcq" and (.ai_score < .max_points))][0].id // empty')
[ -n "$NONFULL" ] || NONFULL=$(j '[.data[] | select(.question_type != "mcq")][0].id')
api GET "/responses/$NONFULL"
expect 200
check '.data.extraction != null and .data.fuzzy_trace != null and .data.why != null'
check '(.data.ai_score == .data.question.max_points) or ((.data.explanation // "") | length > 0)'
ok "response #$NONFULL: $(j '.data.why | if type == "array" then .[0] else . end')"
curl -s -f -o "$WORK/crop.webp" -H "Authorization: Bearer $TOKEN" "$BASE/responses/$NONFULL/crop" || fail "teacher crop download failed"

step "override one score (reason required)"
# override student 3's short answer; student 1 keeps a weak SKILL1 for the practice step
OVR=$(jq -r --argjson s "${STUDENTS[2]}" '[.data[] | select(.question_type == "short" and .student.id == $s)][0] | "\(.id) \(.ai_score) \(.max_points)"' "$WORK/queue.json")
read -r OVR_ID OVR_AI OVR_MAX <<<"$OVR"
NEW_SCORE=$(php -r 'echo $argv[1] >= $argv[2] ? $argv[2] - 1 : $argv[2];' "$OVR_AI" "$OVR_MAX")
api PATCH "/responses/$OVR_ID" "$(jq -nc --argjson s "$NEW_SCORE" '{final_score:$s, final_understanding:"partial"}')"
expect 422
api PATCH "/responses/$OVR_ID" "$(jq -nc --argjson s "$NEW_SCORE" '{final_score:$s, final_understanding:"partial", reason:"ครูอ่านลายมือแล้วตัดสินใหม่ (smoke)"}')"
expect 200
ok "response #$OVR_ID $OVR_AI -> $NEW_SCORE"

step "approve-confident, then review the rest as-is"
api POST "/assignments/$ASSIGNMENT_ID/approve-confident"
expect 200
ok "approved $(j '.data.approved')"
api GET "/assignments/$ASSIGNMENT_ID/review-queue?per_page=50"
expect 200
for row in $(j '.data[] | select(.reviewed_at == null) | @base64'); do
  r=$(base64 --decode <<<"$row")
  rid=$(jq -r '.id' <<<"$r")
  api PATCH "/responses/$rid" "$(jq -c '{final_score:.ai_score, final_understanding:(.ai_understanding // "partial")}' <<<"$r")"
  expect 200
done
api GET "/assignments/$ASSIGNMENT_ID/review-queue?per_page=50"
check 'all(.data[]; .reviewed_at != null)'
ok "all 12 reviewed"

step "publish"
api POST "/assignments/$ASSIGNMENT_ID/publish"
expect 200
check '.data.published == 3'
work # notifications (LogNotifier), mastery listeners
ok "published 3"

# ---------------------------------------------------------------- student
step "student PIN login"
TOKEN=""
api POST /auth/student/pin "$(jq -nc --arg c "$CLASS_CODE" --arg p "$PIN1" '{class_code:$c, student_number:1, pin:$p}')"
expect 200
STUDENT_TOKEN=$(j '.token')
api POST /auth/student/pin "$(jq -nc --arg c "$CLASS_CODE" '{class_code:$c, student_number:2, pin:"000000"}')"
expect 422 401 403
TOKEN=$STUDENT_TOKEN
ok "student 1 logged in; wrong PIN refused ($STATUS)"

step "student results"
api GET /student/results
expect 200
check '(.data | length) == 1'
SUBMISSION_ID=$(j '.data[0].submission_id')
api GET "/student/results/$SUBMISSION_ID"
expect 200
jq '.' <<<"$BODY" >"$WORK/result1.json"
check '(.data.responses | length) == 4 and all(.data.responses[]; .final_score != null)'
check 'any(.data.responses[]; (.explanation // "") | length > 0)'
grep -q '"ai_score"' <<<"$BODY" && fail "student result exposes ai_score"
ok "total $(j '.data.total_score')/$(j '.data.max_score')"
APPEAL_RID=$(j '[.data.responses[] | select(.can_appeal == true and .type == "open")][0].response_id')
[ "$APPEAL_RID" != "null" ] || fail "no appealable open response"
curl -s -f -o /dev/null -H "Authorization: Bearer $TOKEN" "$BASE/responses/$APPEAL_RID/crop" || fail "student crop download failed"
api GET "/assignments/$ASSIGNMENT_ID/review-queue"
expect 403 404
ok "student cannot open the review queue ($STATUS)"

step "appeal"
api POST "/student/responses/$APPEAL_RID/appeal" '{"reason":"หนูคิดว่าตอบถูกค่ะ (smoke)"}'
expect 201
api POST "/student/responses/$APPEAL_RID/appeal" '{"reason":"ซ้ำ"}'
expect 409
check '.code == "appeal_exists"'
ok "appeal on response #$APPEAL_RID; second -> 409"

step "teacher resolves the appeal"
TOKEN=$TEACHER_TOKEN
api GET "/appeals?status=open&assignment_id=$ASSIGNMENT_ID"
expect 200
APPEAL_ID=$(j '.data[0].id')
[ "$APPEAL_ID" != "null" ] || fail "open appeal not listed"
MAXP=$(jq -r --argjson r "$APPEAL_RID" '.data.responses[] | select(.response_id == $r) | .max_points' "$WORK/result1.json")
api PATCH "/appeals/$APPEAL_ID" "$(jq -nc --argjson s "$MAXP" '{status:"accepted", teacher_note:"ตรวจใหม่แล้วให้คะแนนเต็ม", final_score:$s, final_understanding:"good"}')"
expect 200
api PATCH "/appeals/$APPEAL_ID" '{"status":"rejected"}'
expect 409
work
ok "appeal #$APPEAL_ID accepted (score $MAXP); second answer -> 409"

# ---------------------------------------------------------------- analytics
step "mastery and analytics"
api GET "/classrooms/$CLASSROOM_ID/mastery"
expect 200
check '(.data.cells // .cells | length) > 0'
api GET "/students/${STUDENTS[0]}/mastery"
expect 200
api GET "/assignments/$ASSIGNMENT_ID/analytics"
expect 200
check '(.data // .) | .published_count == 3 and (.items | length) == 4'
ok "p per item: $(j '[(.data // .).items[] | "\(.type)=\(.p)"] | join(", ")')"

# ---------------------------------------------------------------- practice
step "practice bank: generate (fake Gemini), approve, learning resource"
api POST "/skills/$SKILL1/practice-items/generate" '{"count":3}'
expect 202
work
api GET "/practice-items?skill=$SKILL1&status=draft"
expect 200
DRAFTS=($(j '.data[].id'))
[ "${#DRAFTS[@]}" -ge 1 ] || fail "no practice drafts generated"
for id in "${DRAFTS[@]}"; do
  api PATCH "/practice-items/$id" '{"status":"approved"}'
  expect 200
done
api POST "/skills/$SKILL1/resources" '{"title":"ทบทวนการคูณ (smoke)","url":"https://example.com/multiply"}'
expect 201
ok "approved ${#DRAFTS[@]} generated item(s)"

step "student practice recommendation + attempt"
TOKEN=$STUDENT_TOKEN
api GET /student/mastery
expect 200
check '(.data | length) > 0'
api GET /student/practice
expect 200
jq '.' <<<"$BODY" >"$WORK/practice.json"
ITEM=$(j '[.data[].items[]][0].id // empty')
[ -n "$ITEM" ] || fail "no practice recommendation for student 1: $(head -c 600 <<<"$BODY")"
grep -q '"answer_key"' <<<"$BODY" && fail "student practice exposes answer_key"
api POST "/student/practice/$ITEM/attempts" '{"answer":"42"}'
expect 201
check 'has("score_ratio") and has("mastery") or (.data | has("score_ratio"))'
api POST "/student/practice/$ITEM/attempts" '{"answer":"42"}'
expect 409
check '.code == "practice_already_attempted"'
ok "item #$ITEM attempted; repeat -> 409"

# ---------------------------------------------------------------- Phase 8: freeform + whole page
step "freeform assignment: the answer key is read once from a photo (fake Gemini)"
TOKEN=$TEACHER_TOKEN
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson k "$COURSE_ID" --argjson p "$PLAN_ID" \
  '{classroom_id:$c, course_id:$k, lesson_plan_id:$p, title:"การบ้านจากหนังสือ smoke", mode:"freeform", accept_late:true}')"
expect 201
check '.data.mode == "freeform" and .data.status == "draft"'
FREE_ID=$(j '.data.id')
jpeg "$WORK/key.jpg" "answer key ${RUN}"
upload /documents "$WORK/key.jpg"
expect 201
check '(.data | length) == 1 and .data[0].page_count == 1 and .data[0].estimate.input_tokens > 0'
DOC_ID=$(j '.data[0].id')
printf 'not a picture' >"$WORK/notes.txt"
upload /documents "$WORK/notes.txt"
expect 422
check '.code == "unsupported_file_type"'
api POST "/assignments/$FREE_ID/answer-key/estimate" "$(jq -nc --argjson d "$DOC_ID" '{document_ids:[$d]}')"
expect 200
check '.data.cached == false and .data.pages == 1'
api POST "/assignments/$FREE_ID/answer-key/approve"
expect 422
api POST "/assignments/$FREE_ID/answer-key/extract" "$(jq -nc --argjson d "$DOC_ID" '{document_ids:[$d]}')"
expect 202 200
work
api GET "/assignments/$FREE_ID/answer-key"
expect 200
check '.data.extraction_status == "done" and .data.key_origin == "document" and (.data.questions | length) >= 1'
jq '.' <<<"$BODY" >"$WORK/answer-key.json"
ok "$(j '[.data.questions[] | .type] | join(", ")') read from document #$DOC_ID; incomplete: $(j '.data.incomplete_questions | map(tostring) | join(",")')"

step "approve drafted rubrics, map indicators from the lesson plan, approve the key"
for row in $(jq -r '.data.questions[] | select(.key_complete != true) | @base64' "$WORK/answer-key.json"); do
  q=$(base64 --decode <<<"$row")
  qid=$(jq -r '.id' <<<"$q")
  api PUT "/questions/$qid/rubric" "$(jq -c '{criteria: [(.rubric_criteria // [])[] | {description, points, is_core}]} + (if .type == "show_work" then {reference_steps: (.answer_key.reference_steps // ["ตั้งโจทย์", "คำนวณ", "ตอบ"])} else {} end)' <<<"$q")"
  expect 200
done
api POST "/assignments/$FREE_ID/indicator-suggestions"
expect 202
work
api GET "/assignments/$FREE_ID/indicator-suggestions"
expect 200
jq '.' <<<"$BODY" >"$WORK/suggestions.json"
MAPPING=$(jq -c --argjson k "$SKILL1" '{questions: [.data.questions[] | {question_id: (.question_id // .id), skill_ids: ([.suggestions[]?.skill.id] | if length == 0 then [$k] else . end)}]}' "$WORK/suggestions.json")
api PUT "/assignments/$FREE_ID/indicator-mapping" "$MAPPING"
expect 200
check '.data.unmapped_question_count == 0'
api POST "/assignments/$FREE_ID/answer-key/approve"
expect 200
api GET "/assignments/$FREE_ID"
expect 200
check '.data.status == "ready" and .data.key_approved_at != null'
api POST "/assignments/$FREE_ID/answer-key/estimate" "$(jq -nc --argjson d "$DOC_ID" '{document_ids:[$d]}')"
expect 200
check '.data.cached == true'
ok "key approved, indicators mapped: $(jq -c '[.questions[].skill_ids | length]' <<<"$MAPPING"); the same file now reads from the cache"

step "teacher uploads a whole page for student 2 (no marker, no QR)"
jpeg "$WORK/page-s2.jpg" "student 2 page 1 ${RUN}"
upload "/assignments/$FREE_ID/students/${STUDENTS[1]}/pages" "$WORK/page-s2.jpg"
expect 201
check '(.data.pages | length) == 1 and .data.waiting_key == false and .data.grading == true'
S2_SUB=$(j '.data.submission_id')
upload "/assignments/$FREE_ID/students/${STUDENTS[1]}/pages" "$WORK/notes.txt"
expect 422
check '.code == "unsupported_file_type"'
ok "submission #$S2_SUB queued for grading; a text file -> 422"

step "student 1 hands in two pages in the app; a draft assignment is refused"
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson k "$COURSE_ID" '{classroom_id:$c, course_id:$k, title:"ยังไม่อนุมัติเฉลย", mode:"freeform"}')"
expect 201
DRAFT_ID=$(j '.data.id')
TOKEN=$STUDENT_TOKEN
api GET /student/assignments
expect 200
check "any(.data[]; .id == $FREE_ID and .can_submit == true and .status == \"not_submitted\")"
check "all(.data[]; .id != $DRAFT_ID)"
jpeg "$WORK/page-s1a.jpg" "student 1 page 1 ${RUN}"
jpeg "$WORK/page-s1b.jpg" "student 1 page 2 ${RUN}"
upload "/student/assignments/$FREE_ID/submission" "$WORK/page-s1a.jpg" "$WORK/page-s1b.jpg"
expect 201
check '.data.status == "submitted" and (.data.pages | length) == 2'
upload "/student/assignments/$DRAFT_ID/submission" "$WORK/page-s1a.jpg"
expect 409
check '.code == "assignment_not_ready"'
api GET /student/assignments
check "any(.data[]; .id == $FREE_ID and .status == \"submitted\")"
ok "handed in at $(j ".data[] | select(.id == $FREE_ID) | .submitted_at"); draft -> 409 assignment_not_ready"

step "worker grades each page in one call; review, publish"
TOKEN=$TEACHER_TOKEN
work
api GET "/assignments/$FREE_ID/review-queue?per_page=50"
expect 200
jq '.' <<<"$BODY" >"$WORK/free-queue.json"
QCOUNT=$(jq '.data.questions | length' "$WORK/answer-key.json")
check "(.data | length) == $((2 * QCOUNT)) and all(.data[]; .ai_score != null or .grading_state == \"manual\")"
RID=$(j '.data[0].id')
api GET "/responses/$RID"
expect 200
check '.data.submission_page_id != null and .data.page_image_url != null and .data.extraction != null'
curl -s -f -o "$WORK/teacher-page.img" -H "Authorization: Bearer $TOKEN" "http://127.0.0.1:${PORT}$(j '.data.page_image_url')" || fail "teacher page image download failed"
api POST "/assignments/$FREE_ID/approve-confident"
expect 200
api GET "/assignments/$FREE_ID/review-queue?per_page=50"
for row in $(j '.data[] | select(.reviewed_at == null) | @base64'); do
  r=$(base64 --decode <<<"$row")
  api PATCH "/responses/$(jq -r '.id' <<<"$r")" "$(jq -c '{final_score:(.ai_score // 0), final_understanding:(.ai_understanding // "partial"), reason:"smoke: ตรวจทานแล้ว"}' <<<"$r")"
  expect 200
done
api POST "/assignments/$FREE_ID/publish"
expect 200
check '.data.published == 2'
work
ok "$(jq -r '[.data[] | "\(.question_type):\(.ai_score)/\(.max_points)"] | join(", ")' "$WORK/free-queue.json")"

step "student sees the whole-page result and the page image"
TOKEN=$STUDENT_TOKEN
api GET /student/results
expect 200
FREE_SUB=$(j "[.data[] | select(.assignment_id == $FREE_ID or .assignment.id == $FREE_ID)][0].submission_id // empty")
[ -n "$FREE_SUB" ] || fail "no published result for the freeform assignment: $(head -c 600 <<<"$BODY")"
api GET "/student/results/$FREE_SUB"
expect 200
jq '.' <<<"$BODY" >"$WORK/free-result.json"
SPAGE=$(j '[.data.responses[].page_image_url // empty][0] // empty')
[ -n "$SPAGE" ] || fail "the whole-page result has no page_image_url"
curl -s -f -o "$WORK/page.img" -H "Authorization: Bearer $TOKEN" "http://127.0.0.1:${PORT}$SPAGE" || fail "student page image download failed"
cmp -s "$WORK/page.img" "$WORK/page-s1a.jpg" || cmp -s "$WORK/page.img" "$WORK/page-s1b.jpg" || fail "the page image is not the file the student handed in"
ok "result $(j '.data.total_score')/$(j '.data.max_score'); page image = the handed-in file"

# ---------------------------------------------------------------- Phase 9: charts + analysis
step "charts (teacher)"
TOKEN=$TEACHER_TOKEN
for path in \
  "/courses/$COURSE_ID/mastery-summary?classroom_id=$CLASSROOM_ID&axis=standard" \
  "/courses/$COURSE_ID/mastery-summary?classroom_id=$CLASSROOM_ID&axis=unit" \
  "/courses/$COURSE_ID/mastery-summary?classroom_id=$CLASSROOM_ID&student_id=${STUDENTS[0]}" \
  "/classrooms/$CLASSROOM_ID/indicator-pass-rate?course_id=$COURSE_ID" \
  "/classrooms/$CLASSROOM_ID/mastery?course_id=$COURSE_ID&unit_id=$UNIT_ID" \
  "/students/${STUDENTS[0]}/indicator-progress?skill_ids=$SKILL1,$SKILL2" \
  "/assignments/$FREE_ID/score-distribution" \
  "/courses/$COURSE_ID/plan-progress?classroom_id=$CLASSROOM_ID"; do
  api GET "$path"
  expect 200
  printf '   GET %-80s %s %s bytes\n' "$path" "$STATUS" "${#BODY}"
done
api GET "/assignments/$FREE_ID/score-distribution"
check '[.. | numbers] | length > 0'
api GET /teacher/attention
expect 200
ok "8 chart endpoints; attention $(j -c '.data')"

step "charts (student, own data only)"
TOKEN=$STUDENT_TOKEN
api GET /student/courses
expect 200
check "any(.data[]; .id == $COURSE_ID)"
api GET "/student/courses/$COURSE_ID/mastery-summary?axis=standard"
expect 200
api GET "/student/indicator-progress?skill_ids=$SKILL1,$SKILL2"
expect 200
api GET "/courses/$COURSE_ID/mastery-summary?classroom_id=$CLASSROOM_ID"
expect 403 404
ok "student charts; teacher chart -> $STATUS"

step "AI analysis: run now, edit, approve, student sees the shared text"
TOKEN=$TEACHER_TOKEN
api POST "/students/${STUDENTS[0]}/analysis/run" "$(jq -nc --argjson c "$CLASSROOM_ID" '{classroom_id:$c}')"
expect 200
check '.data.id != null and (.data.student_text // "" | length) > 0'
ANALYSIS_ID=$(j '.data.id')
api GET "/students/${STUDENTS[0]}/analysis?classroom_id=$CLASSROOM_ID"
expect 200
check ".data.id == $ANALYSIS_ID"
api PATCH "/analyses/$ANALYSIS_ID" '{"student_text":"หนูทำโจทย์การคูณได้ดีขึ้นมาก ลองฝึกแสดงวิธีทำให้ครบทุกขั้นนะ (smoke)"}'
expect 200
api POST "/analyses/$ANALYSIS_ID/approve"
expect 200
api GET "/classrooms/$CLASSROOM_ID/analyses"
expect 200
TOKEN=$STUDENT_TOKEN
api GET /student/analysis
expect 200
check 'tostring | contains("(smoke)")'
grep -q '"teacher_text"' <<<"$BODY" && fail "student analysis exposes the teacher text"
ok "analysis #$ANALYSIS_ID approved and shared"

# ---------------------------------------------------------------- Phase 10/11: gradebook + exams
# DESIGN §22, §23: the course's gradebook from a template -> an app-graded
# exam (two versions, mcq + numeric, indicators, key, approval) -> key sheet,
# booklet and answer sheets printed -> scan kit -> answer sheets read "by the
# phone" (tools/smoke-exam-sheet.php signs the EVX1 QR and writes the fills)
# -> a double mark resolved by the teacher -> publish -> option analysis,
# exam observations in mastery, the student's exam result -> a manual exam
# and a custom item with scores -> the gradebook table, CSV, publish -> the
# student's grade. The exam never calls Gemini for grading (§22.1).
step "gradebook: categories from a template, cutoffs"
TOKEN=$TEACHER_TOKEN
api GET /gradebook/templates
expect 200
check 'any(.data[]; .key == "collect_final")'
api GET "/courses/$COURSE_ID/gradebook/settings"
expect 200
check '.data.configured == false'
api PUT "/courses/$COURSE_ID/gradebook/categories" '{"template":"collect_final"}'
expect 200
check '.data.configured == true and (.data.categories | length) == 2'
COLLECT_CAT=$(j '.data.categories[] | select(.is_homework_default == true) | .id')
FINAL_CAT=$(j '.data.categories[] | select(.is_homework_default == false) | .id')
api PUT "/courses/$COURSE_ID/gradebook/categories" '{"template":"collect_final"}'
expect 409
check '.code == "gradebook_configured"'
api PUT "/courses/$COURSE_ID/gradebook/cutoffs" '{"cutoffs":[80,75,70,65,60,55,50]}'
expect 200
ok "categories คะแนนเก็บ #$COLLECT_CAT (homework default), ปลายภาค #$FINAL_CAT; template again -> 409"

step "exam: create (two versions), sections, questions, key, indicators, approve"
EXAM_DUE="$(date -u -v+1d +%Y-%m-%dT02:00:00Z 2>/dev/null || date -u -d '+1 day' +%Y-%m-%dT02:00:00Z)"
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson k "$COURSE_ID" --arg d "$EXAM_DUE" \
  '{classroom_id:$c, course_id:$k, title:"สอบย่อย smoke", kind:"exam", grading_method:"app", version_count:2, due_at:$d, duration_minutes:30}')"
expect 422
check '.errors.gradebook_category_id != null'
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson k "$COURSE_ID" --arg d "$EXAM_DUE" --argjson g "$FINAL_CAT" \
  '{classroom_id:$c, course_id:$k, title:"สอบย่อย smoke", kind:"exam", grading_method:"app", version_count:2, due_at:$d, duration_minutes:30, gradebook_category_id:$g}')"
expect 201
EXAM_ID=$(j '.data.id')
api POST "/exams/$EXAM_ID/sections" '{"title":"ตอนที่ 1 ปรนัย","type":"mcq","option_count":4,"question_count":4}'
expect 201
api POST "/exams/$EXAM_ID/sections" '{"title":"ตอนที่ 2 เติมตัวเลข","type":"numeric","numeric":{"digits":2},"question_count":1}'
expect 201
api GET "/exams/$EXAM_ID"
expect 200
check '.data.key_complete == false and ([.data.sections[].questions[]] | length) == 5'
EXAM_QS=($(j '[.data.sections[].questions[]] | sort_by(.position) | .[].id'))
KEYS=(1 2 3 1)
for n in 0 1 2 3; do
  api PATCH "/questions/${EXAM_QS[$n]}" "$(jq -nc --arg p "ข้อ $((n + 1)): 1$((n + 2)) × $((n + 3)) เท่ากับเท่าไร" --argjson a "${KEYS[$n]}" \
    '{prompt_text:$p, options:[{text:"ตัวเลือกแรก"},{text:"ตัวเลือกที่สอง"},{text:"ตัวเลือกที่สาม"},{text:"ตัวเลือกที่สี่"}], answer_key:{accepted_options:[$a]}, approve:true}')"
  expect 200
done
api PATCH "/questions/${EXAM_QS[4]}" '{"prompt_text":"3 × 4 เท่ากับเท่าไร","approve":true}'
expect 200
api PUT "/exams/$EXAM_ID/answer-key" "$(jq -nc --argjson q "${EXAM_QS[4]}" '{answers:[{question_id:$q, accepted_values:["12"]}]}')"
expect 200
check '.data.key_complete == true and .data.incomplete_questions == []'
api GET "/assignments/$EXAM_ID/indicator-suggestions"
expect 200
check '.data.indicator_source == "course" and (.data.plan_indicators | length) >= 1 and .data.unmapped_question_count == 5'
api PUT "/assignments/$EXAM_ID/indicator-mapping" "$(jq -nc --argjson a "${EXAM_QS[0]}" --argjson b "${EXAM_QS[1]}" --argjson s1 "$SKILL1" --argjson s2 "$SKILL2" \
  '{questions:[{question_id:$a, skill_ids:[$s1]}, {question_id:$b, skill_ids:[$s2]}]}')"
expect 200
check '.data.changed_question_count == 2 and .data.unmapped_question_count == 3'
api POST "/assignments/$EXAM_ID/answer-key/approve"
expect 200
work # the suggestion queued on approval (fake Gemini) for the three unmapped questions
api GET "/assignments/$EXAM_ID/indicator-suggestions"
expect 200
check '.data.status == "done"'
SUGGESTED=$(j '.data.suggested_question_count')
api GET "/exams/$EXAM_ID/versions"
expect 200
check '(.data.versions | length) == 2'
ok "exam #$EXAM_ID: 5 questions, key approved, 2 mapped; AI suggested for $SUGGESTED question(s) on approval"

step "exam: print key sheet, booklet and answer sheets (structure locks)"
EXAM_PRINTS=()
for body in '{"kind":"key_sheet"}' '{"kind":"exam_booklet","version_no":2}' '{"kind":"answer_sheet"}'; do
  api POST "/exams/$EXAM_ID/prints" "$body"
  expect 202
  EXAM_PRINTS+=("$(j '.data.id')")
done
work
for id in "${EXAM_PRINTS[@]}"; do
  api GET "/worksheet-prints/$id"
  expect 200
  check '.data.status == "ready"'
done
api GET "/exams/$EXAM_ID"
expect 200
check '.data.structure_locked_at != null'
api POST "/exams/$EXAM_ID/versions/reshuffle"
expect 409
check '.code == "exam_structure_locked"'
ok "prints ${EXAM_PRINTS[*]} done; reshuffle after printing -> 409"

step "exam: scan kit, answer sheets read on the phone (version ก right, ข one wrong, ข a double mark)"
api GET "/exams/$EXAM_ID/scan-kit"
expect 200
jq '.data' <<<"$BODY" >"$WORK/exam-kit.json"
check '.data.version_count == 2 and (.data.versions | length) == 2 and (.data.roster | length) == 3'
# marks.json of a version: every sheet row right; $2 = sheet_no answered wrong, $3 = sheet_no double-marked
exam_marks() {
  jq -c --argjson v "$1" --argjson wrong "$2" --argjson dbl "$3" '
    [.versions[] | select(.version_no == $v) | .key[] |
      {key: (.sheet_no | tostring), value: (
        if .type == "numeric" then .accepted_values[0]
        elif .sheet_no == $wrong then (.accepted_options[0] % 4 + 1)
        elif .sheet_no == $dbl then [.accepted_options[0], (.accepted_options[0] % 4 + 1)]
        else .accepted_options[0] end)}] | from_entries' "$WORK/exam-kit.json"
}
upload_exam_sheet() { # $1 student id, $2 version, $3 marks json
  local dir="$WORK/exam-$1" page
  echo "$3" >"$WORK/exam-marks-$1.json"
  php tools/smoke-exam-sheet.php "$WORK/exam-kit.json" "$1" "$2" "$WORK/exam-marks-$1.json" "$dir" >"$WORK/exam-pages-$1"
  while read -r page; do
    STATUS=$(curl -s -o "$WORK/body" -w '%{http_code}' -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" \
      -F "meta=<$page/meta.json" -F "page=@$page/page.webp;type=image/webp" "$BASE/exam-sheets")
    BODY=$(cat "$WORK/body")
    expect 201
  done <"$WORK/exam-pages-$1"
}
upload_exam_sheet "${STUDENTS[0]}" 1 "$(exam_marks 1 0 0)"
check '(.data // .) | .score == .max_score and .needs_version == false'
upload_exam_sheet "${STUDENTS[1]}" 2 "$(exam_marks 2 1 0)"
check '(.data // .) | .score == .max_score - 1'
upload_exam_sheet "${STUDENTS[2]}" 2 "$(exam_marks 2 0 2)"
check '(.data // .) | (.doubts | length) >= 1'
api GET "/exams/$EXAM_ID/sheet-status"
expect 200
check '.summary.scanned == 3 and .summary.missing_numbers == []'
ok "3 sheets scored by code on upload; sheet status $(jq -c '.summary | {scanned, ready_to_publish, waiting_review}' <<<"$BODY")"

step "exam: the teacher reads the double mark, publishes the class"
api GET "/assignments/$EXAM_ID/review-queue?per_page=100"
expect 200
DOUBT_RID=$(j '[.data[] | select(.exam_answer != null and (.exam_answer.doubts | index("double_mark")) and .reviewed_at == null)][0].id')
[ "$DOUBT_RID" != "null" ] || fail "no double mark waiting for review"
api GET "/responses/$DOUBT_RID"
expect 200
INTENDED=$(j '(.data // .).exam.selected[0]')
api POST "/exam-responses/$DOUBT_RID/resolve" "$(jq -nc --argjson o "$INTENDED" '{options:[$o]}')"
expect 200
api POST "/assignments/$EXAM_ID/publish"
expect 200
check '.data.published == 3'
work # mastery listeners, notifications
ok "response #$DOUBT_RID read as option $INTENDED; published 3"

step "exam: option analysis, item analysis, exam observations in mastery"
api GET "/exams/$EXAM_ID/option-analysis"
expect 200
check '.data.published_count == 3 and .data.groups_ready == false and (.data.questions | length) == 5'
check '.data.questions[0].options[0].correct == true and ([.data.questions[0].options[].count] | add) + .data.questions[0].blank.count + .data.questions[0].multiple.count == 3'
check '.data.questions[4].options == [] and .data.questions[4].blank.count == 0'
OPT_SUMMARY=$(jq -c '[.data.questions[] | {n: .position, p}]' <<<"$BODY")
api GET "/assignments/$EXAM_ID/analytics"
expect 200
check '(.data // .) | .published_count == 3 and (.items | length) == 5'
api GET "/students/${STUDENTS[0]}/indicator-progress?skill_ids=$SKILL1,$SKILL2"
expect 200
check '[.data.series[].points[] | select(.source == "exam")] | length >= 1'
ok "p per question $OPT_SUMMARY; exam observations recorded"

step "exam: the student's result (key hidden by default)"
TOKEN=$STUDENT_TOKEN
api GET /student/results
expect 200
EXAM_SUB=$(jq -r --argjson a "$EXAM_ID" '[.data[] | select(.assignment_id == $a)][0].submission_id' <<<"$BODY")
[ "$EXAM_SUB" != "null" ] || fail "the exam result is not listed for the student"
api GET "/student/results/$EXAM_SUB"
expect 200
check '(.data // .) | .kind == "exam" and .items == null and .total == .max'
EXAM_RESULT=$(j '(.data // .) | "\(.total)/\(.max) ชุด \(.version_label)"')
api GET /student/assignments
expect 200
check "all(.data[]; .id != $EXAM_ID)"
ok "student sees $EXAM_RESULT, no key, no exam in the to-do list"

step "manual exam and a custom gradebook item with scores"
TOKEN=$TEACHER_TOKEN
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson k "$COURSE_ID" --arg d "$EXAM_DUE" --argjson g "$FINAL_CAT" \
  '{classroom_id:$c, course_id:$k, title:"สอบปลายภาค (ครูตรวจเอง) smoke", kind:"exam", grading_method:"manual", manual_full_marks:20, due_at:$d, gradebook_category_id:$g}')"
expect 201
check '.data.status == "ready"'
MANUAL_ID=$(j '.data.id')
api POST "/assignments/$MANUAL_ID/answer-key/approve"
expect 422
check '.code == "exam_manual_grading"'
api PUT "/assignments/$MANUAL_ID/gradebook-scores" "$(jq -nc --argjson a "${STUDENTS[0]}" --argjson b "${STUDENTS[1]}" \
  '{scores:[{student_id:$a, score:18}, {student_id:$b, score:12.5}]}')"
expect 200
api PUT "/assignments/$MANUAL_ID/gradebook-scores" "$(jq -nc --argjson a "${STUDENTS[0]}" '{scores:[{student_id:$a, score:25}]}')"
expect 422
api POST "/assignments/$MANUAL_ID/gradebook-scores/fill-full"
expect 200
check '.data.filled == 1'
api PUT "/assignments/$EXAM_ID/gradebook-scores" "$(jq -nc --argjson a "${STUDENTS[0]}" '{scores:[{student_id:$a, score:3}]}')"
expect 422
check '.code == "score_from_app"'
api POST "/courses/$COURSE_ID/gradebook-items" "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson g "$COLLECT_CAT" \
  '{classroom_ids:[$c], category_id:$g, name:"งานกลุ่ม smoke", max_points:10}')"
expect 201
ITEM_ID=$(j '.data[0].id')
api PUT "/gradebook-items/$ITEM_ID/scores" "$(jq -nc --argjson a "${STUDENTS[0]}" '{scores:[{student_id:$a, score:9}]}')"
expect 200
api POST "/gradebook-items/$ITEM_ID/fill-full"
expect 200
check '.data.filled == 2'
ok "manual exam #$MANUAL_ID (18, 12.5, full), item #$ITEM_ID (9, full, full); app exam score -> 422 score_from_app"

step "gradebook: the table, CSV, publish; the student's grade"
api GET "/courses/$COURSE_ID/gradebook?classroom_id=$CLASSROOM_ID"
expect 200
check '.data.configured == true and (.data.rows | length) == 3'
check "any(.data.columns[]; .id == $EXAM_ID) and any(.data.columns[]; .id == $MANUAL_ID and .type == \"manual_exam\") and any(.data.columns[]; .type == \"custom\")"
GRADES=$(jq -c '[.data.rows[] | {n: .student_number, total_rounded, grade}]' <<<"$BODY")
curl -s -f -o "$WORK/gradebook.csv" -H "Authorization: Bearer $TOKEN" "$BASE/courses/$COURSE_ID/gradebook/export?classroom_id=$CLASSROOM_ID" \
  || fail "gradebook CSV export failed"
[ "$(wc -l <"$WORK/gradebook.csv")" -ge 4 ] || fail "gradebook CSV has fewer than 4 lines"
api POST "/courses/$COURSE_ID/gradebook/publish" "$(jq -nc --argjson c "$CLASSROOM_ID" '{classroom_id:$c}')"
expect 201
check '.data.student_count == 3'
work # grades_published notifications
TOKEN=$STUDENT_TOKEN
api GET /student/grades
expect 200
check "any(.data[]; .course.id == $COURSE_ID)"
api GET "/student/courses/$COURSE_ID/grade"
expect 200
check '.data.breakdown != null'
grep -q '"attendance_warning"' <<<"$BODY" && fail "the student's grade exposes the attendance warning"
ok "grades $GRADES; CSV $(wc -l <"$WORK/gradebook.csv" | tr -d ' ') lines; student 1 sees grade $(j '.data.grade // .data.special')"

# ---------------------------------------------------------------- ml
step "ml model (student and teacher)"
api GET "/ml/models/active?name=digit_crnn"
if [ "$STATUS" = "404" ]; then
  check '.code == "model_not_found"'
  ok "no active model registered (404 model_not_found)"
else
  expect 200
  SHA=$(j '.data.sha256 // .sha256')
  URL=$(j '.data.download_url // .download_url')
  curl -s -f -H "Authorization: Bearer $TOKEN" -o "$WORK/model.tflite" "http://127.0.0.1:${PORT}${URL#http://127.0.0.1:${PORT}}" || fail "model download failed"
  GOT=$(shasum -a 256 "$WORK/model.tflite" | cut -d' ' -f1)
  [ "$GOT" = "$SHA" ] || fail "model sha256 mismatch: $GOT != $SHA"
  TOKEN=$TEACHER_TOKEN
  api GET "/ml/models/active?name=digit_crnn"
  expect 200
  ok "$(j '(.data // .) | "\(.name) \(.version)"') sha256 verified"
fi

# ---------------------------------------------------------------- google
step "Google Classroom without credentials"
TOKEN=$TEACHER_TOKEN
api GET /google/status
expect 200
check '(.data // .) | .server_configured == false and .connected == false'
api POST /google/connect '{"server_auth_code":"4/fake-code"}'
expect 503
check '.code == "google_not_configured"'
api GET /google/courses
expect 503
# Every Google route but /google/status answers 503 google_not_configured
# before its own preconditions (classroom_not_linked, not_posted, 404, ...).
probe() { # METHOD PATH JSON
  local m=$1 p=$2 d=$3
  api "$m" "$p" "$d"
  local c; c=$(j '.code // empty')
  [ "$STATUS:$c" = "503:google_not_configured" ] || fail "$m $p -> $STATUS $c (want 503 google_not_configured)"
  printf '   %-6s %-48s %s %s\n' "$m" "$p" "$STATUS" "$c"
}
probe DELETE /google/disconnect ''
probe POST "/classrooms/$CLASSROOM_ID/google-link" '{"course_id":"123456"}'
probe GET "/classrooms/$CLASSROOM_ID/google-roster" ''
probe PUT "/classrooms/$CLASSROOM_ID/google-roster" '{"matches":[]}'
probe DELETE "/classrooms/$CLASSROOM_ID/google-link" ''
probe POST "/assignments/$ASSIGNMENT_ID/google-post" '{}'
probe GET "/assignments/$ASSIGNMENT_ID/google-submissions" ''
probe POST "/assignments/$ASSIGNMENT_ID/google-grades/retry" ''
probe POST /google-submissions/999999/return '{"reason":"ถ่ายใหม่"}'
probe GET /google/courses/123456/import-preview ''
probe POST /classrooms/import-google '{"course_id":"123456","name":"x","grade_level":5,"academic_year":2569,"students":[]}'
probe POST "/classrooms/$CLASSROOM_ID/google-roster/sync" ''
probe POST "/classrooms/$CLASSROOM_ID/google-sync" ''
probe POST /google-submissions/999999/accept-late ''
probe POST "/assignments/$ASSIGNMENT_ID/google-feedback/retry" ''
TOKEN=""
api GET /google/status
expect 401
TOKEN=$STUDENT_TOKEN
api GET /google/status
expect 403
ok "503 google_not_configured / 401 guest / 403 student"

# ---------------------------------------------------------------- google (faked, in process)
# tools/smoke-google.php runs the request (or a worker pass) inside its own
# PHP process with Google's OAuth, Classroom and Drive answered by Http::fake
# from the JSON state below; nothing reaches Google. Its worker pass is a
# plain queue:work, so no cron-wide sync round touches other linked
# classrooms of the dev database.
GSTATE="$WORK/google.json"
GCOURSE="61${RUN}"
jq -n --arg c "$GCOURSE" --arg r "$RUN" '{
  course: {id: $c, name: "คณิตศาสตร์", section: "ป.5/2"},
  students: [
    {id: ("g1-" + $r), name: "ด.ญ. ขวัญใจ ทดสอบ", email: ("kwan-" + $r + "@example.com")},
    {id: ("g2-" + $r), name: "ด.ช. กล้า ทดสอบ", email: ("kla-" + $r + "@example.com")},
    {id: ("g3-" + $r), name: "Teacher Assistant", email: ("ta-" + $r + "@example.com")}
  ],
  course_work: [], submissions: {}, drive: {}, recorded: []}' >"$GSTATE"
gapi() { # METHOD PATH [JSON] -> STATUS, BODY (Google faked, uses $TOKEN)
  STATUS=$(SMOKE_TOKEN="$TOKEN" SMOKE_BODY="$WORK/body" perl -e 'alarm 120; exec @ARGV' \
    php tools/smoke-google.php "$GSTATE" request "$1" "$2" "${3:-}" 2>>"$WORK/google.log") \
    || fail "smoke-google.php request failed: $(tail -20 "$WORK/google.log")"
  BODY=$(cat "$WORK/body")
}
gwork() {
  perl -e 'alarm 180; exec @ARGV' php tools/smoke-google.php "$GSTATE" work >>"$WORK/worker.log" 2>&1 \
    || fail "smoke-google.php worker failed, see log: $(tail -20 "$WORK/worker.log")"
}
gstate() { jq "$@" "$GSTATE" >"$GSTATE.tmp" && mv "$GSTATE.tmp" "$GSTATE"; }
grecorded() { jq -r "$1" "$GSTATE"; }

step "Google (faked): connect, course list, import preview"
TOKEN=$TEACHER_TOKEN
gapi POST /google/connect '{"server_auth_code":"4/smoke-one-time-code"}'
expect 200
check '(.data // .) | .connected == true'
gapi GET /google/status
expect 200
check '(.data // .) | .connected == true and .needs_reconnect == false'
gapi GET /google/courses
expect 200
check "any(.data[]; .course_id == \"$GCOURSE\" and .linked_classroom == null)"
gapi GET "/google/courses/$GCOURSE/import-preview"
expect 200
check '.data.grade_level_guess == 5 and (.data.students | length) == 3'
jq '.data' <<<"$BODY" >"$WORK/preview.json"
ok "$(j '.data.suggested_name'), $(j '.data.students | length') students, grade guess $(j '.data.grade_level_guess')"

step "import the classroom from Google Classroom (the assistant is left out)"
IMPORT=$(jq -c --arg ta "g3-$RUN" '{course_id, name: .suggested_name, grade_level: .grade_level_guess, academic_year,
  students: [.students[] | select(.google_user_id != $ta) | {google_user_id, student_number: .proposed_number}], removed: [$ta]}' "$WORK/preview.json")
gapi POST /classrooms/import-google "$IMPORT"
expect 201
check '(.data.students | length) == 2 and all(.data.students[]; (.pin | tostring | length) == 6)'
GCLASS_ID=$(j '.data.classroom.id')
gapi POST /classrooms/import-google "$IMPORT"
expect 409
check '.code == "course_already_linked"'
gapi GET /google/courses
check "any(.data[]; .course_id == \"$GCOURSE\" and .linked_classroom.id == $GCLASS_ID)"
ok "classroom #$GCLASS_ID; importing again -> 409 course_already_linked"

step "roster sync: a new student joins, the left-out assistant stays out"
gstate --arg r "$RUN" '.students += [{id: ("g4-" + $r), name: "ด.ญ. ใหม่ ทดสอบ", email: ("mai-" + $r + "@example.com")}]'
gapi POST "/classrooms/$GCLASS_ID/google-roster/sync"
expect 200
check '(.data.added | length) == 1 and (.data.added[0].pin | tostring | length) == 6 and (.data.left | length) == 0'
ADDED=$(j '.data.added[0] | "\(.name) (เลขที่ \(.student_number))"')
gapi GET "/classrooms/$GCLASS_ID/roster"
expect 200
check '(.data | length) == 3'
ok "added $ADDED"

step "bind the course to the imported classroom too"
api PUT "/courses/$COURSE_ID/classrooms" "$(jq -nc --argjson a "$CLASSROOM_ID" --argjson b "$GCLASS_ID" '{classroom_ids:[$a,$b]}')"
expect 200
ok "course #$COURSE_ID -> classrooms $CLASSROOM_ID, $GCLASS_ID"

step "sync now: coursework created on the Classroom website is mirrored, key drafted by AI"
CW="cw-$RUN"
jpeg "$WORK/sheet.jpg" "worksheet chapter 2 ${RUN}"
gstate --arg cw "$CW" --arg c "$GCOURSE" --arg now "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --arg p "$WORK/sheet.jpg" '
  .drive["f-sheet"] = {name: "ใบงานบทที่2.jpg", mime: "image/jpeg", path: $p}
  | .course_work = [{courseId: $c, id: $cw, title: "แบบฝึกหัดบทที่ 2 (smoke)", description: "ทำข้อ 1-4 แสดงวิธีทำ",
      state: "PUBLISHED", workType: "ASSIGNMENT", creationTime: $now, maxPoints: 10, associatedWithDeveloper: false,
      alternateLink: ("https://classroom.google.com/c/smoke/a/" + $cw),
      materials: [{driveFile: {driveFile: {id: "f-sheet", title: "ใบงานบทที่2.jpg"}, shareMode: "VIEW"}}]}]'
gapi POST "/classrooms/$GCLASS_ID/google-sync"
expect 202
gwork
gapi GET "/assignments?classroom_id=$GCLASS_ID"
expect 200
MIRROR_ID=$(j '[.data[] | select(.source == "classroom_web")][0].id // empty')
[ -n "$MIRROR_ID" ] || fail "no mirrored assignment: $(head -c 800 <<<"$BODY")"
gapi GET "/assignments/$MIRROR_ID/answer-key"
expect 200
check '.data.key_origin == "ai_draft" and .data.key_approved_at == null and (.data.questions | length) >= 1'
jq '.' <<<"$BODY" >"$WORK/mirror-key.json"
gapi GET /teacher/attention
check '.data.keys_pending >= 1'
ok "mirror #$MIRROR_ID with $(jq '.data.questions | length' "$WORK/mirror-key.json") AI-drafted question(s), waiting for approval"

step "a hand-in synced before the key is approved waits (waiting_key)"
jpeg "$WORK/hw-g1.jpg" "classroom hand-in ${RUN}"
gstate --arg cw "$CW" --arg c "$GCOURSE" --arg u "g1-$RUN" --arg p "$WORK/hw-g1.jpg" --arg now "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
  .drive["f-hw1"] = {name: "การบ้าน.jpg", mime: "image/jpeg", path: $p}
  | .submissions[$cw] = [{courseId: $c, courseWorkId: $cw, id: ("sub-" + $cw), userId: $u, state: "TURNED_IN", updateTime: $now,
      alternateLink: ("https://classroom.google.com/c/smoke/sub-" + $cw),
      assignmentSubmission: {attachments: [{driveFile: {id: "f-hw1", title: "การบ้าน.jpg"}}]}}]'
gapi POST "/classrooms/$GCLASS_ID/google-sync"
expect 202
gwork
gapi GET "/assignments/$MIRROR_ID/google-submissions"
expect 200
check 'any(.data[]; .state == "waiting_key")'
gapi GET "/assignments/$MIRROR_ID/review-queue?per_page=50"
check '(.data | length) == 0'
ok "hand-in stored, not graded before approval"

step "approve the mirror's key (course of the classroom) -> graded -> publish -> private announcement"
for row in $(jq -r '.data.questions[] | select(.key_complete != true) | @base64' "$WORK/mirror-key.json"); do
  q=$(base64 --decode <<<"$row")
  gapi PUT "/questions/$(jq -r '.id' <<<"$q")/rubric" "$(jq -c '{criteria: [(.rubric_criteria // [])[] | {description, points, is_core}]} + (if .type == "show_work" then {reference_steps: (.answer_key.reference_steps // ["ตั้งโจทย์", "คำนวณ", "ตอบ"])} else {} end)' <<<"$q")"
  expect 200
done
# The imported classroom has exactly one course, so the mirror took it (§20.1);
# with none or several the approval would answer 422 course_required.
gapi GET "/assignments/$MIRROR_ID"
check ".data.course_id == $COURSE_ID and .data.subject_id == $SUBJECT_ID"
gapi POST "/assignments/$MIRROR_ID/answer-key/approve" '{}'
expect 200
check '.data.status == "ready" and .data.key_approved_at != null'
gwork
gapi GET "/assignments/$MIRROR_ID/google-submissions"
check 'all(.data[]; .state != "waiting_key")'
gapi GET "/assignments/$MIRROR_ID/review-queue?per_page=50"
expect 200
check '(.data | length) >= 1 and all(.data[]; .ai_score != null or .grading_state == "manual")'
gapi POST "/assignments/$MIRROR_ID/approve-confident"
expect 200
gapi GET "/assignments/$MIRROR_ID/review-queue?per_page=50"
for row in $(j '.data[] | select(.reviewed_at == null) | @base64'); do
  r=$(base64 --decode <<<"$row")
  gapi PATCH "/responses/$(jq -r '.id' <<<"$r")" "$(jq -c '{final_score:(.ai_score // 0), final_understanding:(.ai_understanding // "partial"), reason:"smoke: ตรวจทานแล้ว"}' <<<"$r")"
  expect 200
done
gapi POST "/assignments/$MIRROR_ID/publish"
expect 200
check '.data.published == 1'
gapi GET "/assignments/$MIRROR_ID/google-feedback"
expect 200
check 'any(.data[]; .state == "queued")'
gwork
gapi GET "/assignments/$MIRROR_ID/google-feedback"
expect 200
check '(.data | length) == 1 and .data[0].state == "posted" and .data[0].announcement_id != null'
ANN=$(grecorded '[.recorded[] | select(.method == "POST" and (.url | test("/announcements$")))] | last | .body')
jq -e --arg u "g1-$RUN" '.assigneeMode == "INDIVIDUAL_STUDENTS" and .individualStudentsOptions.studentIds == [$u] and (.text | length) > 0' <<<"$ANN" >/dev/null \
  || fail "announcement body: $ANN"
[ "$(grecorded '[.recorded[] | select(.method == "PATCH" and (.url | test("studentSubmissions")))] | length')" = "0" ] \
  || fail "a grade was pushed to coursework the app does not own"
ok "announcement to g1-$RUN only; no grade pushed to website coursework"

# ---------------------------------------------------------------- school-wide students (DESIGN §24.4-§24.6)
step "school-wide student: find student 1 and enrol the same account in a second classroom"
TOKEN=$TEACHER_TOKEN
api POST /classrooms '{"name":"ป.6/1 smoke","grade_level":6,"academic_year":2570}'
expect 201
ROOM2_ID=$(j '.data.id')
ROOM2_CODE=$(j '.data.class_code')
# A student code makes the search unique: every smoke run adds another "หนึ่ง ทดสอบ".
SCODE="SM${RUN}"
api PATCH "/students/${STUDENTS[0]}" "$(jq -nc --arg c "$(tr 'A-Z' 'a-z' <<<"$SCODE")" '{student_code:$c}')"
expect 200
check ".data.student_code == \"$SCODE\""
api GET "/school-students?q=$SCODE"
expect 200
check "(.data | length) == 1 and .data[0].id == ${STUDENTS[0]} and .data[0].student_code == \"$SCODE\" and any(.data[0].classrooms[]; .id == $CLASSROOM_ID)"
check 'all(.data[]; (has("email") or has("pin")) | not)'
ENROL=$(jq -nc --argjson s "${STUDENTS[0]}" '{students:[{student_id:$s, student_number:7}]}')
api POST "/classrooms/$ROOM2_ID/students" "$ENROL"
expect 201
check ".data[0].student_id == ${STUDENTS[0]} and .data[0].existing == true and .data[0].pin == null"
api POST "/classrooms/$ROOM2_ID/students" "$ENROL"
expect 422
TOKEN=""
api POST /auth/student/pin "$(jq -nc --arg c "$ROOM2_CODE" --arg p "$PIN1" '{class_code:$c, student_number:7, pin:$p}')"
expect 200
check ".user.id == ${STUDENTS[0]}"
ok "student code $SCODE; classroom #$ROOM2_ID ($ROOM2_CODE): student ${STUDENTS[0]} enrolled with no new PIN; the old PIN logs in through either room; enrolling again -> 422"

step "copy students from the first classroom (student 1 is already there)"
TOKEN=$TEACHER_TOKEN
api POST "/classrooms/$ROOM2_ID/students/from-classroom" "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson a "${STUDENTS[0]}" --argjson b "${STUDENTS[1]}" \
  '{source_classroom_id:$c, student_ids:[$a,$b], numbering:"sorted", pin:"keep"}')"
expect 201
check "(.data.enrolled | length) == 1 and .data.enrolled[0].student_id == ${STUDENTS[1]} and .data.enrolled[0].pin == null"
check "(.data.skipped | length) == 1 and .data.skipped[0].student_id == ${STUDENTS[0]} and .data.skipped[0].reason == \"already_enrolled\""
ok "student ${STUDENTS[1]} copied with the old PIN, student ${STUDENTS[0]} skipped"

step "a duplicate account shows up as a likely pair and is merged into student 3"
api POST "/classrooms/$ROOM2_ID/students" '{"students":[{"name":"ด.ช. สาม ทดสอบ","student_number":20}]}'
expect 201
DUP_ID=$(j '.data[0].student_id')
DUP_PIN=$(j '.data[0].pin')
api GET /students/duplicate-candidates
expect 200
check "any(.data[]; ([.a.id, .b.id] | sort) == ([${STUDENTS[2]}, $DUP_ID] | sort) and (.reasons | index(\"name\")) != null)"
api GET "/students/merge-preview?keep_id=${STUDENTS[2]}&merge_id=$DUP_ID"
expect 200
check '.data.can_merge == true and (.data.conflicts | length) == 0 and .data.keep.submissions.total >= 1'
api POST /students/merge "$(jq -nc --argjson k "${STUDENTS[2]}" --argjson m "$DUP_ID" '{keep_id:$k, merge_id:$m}')"
expect 200
check ".data.kept_student.id == ${STUDENTS[2]} and .data.merge_id != null"
api POST /students/merge "$(jq -nc --argjson k "${STUDENTS[2]}" --argjson m "$DUP_ID" '{keep_id:$k, merge_id:$m}')"
expect 422
api GET "/classrooms/$ROOM2_ID/roster"
expect 200
check "any(.data[]; .student_number == 20 and (.id // .student_id) == ${STUDENTS[2]}) and ([.data[] | select((.id // .student_id) == $DUP_ID)] | length) == 0"
TOKEN=""
api POST /auth/student/pin "$(jq -nc --arg c "$ROOM2_CODE" --arg p "$DUP_PIN" '{class_code:$c, student_number:20, pin:$p}')"
expect 422 401 403
ok "#$DUP_ID merged into #${STUDENTS[2]} (number 20 kept); merging again -> 422; the merged PIN no longer logs in ($STATUS)"

# ---------------------------------------------------------------- shared homerooms (DESIGN §24.7-§24.8)
EMAIL2="smoke2-${RUN}@example.com"
step "a second teacher (subject teacher) asks to teach the first classroom"
TOKEN=""
api POST /auth/teacher/register "$(jq -nc --argjson s "$SCHOOL_ID" --arg e "$EMAIL2" --arg p "$PASSWORD" \
  '{school_id:$s, name:"ครูวิชา smoke", email:$e, password:$p}')"
expect 201
check ".user.status == \"pending\" and .user.school.id == $SCHOOL_ID"
TEACHER2_ID=$(j '.user.id')
tinker "\$a = App\\Models\\User::where('role','admin')->value('id'); App\\Models\\User::findOrFail(${TEACHER2_ID})->forceFill(['status' => 'active', 'approved_by' => \$a])->save(); echo 'approved';" | tail -1
api POST /auth/teacher/login "$(jq -nc --arg e "$EMAIL2" --arg p "$PASSWORD" '{email:$e, password:$p, device_name:"smoke2"}')"
expect 200
TEACHER2_TOKEN=$(j '.token')
TOKEN=$TEACHER2_TOKEN
api POST /courses "$(jq -nc --argjson s "$SUBJECT_ID" --argjson k "$SKILL1" \
  '{code:"ค15201", name:"คณิตศาสตร์เพิ่มเติม 5 (smoke)", subject_id:$s, grade_level:5, semester:1, academic_year:2569, hours:40, skill_ids:[$k]}')"
expect 201
T2_COURSE=$(j '.data.id')
api GET "/classrooms/directory?q=$(jq -rn --arg q 'ป.5/1 smoke' '$q | @uri')"
expect 200
check "any(.data[]; .id == $CLASSROOM_ID and .my_role == null and .homeroom_teacher.id == $TEACHER_ID) and all(.data[]; has(\"students\") | not)"
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson k "$T2_COURSE" '{classroom_id:$c, course_id:$k, title:"ก่อนอนุมัติ"}')"
expect 403 404 422
api POST "/classrooms/$CLASSROOM_ID/course-requests" "$(jq -nc --argjson k "$T2_COURSE" '{course_id:$k, message:"ขอสอนคณิตเพิ่มเติม (smoke)"}')"
expect 201
REQ_ID=$(j '.data.id')
check '.data.status == "pending"'
api POST "/classrooms/$CLASSROOM_ID/course-requests" "$(jq -nc --argjson k "$T2_COURSE" '{course_id:$k}')"
expect 409
check '.code == "request_pending"'
ok "teacher #$TEACHER2_ID, course #$T2_COURSE, request #$REQ_ID pending (again -> 409 request_pending, no assignment before approval)"

step "the homeroom teacher approves the request"
TOKEN=$TEACHER_TOKEN
api GET /teacher/attention
expect 200
check '.data.course_requests_pending >= 1'
api GET "/course-requests?box=incoming"
expect 200
check "any(.data[]; .id == $REQ_ID and .status == \"pending\" and .origin == \"teacher\")"
api POST "/course-requests/$REQ_ID/approve"
expect 200
check '.data.status == "approved"'
api POST "/course-requests/$REQ_ID/approve"
expect 409
check '.code == "request_closed"'
ok "approved (deciding again -> 409 request_closed)"

step "the subject teacher works in the room with their own course only; the homeroom teacher reads it"
TOKEN=$TEACHER2_TOKEN
api GET /classrooms
expect 200
check "any(.data[]; .id == $CLASSROOM_ID and .my_role == \"subject\")"
api GET "/classrooms/$CLASSROOM_ID/roster"
expect 200
check '(.data | length) >= 3 and all(.data[]; .pin_pending == null)'
api POST "/classrooms/$CLASSROOM_ID/students" '{"students":[{"name":"ไม่ควรเพิ่ม","student_number":90}]}'
expect 403
check '.code == "not_homeroom_teacher"'
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson k "$T2_COURSE" '{classroom_id:$c, course_id:$k, title:"คณิตเพิ่มเติม smoke"}')"
expect 201
T2_ASSIGNMENT=$(j '.data.id')
api GET "/assignments?classroom_id=$CLASSROOM_ID"
expect 200
check "all(.data[]; .course_id == $T2_COURSE)"
api GET "/assignments/$ASSIGNMENT_ID"
expect 403 404
TOKEN=$TEACHER_TOKEN
api GET "/assignments/$T2_ASSIGNMENT"
expect 200
check '.data.can_manage == false'
api PATCH "/assignments/$T2_ASSIGNMENT" '{"title":"แก้ของครูวิชา"}'
expect 403
check '.code == "not_course_teacher"'
api GET "/classrooms/$CLASSROOM_ID/courses"
expect 200
check "any(.data[]; .course.id == $T2_COURSE and .is_mine == false and .teacher.id == $TEACHER2_ID) and any(.data[]; .course.id == $COURSE_ID and .is_mine == true)"
ok "assignment #$T2_ASSIGNMENT; roster read-only (403 not_homeroom_teacher); homeroom teacher reads it, edits -> 403 not_course_teacher"

# ---------------------------------------------------------------- Google sign-in (DESIGN §24.9)
step "Google sign-in off on this server: config disabled, 503 google_signin_not_configured"
TOKEN=""
api GET /auth/google/config
expect 200
check '.data.enabled == false'
api POST /auth/google '{"id_token":"x","intent":"staff"}'
expect 503
check '.code == "google_signin_not_configured"'
TOKEN=$TEACHER_TOKEN
api GET /me/google-identity
expect 503
ok "disabled; every sign-in route 503"

# ID tokens are signed by tools/smoke-google.php with a key it keeps in the
# state file; the faked JWKS endpoint answers with its public half.
gtoken() { # CLAIMS_JSON -> a signed Google ID token
  perl -e 'alarm 60; exec @ARGV' php tools/smoke-google.php "$GSTATE" idtoken "$1" 2>>"$WORK/google.log" \
    || fail "smoke-google.php idtoken failed: $(tail -20 "$WORK/google.log")"
}
SCHOOL_ID=$(tinker "echo App\\Models\\User::findOrFail(${TEACHER_ID})->school_id;" | tail -1)
SCHOOL_SIGNIN=$(tinker "echo json_encode(App\\Models\\School::findOrFail(${SCHOOL_ID})->only(['student_google_signin', 'google_signin_domains']));" | tail -1)
restore_school() {
  [ -n "${SCHOOL_SIGNIN:-}" ] || return 0
  local saved=$SCHOOL_SIGNIN
  SCHOOL_SIGNIN=""
  tinker "\$v = json_decode('${saved}', true); App\\Models\\School::findOrFail(${SCHOOL_ID})->forceFill(\$v)->save(); echo 'restored';" | tail -1
}

step "Google sign-in (faked ID tokens): a teacher is linked by e-mail, an unknown account gets a link ticket"
# Students of the school may use Google, every domain (restored afterwards, also on failure).
tinker "App\\Models\\School::findOrFail(${SCHOOL_ID})->forceFill(['student_google_signin' => true, 'google_signin_domains' => null])->save(); echo 'school: student Google sign-in on';" | tail -1
TOKEN=""
gapi POST /auth/google "$(jq -nc --arg t "$(gtoken "$(jq -nc --arg s "smoke-t1-$RUN" --arg e "$EMAIL" '{sub:$s, email:$e, name:"ครูทดสอบ smoke"}')")" '{id_token:$t, intent:"staff", device_name:"smoke-google"}')"
expect 200
check ".user.id == $TEACHER_ID"
TOKEN=$(j '.token')
api GET /me
expect 200
check ".data.id == $TEACHER_ID"
gapi GET /me/google-identity
expect 200
check '.data.linked == true and .data.linked_via == "teacher_email" and .data.notice_version == "gsi-1"'
TOKEN=""
gapi POST /auth/google "$(jq -nc --arg t "$(gtoken "$(jq -nc --arg s "smoke-x-$RUN" --arg e "nobody-$RUN@example.com" '{sub:$s, email:$e, name:"ไม่มีบัญชี"}')")" '{id_token:$t, intent:"staff"}')"
expect 404
check '.code == "google_not_linked" and (.link_ticket | length) == 48 and .registration.email != null'
gapi POST /auth/google "$(jq -nc --arg t "$(gtoken "$(jq -nc --arg s "smoke-t1-$RUN" --arg e "$EMAIL" '{sub:$s, email:$e, aud:"someone-else.apps.googleusercontent.com"}')")" '{id_token:$t, intent:"staff"}')"
expect 422
check '.code == "google_token_invalid"'
ok "teacher signed in with Google (token works on the server); unknown -> 404 + link_ticket; wrong aud -> 422"

step "Google sign-in: a student confirms with the PIN once, then signs in with Google; Classroom roster auto-link"
S1_ID_TOKEN=$(gtoken "$(jq -nc --arg s "smoke-s1-$RUN" --arg e "s1-$RUN@example.com" '{sub:$s, email:$e, name:"หนึ่ง ทดสอบ"}')")
TOKEN=""
gapi POST /auth/google "$(jq -nc --arg t "$S1_ID_TOKEN" '{id_token:$t, intent:"student"}')"
expect 404
check '.code == "google_not_linked" and (.link_ticket | length) == 48'
LINK=$(j '.link_ticket')
gapi POST /auth/google/link-with-pin "$(jq -nc --arg l "$LINK" --arg c "$CLASS_CODE" --arg p "$PIN1" '{link_ticket:$l, class_code:$c, student_number:1, pin:$p}')"
expect 422
check '.code == "notice_required"'
gapi POST /auth/google/link-with-pin "$(jq -nc --arg l "$LINK" --arg c "$CLASS_CODE" --arg p "$PIN1" '{link_ticket:$l, class_code:$c, student_number:1, pin:$p, accept_notice:true}')"
expect 200
check ".user.id == ${STUDENTS[0]}"
gapi POST /auth/google "$(jq -nc --arg t "$S1_ID_TOKEN" '{id_token:$t, intent:"student"}')"
expect 200
check ".user.id == ${STUDENTS[0]}"
S1_GOOGLE_TOKEN=$(j '.token')
TOKEN=$S1_GOOGLE_TOKEN
gapi GET /me/google-identity
expect 200
check '.data.linked == true and .data.linked_via == "pin_confirm"'
# g1-$RUN / kwan-$RUN@example.com is on the roster imported from Google Classroom above.
TOKEN=""
gapi POST /auth/google "$(jq -nc --arg t "$(gtoken "$(jq -nc --arg s "g1-$RUN" --arg e "kwan-$RUN@example.com" '{sub:$s, email:$e, name:"ขวัญใจ"}')")" '{id_token:$t, intent:"student"}')"
expect 200
check '.user.role == "student"'
KWAN_ID=$(j '.user.id')
TOKEN=$TEACHER_TOKEN
gapi GET "/classrooms/$GCLASS_ID/roster"
check "any(.data[]; (.id // .student_id) == $KWAN_ID and .google_linked == true)"
gapi DELETE "/students/$KWAN_ID/google-identity"
expect 204
gapi GET "/classrooms/$GCLASS_ID/roster"
check "any(.data[]; (.id // .student_id) == $KWAN_ID and .google_linked == false)"
TOKEN=$S1_GOOGLE_TOKEN
gapi DELETE /me/google-identity
expect 204
TOKEN=""
gapi POST /auth/google "$(jq -nc --arg t "$S1_ID_TOKEN" '{id_token:$t, intent:"student"}')"
expect 404
restore_school
ok "PIN confirmation (notice required first), Google login, roster auto-link of #$KWAN_ID (classroom_roster), unlink by the homeroom teacher and by the student"

# ---------------------------------------------------------------- Classroom import with existing students (DESIGN §24.10)
step "the subject teacher imports their own Google course: the existing classroom is suggested"
GCOURSE2="62${RUN}"
gstate --arg c "$GCOURSE2" --arg r "$RUN" '.course = {id: $c, name: "คณิตศาสตร์เพิ่มเติม", section: "ป.5/2"}
  | .students = [.students[] | select(.id != ("g3-" + $r))]'
TOKEN=$TEACHER2_TOKEN
gapi POST /google/connect '{"server_auth_code":"4/smoke-two-one-time-code"}'
expect 200
gapi GET "/google/courses/$GCOURSE2/import-preview"
expect 200
check ".data.suggested_classroom.id == $GCLASS_ID and .data.suggested_classroom.coverage == 1 and .data.suggested_classroom.owned_by_me == false"
check 'all(.data.students[]; .match != null and .match.matched_by == "classroom_user")'
gapi POST "/google/courses/$GCOURSE2/link-existing" "$(jq -nc --argjson c "$GCLASS_ID" --argjson k "$T2_COURSE" '{classroom_id:$c, app_course_id:$k}')"
expect 202
check '.data.status == "requested"'
REQ2_ID=$(j '.data.request_id')
TOKEN=$TEACHER_TOKEN
gapi GET "/course-requests?box=incoming"
check "any(.data[]; .id == $REQ2_ID and .origin == \"classroom_import\" and .google_course_name != null)"
gapi POST "/course-requests/$REQ2_ID/approve"
expect 200
gwork
TOKEN=$TEACHER2_TOKEN
gapi GET "/classrooms/$GCLASS_ID"
expect 200
check ".data.my_role == \"subject\" and .data.google_link.course_id == \"$GCOURSE2\" and .data.google_link.app_course_id == $T2_COURSE"
gapi POST "/classrooms/$GCLASS_ID/google-roster/sync"
expect 200
check '(.data.added | length) == 0 and (.data.enrolled | length) == 0 and (.data.not_in_classroom | length) == 0'
gapi GET "/classrooms/$GCLASS_ID/roster"
check '(.data | length) == 3'
ok "suggested #$GCLASS_ID (coverage 100%), request #$REQ2_ID approved, course linked to the subject teacher, no new student accounts"

# ---------------------------------------------------------------- one student, several classrooms (DESIGN §24.11)
step "student 1's combined view: courses of both classrooms, the closed one read-only"
TOKEN=$TEACHER_TOKEN
api PUT "/courses/$COURSE_ID/classrooms" "$(jq -nc --argjson a "$CLASSROOM_ID" --argjson b "$GCLASS_ID" --argjson c "$ROOM2_ID" '{classroom_ids:[$a,$b,$c]}')"
expect 200
api POST "/classrooms/$ROOM2_ID/close"
expect 200
api POST "/classrooms/$ROOM2_ID/students" '{"students":[{"name":"ห้องปิดแล้ว","student_number":30}]}'
expect 409
check '.code == "classroom_closed"'
api GET "/classrooms?state=closed"
expect 200
check "any(.data[]; .id == $ROOM2_ID)"
TOKEN=$STUDENT_TOKEN
api GET /student/overview
expect 200
check "any(.data.classrooms[]; .id == $CLASSROOM_ID and .closed == false) and any(.data.classrooms[]; .id == $ROOM2_ID and .closed == true)"
check "any(.data.groups[]; .course.id == $COURSE_ID and .classroom.id == $CLASSROOM_ID and .results_count >= 1)"
check "any(.data.groups[]; .course.id == $T2_COURSE and .classroom.id == $CLASSROOM_ID and .teacher_name == \"ครูวิชา smoke\")"
check "any(.data.groups[]; .course.id == $COURSE_ID and .classroom.id == $ROOM2_ID and .classroom.closed == true and .todo_count == 0)"
api GET "/student/assignments?classroom_id=$CLASSROOM_ID"
expect 200
check "all(.data[]; .classroom.id == $CLASSROOM_ID)"
TOKEN=$TEACHER_TOKEN
api POST "/classrooms/$ROOM2_ID/reopen"
expect 200
check '.data.closed_at == null'
ok "groups by course over both classrooms (#$ROOM2_ID shown closed, nothing to hand in); closing -> 409 classroom_closed; reopened"

# ---------------------------------------------------------------- wrap up
step "health heartbeat and logout"
TOKEN=""
api GET /health
expect 200
check '.status == "ok" and .queue_last_run_at != null'
TOKEN=$STUDENT_TOKEN
api POST /auth/logout
expect 204
api GET /me
expect 401
TOKEN=$TEACHER_TOKEN
api POST /auth/logout
expect 204
ok "heartbeat $(TOKEN='' ; curl -s "$BASE/health" | jq -r .queue_last_run_at); tokens revoked"

printf '\nSMOKE PASSED: %d steps (assignment #%s, classroom #%s)\n' "$STEPS" "$ASSIGNMENT_ID" "$CLASSROOM_ID"
