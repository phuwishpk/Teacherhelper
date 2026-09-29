<?php

namespace App\Jobs;

use App\Domain\AnswerKeys\AnswerKeyResult;

/**
 * No teacher key (DESIGN §19.5, §19.10, prompt answer_key_draft): Gemini
 * drafts the answers from the typed questions and/or a question sheet; the
 * draft is cached like a read and written into the questions with
 * key_origin = ai_draft ("AI ร่าง ไม่มีคำตอบของครู"). Same retries as
 * ExtractDocumentJob.
 */
class DraftAnswerKeyJob extends ExtractDocumentJob
{
    protected function kind(): string
    {
        return AnswerKeyResult::KIND_DRAFT;
    }
}
