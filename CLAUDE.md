# CLAUDE.md - EduVision (repo: phuwishpk/Teacherhelper)

AI homework-grading platform for Thai schools: Flutter Android app + Laravel API on shared Plesk hosting + a small ML folder.
Group course project. One developer (phuwishpk) writes all code with Claude Code; teammates edit docs/ and CSV files through the GitHub web UI.

## Read first
- docs/DESIGN.md is the settled system design (architecture, MariaDB schema, API, fuzzy rules, Gemini prompts, phases). Do not re-decide anything it settles. If a change is truly needed, edit DESIGN.md in the same commit/PR and explain why in the commit body.
- docs/KICKOFF.md is the M0 plan, repo rules and definition of done. Where DESIGN §7.6 (document root) or §15 row "Phase 0" disagrees with KICKOFF.md, KICKOFF.md wins until issue M0-17 updates DESIGN.
- Current milestone: M0 "walking skeleton". NOT in M0: CI/CD, Firebase/FCM, camera/OpenCV/Pigeon, Gemini, drift, Cloudflare. Do not add them early.

## Layout
- app/      Flutter, Android only. Follow DESIGN §6.1: lib/core/{api,auth,router,theme}, lib/features/<feature>/. Riverpod + go_router + dio + flutter_secure_storage. applicationId com.eduvision.app, minSdk 26.
- backend/  Laravel (composer.json php ^8.3) + Sanctum + Filament 4. Follow DESIGN §7.1: app/Http/Controllers/Api/V1, app/Domain/*, app/Jobs. Queue driver and cache driver = database. No Redis.
- ml/       Python 3.12 managed by uv. Skeleton only in M0.
- docs/     Design and technical docs (Thai). tools/ holds helper scripts (hosting-probe.php).

## Conventions
- Code identifiers, commit messages and code comments: English. UI strings, docs, issues: Thai.
- Commits follow Conventional Commits with scope app|backend|ml|docs|tools|repo, e.g. `feat(backend): add teacher login endpoint`. The scope may be omitted for `docs:` commits that touch only docs/. Reference issues with `Refs #12` or `Closes #12`.
- M0: commit directly to main. From Phase 1: short-lived branch `<type>/<area>-<topic>` + PR, squash-merged by the developer. Never force-push or rewrite main (a ruleset blocks it). Never use `git commit --no-verify` (the local pre-commit hook is the Gemini-key guard).
- API: base path /api/v1, snake_case JSON, errors `{message, errors, code}` (DESIGN §9). Store timestamps in UTC, display Asia/Bangkok.
- Keep `flutter analyze`, `flutter test` and `php artisan test` green before every commit (no CI yet, so this is manual).

## Commands
Backend (local PHP 8.5 from Homebrew; MariaDB runs as an OrbStack container, see docs/KICKOFF.md for the container command):
  cd backend && composer install && cp -n .env.example .env && php artisan key:generate
  php artisan migrate && php artisan serve                   # AVD: http://10.0.2.2:8000
  php artisan serve --host=0.0.0.0 --port=8000               # physical phone: http://<Mac LAN IP>:8000 (ipconfig getifaddr en0)
  php artisan queue:work --stop-when-empty --max-time=50
  php artisan test
App (an AVD reaches the Mac at 10.0.2.2; a phone on the same Wi-Fi uses the Mac's LAN IP and needs the --host=0.0.0.0 serve above):
  cd app && flutter pub get
  flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
  flutter test && flutter analyze
  flutter run -d chrome --dart-define=API_BASE_URL=http://127.0.0.1:8000   # quick UI preview in Chrome (web runner kept for this only; Android is the target)
  Local http:// only works because app/android/app/src/debug/ ships a network_security_config.xml that permits cleartext (debug build only; release stays https-only). Do not add it to src/main.
ML:
  cd ml && uv sync

## Hosting constraints (production = Hostatom shared Plesk: no SSH, no Docker, no daemons)
- The queue worker is a Plesk Scheduled Task running `artisan queue:work --stop-when-empty --max-time=50` every minute. Never add Horizon, Redis, websockets, long-running processes, or anything that needs `schedule:run` (requires proc_open).
- Keep CPU-heavy work off the server; image processing happens on the phone.
- Local PHP is 8.5 but the server is 8.3 or 8.4 (unconfirmed until the hosting probe reports). Do not use syntax newer than PHP 8.3 in backend/.
- backend/composer.json must pin the resolver to the server's PHP: `"config": {"platform": {"php": "8.3.0"}}` (update to the exact version the hosting probe reports). Without it composer.lock is resolved against the local 8.5 and the Plesk Composer extension fails with a platform-check error, which is hard to diagnose without SSH. Never run `composer update` without that setting; commit composer.lock.
- Deployment = Plesk Git pull + Composer extension; `.env` lives outside the document root on the server; migrations run through a Scheduled Task "Run now" with `artisan migrate --force` (production is non-interactive).
- Do not use the Homebrew MySQL 9.6 on the Mac. The target database is MariaDB.

## This repo is PUBLIC
- Never commit secrets: .env, Gemini API keys, Firebase files, keystores, service-account JSON, Plesk credentials, real student data or real student handwriting (test handwriting comes from the team only, DESIGN §16.2). Push protection is on but does NOT block Google API keys (GitHub only alerts after the push); a local pre-commit hook and .gitignore are the real guard. Read keys only from .env via config().
- Prompts under backend/resources/prompts are public by design; keep school-specific data out of them.
- When unsure: ask before adding a dependency, an external service, or a schema column that is not in DESIGN §8. Prefer the boring option that works on shared hosting.
