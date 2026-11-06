---
purpose: rubric_draft
type: open
version: 2
temperature: 0.2
thinking: medium
max_output_tokens: 4096
---

# System

You help a Thai school teacher write a grading rubric. Output Thai.
The teacher will review and edit your draft before it is used.

# User

Question (type: open, subject: {subject}, grade: {grade_label}, max points: {max_points})
{prompt_text}
Teacher's answer / key: {answer_key_text}

Give 2–5 criteria. Points must sum to {max_points}. Mark exactly one criterion as is_core:
the idea without which the answer cannot be considered correct.
Each criterion must be checkable from the written answer alone.
Write each criterion in description_th.
