{{--
    Error page of GET /auth/google/callback (GoogleSignInCallbackController)
    when there is nowhere safe to redirect to: an unknown, expired or spent
    state, or the browser flow switched off. Self-contained: no external
    asset, no script, never indexed.
--}}
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>{{ $title }} · EduVision</title>
<style>
    :root { --bg: #f4f6fb; --card: #ffffff; --text: #1d2433; --muted: #5b6475; --line: #e3e7ef; --bad: #b3261e; --bad-bg: #fbe4e2; }
    @media (prefers-color-scheme: dark) {
        :root { --bg: #12151c; --card: #1c212b; --text: #e8ebf2; --muted: #a3abbb; --line: #2c3340; --bad: #f2a29c; --bad-bg: #3d1c1a; }
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        padding: 24px 16px; background: var(--bg); color: var(--text);
        font: 16px/1.6 system-ui, -apple-system, "Segoe UI", "Noto Sans Thai", "Sarabun", Tahoma, sans-serif;
    }
    main { width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; }
    .badge { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 16px; background: var(--bad-bg); color: var(--bad); }
    .badge svg { width: 26px; height: 26px; }
    h1 { font-size: 1.25rem; line-height: 1.4; margin: 0 0 8px; }
    p { margin: 0 0 12px; }
    footer { margin-top: 20px; padding-top: 12px; border-top: 1px solid var(--line); color: var(--muted); font-size: 0.9rem; }
</style>
</head>
<body>
<main>
    <div class="badge" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6.5v7"/><path d="M12 17.5v.01"/></svg>
    </div>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    <footer>EduVision · เข้าสู่ระบบด้วย Google</footer>
</main>
</body>
</html>
