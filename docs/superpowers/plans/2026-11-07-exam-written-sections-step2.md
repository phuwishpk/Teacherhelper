# Exam written sections — Step 2 (written pages on the answer sheet, printing) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (the user chose inline execution for step 1; same here). Steps use checkbox (`- [ ]`) syntax.

**Goal:** The answer sheet of an exam with written sections has, after its bubble pages, written pages (a signed `EV1` QR naming the student, a box of lines per written question) that print per student in one PDF with the bubble pages; the layout JSON the phone will crop from stores them; the booklet says on which page to write.

**Architecture:** `ExamWrittenLayout` paginates the written questions with the worksheet's own `RegionGeometry` (so the region JSON is the §5.3 shape the native `cropPage` already crops). `ExamSheetPlan` gains `writtenPages` and appends them to `toLayoutPages` as `"sheet": "written"` pages of the same layout version. `ExamSheetPdfRenderer` draws them for a student (never for the key sheet). The step-1 guard on printing answer sheets is deleted; the guard on the scan-kit stays until step 3 (the app cannot read these pages yet).

**Tech Stack:** Laravel 12 (PHP 8.3 syntax), mPDF, PHPUnit feature tests with `ExamTestHelpers`.

**Spec:** `docs/DESIGN.md` §22.20.3 (paper), §22.20.7 (config), §22.20.9 step 2.

## Global Constraints

- PHP syntax no newer than 8.3; no composer changes; Conventional Commits, English, scope `backend`/`docs`, no `--no-verify`, no Claude attribution. Stage files by path (never `git add -A`: `app/ios/Runner.xcodeproj/project.pbxproj` and `sendwork/` are not ours).
- Branch `feat/exams-written-sheet` off main (done). Merging to main deploys production: ask the user first.
- Written block geometry (spec §22.20.3, constants from `WorksheetGeometry`): content area y 48–270 (`CONTENT_TOP`, `CONTENT_BOTTOM`), label "ข้อ n (x คะแนน)" 7 mm high, `QUESTION_GAP` 6 mm between blocks, a block is never split across pages, lines 10 mm (`LINE_H`), show_work adds `FINAL_GAP` 3 + `BOX_H` 16.
- Limits in `config('eduvision.exams')`: `max_written_pages` = 4, `sheet_batch_size_written` = 10 (bubble pages stay ≤ 2). Overflow of the written pages is 422 `exam_sheet_overflow`, message `ข้อเขียนยาวเกิน 4 หน้า ลดจำนวนข้อหรือจำนวนบรรทัด`.
- Written pages: `EV1.{assignment}.{student}.{page}.{layout_version}.{sig}` via `QrSigner::sign`, page numbers continue after the bubble pages, every page object carries `page_count` = bubble + written; the key sheet and the shared sheet have no written pages (a shared sheet cannot coexist with written sections anyway).
- Keep green: `cd backend && php artisan test` and `vendor/bin/pint --test`; MariaDB-safe (no schema change here).

## Review Focus

1. An exam with no written section prints byte-identical layouts (`ExamSheetLayoutTest`, `ExamSheetPdfTest`, `ExamPrintsTest` untouched and green).
2. Changing the lines of a written question after the first print gives a new layout version only after unlocking (structure lock) and the PDF renderer refuses a stale layout.
3. The key sheet of an exam with written sections has only bubble pages and says "หน้า x/B" with B = bubble pages.
4. A written block taller than the free space starts a new page; a 15-line show_work block still fits one page (176 mm of 222).
5. The booklet of an exam whose written pages overflow still prints (the hint just has no page number).

---

### Task 1: `ExamWrittenLayout`

**Files:** Create `backend/app/Domain/Exams/ExamWrittenLayout.php`; Test `backend/tests/Feature/Exams/ExamWrittenLayoutTest.php`

**Interfaces:** Produces `ExamWrittenLayout::LABEL_H = 7.0`; `ExamWrittenLayout::blocks(Assignment $exam): list<array{question: Question, number: int, points: float, label_top: float, region: RegionGeometry, page_index: int}>` — written questions of the exam in `position` order, `page_index` 0-based among the written pages, `region` from `RegionGeometry::at($question, label_top + LABEL_H)`; `ExamWrittenLayout::pageCount(Assignment $exam): int` (0 when none).

- [ ] Failing tests: (a) 3 `open` questions of 8 lines → `page_index` `[0,0,1]`, first `label_top` 48.0, its region `rect.y` 55.0 and `rect.h` 80.0, second `label_top` 48+87+6 = 141.0; (b) a `show_work` of 15 lines has block height 7+150+3+16 = 176 and region `final` below the lines; (c) no written questions → `[]` and `pageCount` 0; (d) bubble questions are ignored and `number` is the question `position`.
- [ ] Run → FAIL; implement (greedy top-down, new page when `top + height > CONTENT_BOTTOM`); run → PASS; commit `feat(backend): paginate the written questions of an exam`.

### Task 2: Plan and layout JSON

**Files:** Modify `backend/app/Domain/Exams/ExamSheetPlan.php`, `ExamSheetLayout.php` (`forExam`, `plan`, `assertFits`), `backend/config/eduvision.php`; Test `backend/tests/Feature/Exams/ExamSheetLayoutTest.php` (extend)

