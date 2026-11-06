# Exam written sections — Step 4 (server files, grades and publishes the written pages) Plan

> Inline execution (executing-plans), TDD per task. Spec: `docs/DESIGN.md` §22.20.5. Step 3 (the app's one-screen scan) is NOT part of this: the user fills the printed PDFs on screen and a script files the pages like the phone would, so step 4 alone makes the exam gradable. The app only gets a parse tolerance for the new pages.

**Goal:** `POST /scans` takes the written pages of an exam (QR `EV1` of the exam, layout page `"sheet": "written"`), Gemini grades the crops against the rubric with the existing worksheet job, the teacher reviews them, and the submission is published only when bubbles and written answers are in and reviewed.

**Global constraints:** PHP ≤ 8.3, Conventional Commits (English), stage by path, Pint clean, full `php artisan test` green, no schema change. Merging deploys production: ask first.

## Decisions

- One place computes an exam submission's status: `SubmissionStatus::refresh` applies the page/version rule itself (moved from `ExamSheetIngestor::refreshStatus`, which becomes a thin wrapper), so every caller (`ScanGrader`, `ScanIngestor`, `ClassRegrade`, `ResponseReviewer`...) is exam-correct.
- A written response is owned by the written path: `ExamSheetGrader::apply` never deletes or rewrites a response whose question needs a rubric.
- `ScanIngestor` refuses an exam's page unless the exam is `app` with QR sheets and the page of the layout is `"sheet": "written"` (422 `page_mismatch`); the bubble pages still go to `POST /exam-sheets`.
- The scan-kit guard of step 1/2 goes away (the kit's `layouts` now carry the written pages; the app ignores them until step 3).

## Tasks

1. **Status**: `SubmissionStatus` exam rule; test: a written page graded and reviewed without its bubble page leaves the submission `needs_review`, and with all pages reviewed it is `reviewed`.
2. **Scan route**: replace the `ScanIngestor` exam guard by the written-page rule; tests: written page of an exam is filed (responses queued, `GradeScanJob` grades them with the fake Gemini), a bubble page `EV1`... → `page_mismatch`, manual and code-sheet exams refused, homework unchanged.
3. **Grader sparing**: `ExamSheetGrader::apply` keeps written responses; test: rescanning the bubble page leaves the written response and its reviewed state alone.
4. **Coverage / publish**: `SubmissionCoverage::examExpected` maps a written region by `question_id`; test: an exam submission missing its written page is "missing" with the page number and is not published by "ประกาศผลทั้งห้อง"; with every page and review it is.
5. **Sheet status**: pages carry `kind` (`bubble`/`written`), rows and summary carry `written_pending`; test.
6. **Guards**: remove `ExamWritten::assertSheetsReady` and its caller, allow `requeue-missing-key` for an exam with written sections; tests.
7. **No crashes**: results, option analysis, regrade, student result, sheet-status and the review queue with written responses present; fix what breaks.
8. App: `ExamScanKit` ignores `"sheet": "written"` pages of the kit (so the exam scan screen keeps working for the bubble pages).
9. Docs (§22.20.9 steps), gates, PR.
