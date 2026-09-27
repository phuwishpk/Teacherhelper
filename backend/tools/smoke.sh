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
# Prerequisites: MariaDB container running, `php artisan migrate --seed`
# done (the demo school with SEED_TEACHER_JOIN_CODE), QR_SIGNING_KEY set in
# .env and a model registered with eduvision:register-model (else the ml step
# expects 404). Run from anywhere:  bash backend/tools/smoke.sh
#
# The server is started here with `php artisan serve --no-reload` so the
# overrides below reach it (without --no-reload artisan serve hides every
# variable that .env also defines from the child PHP server). Set
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
step "teacher register ($EMAIL)"
api POST /auth/teacher/register "$(jq -nc --arg c "$SCHOOL_CODE" --arg e "$EMAIL" --arg p "$PASSWORD" \
  '{school_code:$c, name:"ครูทดสอบ smoke", email:$e, password:$p}')"
expect 201
check '.user.status == "pending"'
TEACHER_ID=$(j '.user.id')
ok "teacher #$TEACHER_ID pending"

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

# ---------------------------------------------------------------- assignment
step "assignment with mcq, numeric short, show_work, open"
api GET /subjects
expect 200
SUBJECT_ID=$(j '.data[0].id')
api GET "/skills?subject=$SUBJECT_ID"
expect 200
SKILL1=$(j '.data[0].id')
SKILL2=$(j '.data[1].id // .data[0].id')
api POST /assignments "$(jq -nc --argjson c "$CLASSROOM_ID" --argjson s "$SUBJECT_ID" '{classroom_id:$c, subject_id:$s, title:"แบบฝึกหัด smoke การคูณ"}')"
expect 201
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
# Routes scoped to a classroom/assignment check their own preconditions
# (link, post) before the server config, so they may answer 409/422 here.
probe() { # METHOD PATH JSON allowed-codes...
  local m=$1 p=$2 d=$3; shift 3
  api "$m" "$p" "$d"
  local c; c=$(j '.code // empty')
  case " $* " in *" $STATUS:$c "*) printf '   %-6s %-48s %s %s\n' "$m" "$p" "$STATUS" "$c" ;; *) fail "$m $p -> $STATUS $c (allowed: $*)" ;; esac
}
probe DELETE /google/disconnect '' 200: 204: 503:google_not_configured
probe POST "/classrooms/$CLASSROOM_ID/google-link" '{"course_id":"123456"}' 503:google_not_configured
probe GET "/classrooms/$CLASSROOM_ID/google-roster" '' 503:google_not_configured 422:classroom_not_linked
probe PUT "/classrooms/$CLASSROOM_ID/google-roster" '{"matches":[]}' 503:google_not_configured 422:classroom_not_linked 422:validation_failed
probe DELETE "/classrooms/$CLASSROOM_ID/google-link" '' 204: 503:google_not_configured 422:classroom_not_linked
probe POST "/assignments/$ASSIGNMENT_ID/google-post" '{}' 503:google_not_configured 422:classroom_not_linked
probe GET "/assignments/$ASSIGNMENT_ID/google-submissions" '' 503:google_not_configured 409:not_posted
probe POST "/assignments/$ASSIGNMENT_ID/google-grades/retry" '' 202: 503:google_not_configured 409:not_posted
probe POST /google-submissions/999999/return '{"reason":"ถ่ายใหม่"}' 404:not_found 503:google_not_configured
TOKEN=""
api GET /google/status
expect 401
TOKEN=$STUDENT_TOKEN
api GET /google/status
expect 403
ok "503 google_not_configured / 401 guest / 403 student"

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