**Interfaces:** `ExamSheetPlan::__construct(array $pages, int $versionCount, int $codeDigits = 0, array $writtenBlocks = [], int $writtenPages = 0)`; `bubblePageCount(): int`, `writtenPageCount(): int`, `pageCount(): int` (= both), `overflows()` (bubble pages > `ExamSheetCapacity::MAX_PAGES`), `writtenOverflows()` (> `config('eduvision.exams.max_written_pages')`); `toLayoutPages` appends the written pages. `ExamSheetLayout::assertFits` throws the written overflow. `ExamSheetLayout::writtenPageOf(Assignment $exam): array<int, int>` question id => sheet page (empty when none or overflowing, never throws).

- [ ] Failing tests: exam with 6 mcq + 5 open (8 lines) → `toLayoutPages` has 4 pages, pages 2–4 `"sheet" => "written"` (page 1 `"exam"`), every `page_count` 4; written regions `region_id` `q{id}`, `kind` `lines`, `question_id`, `line_count` 8, `rect` inside 0..1; exam with no written section → pages unchanged (compare to `plan()` of the same items); 12 open questions → `forExam` throws `exam_sheet_overflow` with the spec message; `writtenPageOf` maps questions to pages 2,2,3,3,4 and returns `[]` on overflow.
- [ ] Run → FAIL; implement; add the two config keys; run `--filter=ExamSheet` → PASS; commit `feat(backend): written pages in the answer-sheet layout`.

### Task 3: Draw the written pages

**Files:** Modify `backend/app/Domain/Exams/ExamSheetPdfRenderer.php`, `backend/app/Domain/Worksheets/WorksheetPdfRenderer.php` (`drawLines`, `drawBox` become `public static`); Test `backend/tests/Feature/Exams/ExamSheetPdfTest.php` (extend)

- [ ] Failing tests: rendering one student of the 6 mcq + 5 open exam gives 4 pages, the signed QR log shows `EVX1…1` then `EV1.{exam}.{student}.2…`, `…3`, `…4` (log `QrSigner::sign` as the test already logs `signExamSheet`); the key sheet renders 1 page and the header text says `หน้า 1/1`; the shared sheet and no-written exams render as before (existing tests).
- [ ] Implement: for a `WorksheetStudent`, after the bubble pages draw each written page (markers, header with `pageCount()`, `drawQr(sign(...))`, label "ข้อ n (x คะแนน)" at `label_top`, `WorksheetPdfRenderer::drawLines/drawBox` for the region, footer "เขียนคำตอบในกรอบของแต่ละข้อเท่านั้น"); header of key/shared sheets uses `bubblePageCount()`.
- [ ] Run `--filter=ExamSheetPdfTest` → PASS; commit `feat(backend): draw the written pages of the answer sheet`.

### Task 4: Printing

**Files:** Modify `backend/app/Domain/Exams/ExamPrintService.php` (remove the answer_sheet `assertSheetsReady` call, chunk size), `ExamWritten.php` (keep the method, doc says scan-kit only); Test `ExamWrittenSheetSideTest.php`, `ExamPrintsTest.php`

- [ ] Update `test_sheet_prints_and_scan_kit_are_refused_for_written_exams`: answer_sheet print is now 202 (enrol 3 students; `Queue::fake` as before) while scan-kit stays 422 `exam_written_not_ready`; a new test prints for real (queue sync): merged PDF pages = students × (bubble + written), `worksheet_prints.layout_version` set, the layout has the written pages, `structure_locked_at` set; chunk size is 10 when the exam has written sections (assert the number of `RenderAnswerSheetsJob` pushed for 25 students = 3 + merge).
- [ ] Implement; run `--filter="ExamPrints|ExamWritten"` → PASS; commit `feat(backend): print the answer sheet with its written pages`.

### Task 5: Booklet page hint and payload

**Files:** Modify `backend/app/Domain/Exams/ExamBooklet.php`, `ExamPayload.php`; Tests `ExamBookletTest.php`, `ExamWrittenSectionTest.php`

- [ ] Failing tests: booklet hint reads `เขียนตอบในกระดาษคำตอบหน้า 2` for a written question on written page 2 (exam of 6 mcq + 2 open, bubble 1 page); with an overflowing written layout the hint is the step-1 text without a page and the booklet still plans; `GET /exams/{id}` has `written_sheet: {pages, overflow}` next to the unchanged `sheet`.
- [ ] Implement (`ExamBooklet::plan` computes `ExamSheetLayout::writtenPageOf` once and passes the page into `questionHtml`); run → PASS; commit `feat(backend): booklet names the page to write on`.

### Task 6: Docs, gates

- [ ] DESIGN §22.20.9: step 2 `[x]`; correct the guard note (only the scan-kit keeps `exam_written_not_ready` until step 3); `docs/superpowers/plans` this file.
- [ ] `cd backend && php artisan test` (whole suite) and `vendor/bin/pint --test` green; fix any migration-step style tests (none expected, no migration here).
- [ ] Commit `docs: mark step 2 of written exam sections done`; push; PR; ask the user before merging.
