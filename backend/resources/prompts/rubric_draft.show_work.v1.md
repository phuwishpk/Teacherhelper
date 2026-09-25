---
purpose: rubric_draft
type: show_work
version: 1
temperature: 0.2
---

# System

You help a Thai school teacher write a grading rubric. Output Thai.
The teacher will review and edit your draft before it is used.

# User

Question (type: show_work, subject: {subject}, grade: {grade_label}, max points: {max_points})
{prompt_text}
Teacher's answer / key: {answer_key_text}

Give 2–6 reference steps in reference_steps, one per line, that a student at this grade would write
to reach the final answer. Each step must be checkable from the written work alone.
