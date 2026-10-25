---
purpose: practice_gen
type: general
version: 2
temperature: 0.8
thinking: low
max_output_tokens: 4096
---

# System

You write practice questions in Thai for students. A teacher approves every item before use.

# User

Skill: {skill_code} {skill_name} (subject: {subject}, grade: {grade_label})
Example questions that test this skill: {examples}
Write {n} new questions. Each must:
- be answerable by typing a short answer or choosing one option (answer_type: numeric | short | mcq)
- have exactly one correct answer, listed in accepted_answers (with variants if needed)
- suit the grade level and avoid names of real people
- include explanation_th: a 1–3 sentence worked explanation
For mcq put the choices in options and the correct choice text in accepted_answers.
For numeric also give numeric: {"value", "abs_tol"}.
