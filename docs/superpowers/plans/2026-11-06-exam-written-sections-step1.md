# Exam written sections — Step 1 (sections, rubric, key, booklet) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A teacher can add "เขียนตอบ" sections (`open`, `show_work`) to an `app`-graded exam, give each question a prompt, points, lines and an approved rubric, and print them in the booklet. Nothing about answer sheets, scanning or Gemini grading of student writing is built here.

**Architecture:** `exam_sections.type` gets two values. Written questions reuse the homework question fields (`answer_lines`, `rubric_status`, `rubric_criteria`, `answer_key.final`/`reference_steps`) and the homework rubric endpoints and screen. Everything sheet-shaped (versions' key, bubble layout, scan-kit, capacity) ignores written questions, and printing answer sheets / scan-kit for an exam with written sections answers a temporary 422 until step 2 removes it.

**Tech Stack:** Laravel 12 / PHP 8.3 syntax, PHPUnit feature tests with `ExamTestHelpers`, Flutter + Riverpod, widget tests with `exam_fakes.dart`.

**Spec:** `docs/DESIGN.md` §22.20 (this plan is step 1 of §22.20.9). Read §22.20.1–22.20.3 and §22.20.7 first.

## Global Constraints

- PHP syntax no newer than 8.3; never run `composer update`; no new composer or pub packages.
- Commits: Conventional Commits, English, scope `app|backend|docs`, no `--no-verify`; no Claude attribution lines. Do not commit `sendwork/`, `app/ios/Runner.xcodeproj/project.pbxproj`, or anything secret. Only commit when the user has said to (CLAUDE.md session rule); ask at Task 1.
- Branch `feat/exams-written-sections` off `main`; one PR; never force-push main.
- API errors are `{message, errors, code}`; `message` is Thai; timestamps UTC.
- UI strings Thai; screens take colours/text styles/shapes from `Theme.of(context)`, lay out by `core/theme/breakpoints.dart`; check at 390, 820, 1440 px wide.
- Test passwords: `'secret1234'` only (gitleaks).
- Schema change allowed in this step: the `exam_sections.type` enum only (spec §22.20.7). Config key added: none.
- `ExamSection::TYPES` stays `['mcq','true_false','numeric']` (the Gemini file import validates against it and must not accept written sections).
- Keep green before every commit: `cd backend && php artisan test`, `cd app && flutter analyze && flutter test`.
- Error codes: `exam_written_section_last` (422), `exam_written_needs_qr_sheet` (422), existing `exam_manual_grading` (422), temporary `exam_written_not_ready` (422, deleted in step 2).
- Thai copy fixed by the spec: `ตอนเขียนตอบต้องอยู่หลังตอนที่ฝนทุกตอน`, `ข้อเขียนใช้กับกระดาษคำตอบแบบ QR รายคนเท่านั้น`, reason `rubric ยังไม่อนุมัติ`.

## Review Focus

Inputs the spec implies but no spec line pins; each has a test in the named task.

1. An exam with no written section behaves byte-for-byte as before (all existing exam tests untouched and green) — Task 9.
2. A written section added/moved after the exam was printed (`structure_locked_at` set) — 409 `exam_structure_locked`, nothing changes — Task 3.
3. Teacher edits `max_points` (or the section's `default_points`) of a written question whose rubric is approved: criteria no longer sum to the points, so the rubric goes back to `draft` and the exam's key approval is withdrawn — Task 4.
4. A written question with an empty prompt: rubric draft job does not crash; booklet is blocked with "ยังไม่มีโจทย์" while key stays complete — Tasks 5 and 7.
5. `PUT /exams/{id}/answer-key` or the question copier handed a written question must refuse, not write an options/values key into it — Tasks 5 and 6.

---

### Task 1: Branch and land the spec

**Files:**
- Modify: `docs/DESIGN.md` (§22.20.2, §22.20.3, §22.20.9)
- Create (already written): `docs/superpowers/plans/2026-11-06-exam-written-sections-step1.md`

- [ ] **Step 1: Ask the user whether to commit** the already-edited `docs/DESIGN.md` (§22 changes + §22.20) and this plan. If yes: `git switch -c feat/exams-written-sections`.
- [ ] **Step 2: Add three clarifications to §22.20** (they decide things the spec left open):
  - §22.20.2 "เฉลยครบ": a `show_work` question also needs `answer_key.final.accepted` with at least one answer (the homework grader needs it; without it `ResponseGrader` routes the answer to manual).
  - §22.20.3 booklet: step 1 prints "เขียนตอบในกระดาษคำตอบ" without a page number; step 2 adds "หน้า n" once the written pages exist.
  - §22.20.9: add under step 1 "ระหว่างขั้น 1–2 พิมพ์กระดาษคำตอบและเตรียมสแกนของข้อสอบที่มีตอนเขียนตอบ 422 `exam_written_not_ready` (ลบในขั้น 2)".
- [ ] **Step 3: Commit** `docs: spec written sections in exams (§22.20)` with a body explaining that §22.1's "Gemini never sees the answer sheet" is changed to "only the written-box crops" because final exams need written answers.

---

### Task 2: Section type model and migration

**Files:**
- Modify: `backend/app/Models/ExamSection.php`
- Create: `backend/database/migrations/2026_11_06_000001_add_written_exam_section_types.php`
- Create: `backend/app/Domain/Exams/ExamWritten.php`
- Test: `backend/tests/Feature/Exams/ExamWrittenSectionTest.php`

**Interfaces:**
- Produces (`ExamSection`): `const TYPE_OPEN = 'open'`, `const TYPE_SHOW_WORK = 'show_work'`, `const WRITTEN_TYPES = ['open','show_work']`, `const DEFAULT_ANSWER_LINES = 8`, `static isWrittenType(string $type): bool`, `isWritten(): bool`. `TYPES` unchanged. `choiceCount()` stays 0 for written types.
- Produces (`ExamWritten`, `final class`, all static, created empty here and filled by Tasks 3 and 6): `hasWritten(Assignment $exam): bool`.

- [ ] **Step 1: Failing test** `ExamWrittenSectionTest::test_written_types_are_known_and_choice_types_unchanged` asserting `ExamSection::WRITTEN_TYPES === ['open','show_work']`, `ExamSection::TYPES === ['mcq','true_false','numeric']`, `(new ExamSection(['type'=>'open']))->isWritten()` true, `choiceCount()` 0 for `show_work`.
- [ ] **Step 2: Run** `php artisan test --filter=ExamWrittenSectionTest` → FAIL (constants missing).
- [ ] **Step 3: Implement** the constants/methods. Migration: follow the enum-`->change()` pattern of `2026_09_30_000013_add_exams.php` (`Schema::table('exam_sections', fn ($t) => $t->enum('type', ['mcq','true_false','numeric','show_work','open'])->change())`); `down()` restores the three values. Implement `ExamWritten::hasWritten()` as an `exists()` query on `exam_sections` where `type` in `WRITTEN_TYPES`.
- [ ] **Step 4: Run** the test → PASS; run `php artisan migrate:fresh --env=testing` is covered by RefreshDatabase.
- [ ] **Step 5: Commit** `feat(backend): written section types for exams`.

---

### Task 3: Creating and moving written sections

**Files:**
- Modify: `backend/app/Domain/Exams/ExamWritten.php`, `backend/app/Domain/Exams/ExamEditor.php` (`createSection`, `updateSection`, `sectionData`, `insertQuestion`), `backend/app/Domain/Exams/ExamSettings.php`
- Test: `backend/tests/Feature/Exams/ExamWrittenSectionTest.php`

**Interfaces:**
- Consumes: Task 2 constants.
- Produces (`ExamWritten`): `assertAllowed(Assignment $exam): void` (422 `exam_manual_grading` when `isManualExam()`, 422 `exam_written_needs_qr_sheet` when `usesCodeSheets()`), `assertLast(Assignment $exam): void` (422 `exam_written_section_last` when, ordered by `position`, any non-written section follows a written one).

- [ ] **Step 1: Failing tests** in `ExamWrittenSectionTest` (use `makeExamWorld`, `createExam`, `addSection` helpers; `addSection` asserts 201, so use `postJson` directly for rejections):
  - `test_written_section_is_created_with_blank_questions`: POST `{type:'open', question_count:3, default_points:5}` on an `app` exam with an mcq section first → 201; response `type` `open`, `option_count` null, `numeric` null; DB questions: 3 rows, `type` `open`, `answer_lines` 8, `rubric_status` `draft`, `approved_at` null, `origin` `teacher`, `max_points` 5.
  - `test_option_count_and_numeric_are_refused_for_written`: `{type:'show_work', option_count:4}` → 422 with `errors.option_count`; `{type:'open', numeric:{digits:2}}` → 422 `errors.numeric`.
  - `test_written_section_must_come_last`: creating mcq after an open section → 422 `code` `exam_written_section_last`; creating `open` with `position:1` before an mcq section → same code; PATCH `exam-sections/{open}` `{position:1}` → same code; each leaves sections unchanged.
  - `test_written_refused_on_manual_exam`: exam with `grading_method:'manual'` (+ `manual_full_marks:100`) → 422 `exam_manual_grading`; and PATCH an exam holding a written section to `{grading_method:'manual'}` → 422 `exam_manual_grading`.
  - `test_written_refused_on_code_sheet_exam`: `sheet_identity:'code', student_code_digits:8` exam → 422 `exam_written_needs_qr_sheet`; PATCH an exam with a written section to `sheet_identity:'code'` → same code.
  - `test_written_section_refused_when_structure_locked`: set `structure_locked_at` → 409 `exam_structure_locked`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** `ExamEditor::sectionData`: `type` rule `Rule::in([...ExamSection::TYPES, ...ExamSection::WRITTEN_TYPES])`, update the `type.in` message to `ชนิดของตอนต้องเป็น ปรนัย ถูก/ผิด เติมตัวเลข อัตนัย หรือแสดงวิธีทำ`; `option_count`/`numeric` already `prohibited` for other types. `createSection`: call `ExamWritten::assertAllowed($exam)` when the new type is written, and `ExamWritten::assertLast($exam)` after positions are written (inside the `AssignmentLocked::run` closure so the exception rolls back). `updateSection`: call `assertLast` after a position move. `insertQuestion`: for written sections set `rubric_status` = `Question::RUBRIC_DRAFT` and `answer_lines` = `ExamSection::DEFAULT_ANSWER_LINES` (keep `is_numeric` false, `match_mode` `exact`). `ExamSettings::apply`: when switching to manual call `ExamWritten::hasWritten` → 422 `exam_manual_grading` (same message text as `assertAllowed`: `ข้อสอบที่ครูตรวจเองไม่มีตอนเขียนตอบ`); in `applySheetIdentity`, when the new identity is `code` and `hasWritten` → 422 `exam_written_needs_qr_sheet`. Use `ApiException` with the codes above (validation-style `ValidationException` is wrong here: the app reads `code`).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(backend): add, move and guard written exam sections`.

---

### Task 4: Written question fields, points and payload

**Files:**
- Modify: `backend/app/Domain/Assignments/QuestionData.php` (add one public method), `backend/app/Domain/Exams/ExamEditor.php` (`QUESTION_FIELDS`, `questionData`, `createQuestion`, `updateQuestion`, `updateSection`), `backend/app/Domain/Exams/ExamPayload.php`
- Test: `backend/tests/Feature/Exams/ExamWrittenQuestionTest.php`

**Interfaces:**
- Produces: `QuestionData::validatedKey(string $type, mixed $key): ?array` (public static; runs the existing `answerKeyRules($type)` and `normaliseKey`, throws `ValidationException` keyed `answer_key.*`; `open` → `null`).
- Produces (payload): `ExamPayload::question()` adds `answer_lines` (int|null) and `rubric_status` (string); `ExamPayload::of()['sheet']` counts only choice questions.
- `ExamEditor::QUESTION_FIELDS` gains `'answer_lines'`.

- [ ] **Step 1: Failing tests** in `ExamWrittenQuestionTest`:
  - `test_written_question_create_and_patch`: `POST exam-sections/{open}/questions {prompt_text:'อธิบาย…', max_points:4, answer_lines:6}` → 201, payload `answer_lines` 6, `rubric_status` `draft`, `approved_at` set (a question the teacher types is approved when saved, §22.4; the rubric gate is separate, Task 5), `key_complete` false; PATCH `{answer_lines:20}` → 422 `errors.answer_lines` (max `QuestionData::MAX_ANSWER_LINES` = 15).
  - `test_show_work_key_final_answer`: PATCH `{answer_key:{final:{accepted:['12','๑๒']}}}` on a show_work question → saved normalised; `{answer_key:{accepted_options:[1]}}` → 422 `errors.answer_key.final`-style error; on an `open` question any `answer_key` → 422.
  - `test_options_and_lock_refused_on_written`: `{options:[{text:'x'}]}` → 422; `{lock_options:true}` → 422.
  - `test_points_change_withdraws_rubric` (Review Focus 3): question with `rubric_status` `approved` and criteria summing to 4; PATCH `{max_points:5}` → `rubric_status` `draft`; same effect when `PATCH exam-sections/{id} {default_points: 6}` moves an approved question that was at the default.
  - `test_sheet_capacity_ignores_written`: exam with 10 mcq + 3 open → `GET /exams/{id}` `sheet.pages` computed from 10 rows only.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** In `ExamEditor::questionData`, branch on `$section->isWritten()`: allow `prompt_text` (nullable), `max_points`, `answer_lines` (`integer|min:1|max:QuestionData::MAX_ANSWER_LINES`), `approve`, `position`, `skill_ids`; `options` and `lock_options` `prohibited`; `answer_key` via `QuestionData::validatedKey($section->type, ...)` (not `ExamAnswerKey::normalise`). `createQuestion` for written: `approved_at` now (as for every teacher-typed question), `rubric_status` draft, `answer_lines` from input or default 8 (the rest of `insertQuestion` is shared). `updateQuestion`: persist `answer_lines`; when `max_points` changes on a written question with `rubric_status` approved set `RUBRIC_DRAFT`. `updateSection`: when `default_points` changes, also set approved rubrics of the questions it updated to draft. `ExamPayload::question` adds the two fields; `ExamPayload::of` computes `sheet` from `$questions->reject(fn (Question $q) => Question::typeNeedsRubric($q->type))`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(backend): written question fields for exams`.

---

### Task 5: Key completeness and rubric endpoints for exam questions

**Files:**
- Modify: `backend/app/Domain/Exams/ExamKeyCheck.php`, `backend/app/Domain/Exams/ExamEditor.php` (`saveAnswerKey`, `autoApprove`), `backend/app/Domain/Assignments/RubricService.php`, `backend/tests/Feature/Exams/ExamTestHelpers.php` (`fillExam`)
- Test: `backend/tests/Feature/Exams/ExamWrittenKeyTest.php`

**Interfaces:**
- Produces: `ExamKeyCheck::NO_RUBRIC = 'no_rubric'` with Thai `rubric ยังไม่อนุมัติ`; `ExamKeyCheck::hasKey(Question)` is true for a written question iff `rubricApproved()` and (type `open` or `answer_key.final.accepted` non-empty).
- Consumes: Task 4 payload, homework `PUT /questions/{id}/rubric`, `POST /questions/{id}/rubric/draft`.

- [ ] **Step 1: Failing tests** in `ExamWrittenKeyTest`:
  - `test_written_question_without_rubric_blocks_key_approval`: exam with one mcq (filled) + one approved-by-teacher open question whose rubric is `draft`: `GET /exams/{id}` → `key_complete` false, `incomplete_questions[0].reasons` `['no_rubric']`; `POST /assignments/{id}/answer-key/approve` → 422 `answer_key_incomplete` and `errors.questions[0]` contains `rubric ยังไม่อนุมัติ`.
  - `test_rubric_put_completes_key_and_auto_approves_blank`: blank open question (4 points); `PUT /questions/{id}/rubric` with criteria `[{description:'ตอบถูก',points:3,is_core:true},{description:'มีเหตุผล',points:1}]` → 200; question `rubric_status` `approved` and `approved_at` set (auto-approval); `key_complete` true; then answer-key approve → 200.
  - `test_show_work_needs_final_answer`: show_work with approved rubric but no `answer_key.final` → `no_key` reason; after PATCH final answer → complete.
  - `test_rubric_change_after_ready_withdraws_approval`: approved exam; `PUT /questions/{id}/rubric/draft` job result applied via `RubricService::applyDraft` (use `FakeGeminiClient`/direct call) sets draft → exam back to `draft` status and `key_approved_at` null.
  - `test_answer_key_table_refuses_written` (Review Focus 5): `PUT /exams/{id}/answer-key {answers:[{question_id:<open>, accepted_options:[1]}]}` → 422 `errors.answers.0.question_id` with `ข้อเขียนตั้งเกณฑ์ที่หน้า rubric`; the question's `answer_key` stays null.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** `ExamKeyCheck::keyProblems`: reasons per question = `NOT_APPROVED` if unapproved, then for written `NO_RUBRIC` when `! rubricApproved()` else `NO_KEY` when `! hasKey()`; choice questions unchanged. `hasKey` as in Interfaces (it is used by `ExamPayload::question['key_complete']` and `autoApprove`). `ExamEditor::saveAnswerKey`: reject a written question id with that message instead of calling `ExamAnswerKey::normalise`. `RubricService::approve` and `applyDraft`: after saving, when `$assignment->isExam()` call `ExamEditor::autoApprove($question->refresh(), $assignment)` then `ExamKeyCheck::keepApprovalValid($assignment)` (the existing `backToDraft()` call in `applyDraft` stays). `ExamTestHelpers::fillExam`: for written questions set `rubric_status` `approved` (and create criteria summing to `max_points` for `open`; `answer_key.final.accepted = ['1']` for `show_work`) instead of the options/values key, so existing helpers keep working.
- [ ] **Step 4: Run** → PASS; also run `php artisan test --filter=Exam` to catch `fillExam` regressions.
- [ ] **Step 5: Commit** `feat(backend): rubric decides key completeness of written exam questions`.

---

### Task 6: Keep written questions out of the sheet side, and the temporary print guard

**Files:**
- Modify: `backend/app/Domain/Exams/ExamVersions.php` (`permutation`, `keyOf`), `backend/app/Domain/Exams/ExamSheetLayout.php` (`items`), `backend/app/Domain/Exams/ExamQuestionCopier.php` (`mismatch`), `backend/app/Domain/Exams/ExamWritten.php`, `backend/app/Domain/Exams/ExamPrintService.php` (`queue`), `backend/app/Http/Controllers/Api/V1/ExamController.php` (`scanKit`)
- Test: `backend/tests/Feature/Exams/ExamWrittenSheetSideTest.php`

**Interfaces:**
- Produces (`ExamWritten`): `assertSheetsReady(Assignment $exam): void` — 422 `exam_written_not_ready`, message `ยังพิมพ์กระดาษคำตอบหรือเตรียมสแกนข้อสอบที่มีข้อเขียนไม่ได้ (กำลังพัฒนา)`, thrown when `hasWritten`. **Temporary: step 2 deletes it and its two call sites.**

- [ ] **Step 1: Failing tests** in `ExamWrittenSheetSideTest` (exam with 6 mcq, then an `open` section with 2 questions; `version_count` 3; approve via `approveExam`):
  - `test_versions_do_not_shuffle_or_key_written`: for every version `question_order` ends with the two written ids in original order; `GET /exams/{id}/versions` `items` contain only the 6 choice questions (`sheet_no` 1–6).
  - `test_bubble_layout_ignores_written`: `ExamSheetLayout::items($exam)` has 6 items, all with `type` in `ExamSection::TYPES`.
  - `test_sheet_prints_and_scan_kit_refused_for_written`: `POST /exams/{id}/prints {kind:'answer_sheet'}` and `GET /exams/{id}/scan-kit` → 422 `exam_written_not_ready`; `{kind:'exam_booklet'}` and `{kind:'key_sheet'}` still 202.
  - `test_copier_refuses_written_target` (Review Focus 5): `POST /exams/{target}/copy-questions` with a written source question into a written target section → the question is reported mismatched (same response shape as the existing `TYPE_MISMATCH` case in `ExamCopyQuestionsTest`) and no row is created.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** `permutation`: shuffle only when `! $section->isWritten()` (version 1 and the section order are unchanged). `keyOf`: `continue` for written types (indexes stay valid because written questions are last). `ExamSheetLayout::items`: skip questions whose section is written. `ExamQuestionCopier::mismatch`: return `self::TYPE_MISMATCH` when `$target->isWritten()` (round one does not copy written questions). Call `ExamWritten::assertSheetsReady($exam)` at the top of `ExamController::scanKit` and in `ExamPrintService::queue` for kind `answer_sheet` only.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(backend): written questions stay off the bubble sheet`.

---

### Task 7: Booklet prints written questions

**Files:**
- Modify: `backend/app/Domain/Exams/ExamBooklet.php` (`questionHtml`)
- Test: `backend/tests/Feature/Exams/ExamBookletTest.php`

- [ ] **Step 1: Failing tests** (extend `ExamBookletTest`, same helpers):
  - `test_written_question_prints_without_options_and_with_write_hint`: exam with an open question (prompt `อธิบายวัฏจักรของน้ำ`, 5 points); render the version-1 booklet HTML via the same entry the existing tests use for question text; assert the number, the prompt, `(5 คะแนน)` and the line `เขียนตอบในกระดาษคำตอบ`, and no option labels.
  - `test_blank_prompt_written_question_blocks_booklet` (Review Focus 4): written question with empty prompt and an approved rubric → `POST /exams/{id}/prints {kind:'exam_booklet'}` 422 `answer_key_incomplete`, `errors.questions` has `ยังไม่มีโจทย์`, while `GET /exams/{id}` `key_complete` is true.
- [ ] **Step 2: Run** `php artisan test --filter=ExamBookletTest` → FAIL on the first.
- [ ] **Step 3: Implement** in `questionHtml`: for written types render the prompt and points as for other questions (the points suffix already shows when `max_points` differs from the section default; for written sections always show it), no options, then a `<div>` with `เขียนตอบในกระดาษคำตอบ`. No change to `bookletProblems`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(backend): print written questions in the exam booklet`.

---

### Task 8: Rubric draft for exam questions, and the empty show_work draft

**Files:**
- Investigate/modify: `backend/app/Domain/Gemini/RubricDraftRequest.php`, `backend/app/Domain/Gemini/RubricDrafter.php`, `backend/app/Domain/Gemini/RubricDraft.php`, `backend/app/Jobs/DraftRubricJob.php`, prompt file under `backend/resources/prompts/` for `rubric_draft`
- Test: `backend/tests/Feature/Exams/ExamWrittenKeyTest.php`

- [ ] **Step 1: Failing test** `test_rubric_draft_works_for_exam_questions`: teacher with a Gemini key (use the helper other rubric tests use), `POST /api/v1/questions/{open}/rubric/draft` → 202; run `DraftRubricJob` synchronously with `FakeGeminiClient`; the question then has criteria with `source` `ai`, `rubric_status` `draft`, and the exam's `key_approved_at` stays null. Repeat for a `show_work` question with an empty `prompt_text` (Review Focus 4): `answer_key.reference_steps` saved, status `draft`, no exception. Also assert `Gate::authorize('manageRubric')` lets the exam's teacher in and another teacher gets 404/403.
- [ ] **Step 2: Run** → note which assertion fails (it shows whether the policy, `fromQuestion`, or `applyDraft` is the problem).
- [ ] **Step 3: Diagnose the demo finding** (6 Nov: a show_work draft returned with "no criteria saved"). By design a `show_work` draft carries `reference_steps` and no criteria (`RubricDraft::validateFor`), so first check whether the demo only looked empty because the screen shows criteria. Read the `ai_calls` rows of the local demo database under `~/krucheck-demo` (read-only) for the show_work draft calls and the `rubric_draft` prompt. Do NOT call real Gemini without asking the user (costs money). Fix only a cause you can show in a failing test; if the cause is "by design / screen shows steps elsewhere", record that in §22.20.2 (replace the "ปัญหาที่รู้แล้ว" bullet with the finding) instead of changing code.
- [ ] **Step 4: Run** the new test and `php artisan test --filter=Rubric` → PASS.
- [ ] **Step 5: Commit** `fix(backend): rubric drafts for written exam questions` (or `docs:` if only the spec bullet changed).

---

### Task 9: Backend regression gate

- [ ] **Step 1: Run** `cd backend && php artisan test` — the whole suite, exam tests included, unchanged and green (Review Focus 1). If a pre-existing exam test fails, the change is wrong, not the test; fix the code. Exception: tests that enumerate section types or count questions for sheet capacity may need `written` excluded explicitly; changing them requires a line in the commit body.
- [ ] **Step 2: Check `QaCatalog` refs**: `rg "ExamWritten|ExamBooklet" backend/app/Domain/Qa` — if an automated case lists exam test files, add the new test files to its `refs` so `QaLabTest` stays green.
- [ ] **Step 3: Commit** any fix-ups `test(backend): keep exam suite green with written sections`.

---

### Task 10: App models

**Files:**
- Modify: `app/lib/features/exams/exam_models.dart`
- Test: `app/test/exams/exam_written_models_test.dart`

**Interfaces:**
- Produces: `ExamSectionType.open('open','อัตนัย')`, `ExamSectionType.showWork('show_work','แสดงวิธีทำ')`, getter `bool get isWritten`; `ExamSection.choiceCount` returns 0 for written; `ExamSection.typeSummary` → `เขียนตอบ (อัตนัย)` / `เขียนตอบ (แสดงวิธีทำ)`; `ExamQuestion.answerLines` (`int?`), `ExamQuestion.rubricStatus` (`String`, default `not_needed`), getter `rubricApproved` (`rubricStatus == 'approved'`); `ExamQuestion.finalAccepted` (`List<String>`, parsed from `answer_key.final.accepted`); `ExamIncomplete.reasonLabel('no_rubric')` → `rubric ยังไม่อนุมัติ`; `ExamQuestionDraft.answerLines` (`int?`) and `finalAccepted` (`List<String>?`) serialised as `answer_lines` and `answer_key: {final: {accepted: [...]}}` (show_work only; open sends no `answer_key`, written drafts never send `options`/`lock_options`); `ExamSectionDraft.toCreateJson` unchanged for written (no `option_count`/`numeric`); `ExamDetail.choiceQuestions` (questions of non-written sections, used by the key table).

- [ ] **Step 1: Failing test** `exam_written_models_test.dart`: `ExamQuestion.fromJson` of a payload with `type:'show_work'`, `answer_lines:6`, `rubric_status:'draft'`, `answer_key:{final:{accepted:['12']}}` → `answerLines` 6, `rubricApproved` false, `finalAccepted == ['12']`, `key` null (the options/values key class must not choke on this shape); `ExamSection.fromJson` type `open` → `choiceCount` 0, `typeSummary` as above; `ExamQuestionDraft(type: showWork, finalAccepted: ['12'], answerLines: 6).toJson()` has no `options`/`lock_options` and has the `answer_key` above; `ExamIncomplete.fromJson` reason `no_rubric` summary text.
- [ ] **Step 2: Run** `cd app && flutter test test/exams/exam_written_models_test.dart` → FAIL.
- [ ] **Step 3: Implement** the interfaces. Every `switch` over `ExamSectionType` gets the two cases (the analyzer flags missing ones: `flutter analyze` shows the exhaustive-switch errors in `exam_models.dart`; `exam_scan_*` files only parse `fromApi` and need no case).
- [ ] **Step 4: Run** the test and `flutter analyze` → PASS / no issues.
- [ ] **Step 5: Commit** `feat(app): written section types in the exam models`.

---

### Task 11: Section dialog

**Files:**
- Modify: `app/lib/features/exams/exam_section_dialog.dart`, `app/lib/features/exams/exam_screen.dart` (the call site of `showExamSectionDialog`)
- Test: `app/test/exams/exam_form_test.dart` (existing dialog tests live here; extend)

**Interfaces:**
- Produces: `showExamSectionDialog(..., {bool allowWritten = false})`; `ExamSectionDialog.allowWritten`. `exam_screen.dart` passes `allowWritten: detail.exam is an app exam with QR sheets` (a getter on `ExamDetail`: `!isManual && !usesCodeSheet`; add it next to `isManual`, reading the same exam fields the settings screen uses for the sheet identity).

- [ ] **Step 1: Failing widget tests:** with `allowWritten: true` the type selector offers `อัตนัย` and `แสดงวิธีทำ`; choosing one hides the option-count and digit-block controls, prefills points `5`, and `ExamSectionDraft` returned has that type, `optionCount` null, `numeric` null, `defaultPoints` 5; with `allowWritten: false` (manual exam or shared sheet) the two choices are absent (or disabled with the Thai reason `ข้อเขียนใช้กับข้อสอบตรวจด้วยแอปที่ใช้กระดาษแบบ QR รายคนเท่านั้น`).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** in the dialog (type chooser reuses the existing control; no new widget file). Server errors (`exam_written_section_last`) already surface through `apiErrorMessage`.
- [ ] **Step 4: Run** `flutter test test/exams` → PASS.
- [ ] **Step 5: Commit** `feat(app): add written sections from the exam screen`.

---

### Task 12: Written question form, tiles and key table

**Files:**
- Modify: `app/lib/features/exams/exam_question_screen.dart`, `app/lib/features/exams/exam_screen.dart` (question tile at the "ยังไม่มีเฉลย" label), `app/lib/features/exams/exam_answer_key_screen.dart`
- Test: `app/test/exams/exam_screen_test.dart`, `app/test/exams/exam_answer_key_screen_test.dart`, new `app/test/exams/exam_written_question_screen_test.dart`

**Interfaces:**
- Consumes: Task 10 models; homework `RubricScreen(assignmentId: examId, questionId: q.id)` from `features/assignments/rubric_screen.dart` (it loads `GET /assignments/{id}`, which already returns exam questions with `rubric_criteria`, no change needed).
- Produces: route/navigation to `RubricScreen` from the question form via the same `context.push` pattern other exam screens use (check `core/router` for the existing rubric route and reuse it).

- [ ] **Step 1: Failing widget tests** (`exam_written_question_screen_test.dart`, fakes from `exam_fakes.dart`, a detail with an `open` and a `show_work` section):
  - open question form shows prompt, image field, points, a lines stepper (1–15, default from the question or 8), a rubric status line (`rubric ยังไม่อนุมัติ` / `rubric อนุมัติแล้ว`) and a `ตั้ง rubric` button; it shows NO option rows, NO `ห้ามสลับตัวเลือก`, NO key chips/values field.
  - show_work form also shows `คำตอบสุดท้าย` (comma-separated accepted answers); saving calls `updateQuestion` with `answer_lines` and `answer_key.final.accepted`.
  - tapping `ตั้ง rubric` navigates to `RubricScreen` for that question; a new unsaved question shows the button disabled with `บันทึกข้อก่อนแล้วจึงตั้ง rubric`.
  - a blank (unapproved) written question shows the hint `ตั้ง rubric แล้วข้อนี้จะอนุมัติเอง` instead of the "ใส่เฉลย" hint of choice questions; an approved written question with a draft rubric shows only the rubric status line.
  - `exam_screen_test`: a written question tile shows a rubric chip (`rubric ยังไม่อนุมัติ` / `rubric อนุมัติแล้ว`) instead of `ยังไม่มีเฉลย`; `exam_answer_key_screen_test`: written questions do not appear in the key table and the table's count ignores them.
  - widths: the new form test pumps at 390, 820 and 1440 px (`tester.view.physicalSize`) and finds no overflow exception.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the written branch in `_ExamQuestionFormState` (`bool get _written => _section.type.isWritten`): extend `_draft()` with `answerLines`/`finalAccepted`, skip `_key()` and option controllers for written sections (controllers are built from `section.choiceCount`, which is 0, so options already vanish; guard the numeric/true-false key widgets). Tile label and key table use `ExamDetail.choiceQuestions`. Colours/shapes from the theme only.
- [ ] **Step 4: Run** `flutter test test/exams && flutter analyze` → PASS.
- [ ] **Step 5: Commit** `feat(app): edit written exam questions and open their rubric`.

---

### Task 13: Help text, step bookkeeping and final gates

**Files:**
- Modify: `app/lib/features/help/help_content.dart` (exam topic), `docs/DESIGN.md` (§22.20.9 step 1 → `[x]`)

- [ ] **Step 1:** Add one help paragraph in the exam topic: `ตอนเขียนตอบ (อัตนัยและแสดงวิธีทำ) ใช้ได้กับข้อสอบตรวจด้วยแอปที่ใช้กระดาษแบบ QR รายคน ตั้งโจทย์ คะแนน จำนวนบรรทัด และ rubric แล้วพิมพ์ลงเล่มข้อสอบได้ ส่วนกระดาษคำตอบและการสแกนข้อเขียนจะตามมาในรอบถัดไป` (if the help tests count topics or text, update them in the same step).
- [ ] **Step 2:** Mark §22.20.9 step 1 `[x]` with the PR number once known.
- [ ] **Step 3: Gates:** `cd backend && php artisan test`; `cd app && flutter analyze && flutter test` — all green. Ask the user before starting any server for a visual check at 390/820/1440 px (port rules in memory: ask first); the widget tests above are the automated check.
- [ ] **Step 4: Commit** `docs: mark step 1 of written exam sections done` and open the PR (`feat(app,backend): written sections for exams, step 1 of §22.20`), body: what is deliberately not built (answer sheet pages, scanning, Gemini grading, booklet page number) and the temporary `exam_written_not_ready` guard.
