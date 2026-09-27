{{--
    Result of GET /google/oauth/callback (GoogleOAuthCallbackController).
    Self-contained on purpose: no external asset, no script (the controller
    sends a CSP that allows inline styles only), never indexed.
    $kind: success | cancelled | error; $missing: Thai labels of scopes that
    were not granted; $email / $teacher only on success.
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
    :root {
        --bg: #f4f6fb; --card: #ffffff; --text: #1d2433; --muted: #5b6475; --line: #e3e7ef;
        --ok: #1f7a3d; --ok-bg: #e5f4ea; --warn: #8a5a00; --warn-bg: #fdf1d8; --bad: #b3261e; --bad-bg: #fbe4e2;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #12151c; --card: #1c212b; --text: #e8ebf2; --muted: #a3abbb; --line: #2c3340;
            --ok: #7fd79b; --ok-bg: #173323; --warn: #f2c46b; --warn-bg: #3a2e12; --bad: #f2a29c; --bad-bg: #3d1c1a;
        }
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        padding: 24px 16px; background: var(--bg); color: var(--text);
        font: 16px/1.6 system-ui, -apple-system, "Segoe UI", "Noto Sans Thai", "Sarabun", Tahoma, sans-serif;
    }
    main {
        width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--line);
        border-radius: 16px; padding: 28px 24px;
    }
    .badge {
        width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
        margin-bottom: 16px;
    }
    .badge svg { width: 26px; height: 26px; }
    .success .badge { background: var(--ok-bg); color: var(--ok); }
    .cancelled .badge { background: var(--warn-bg); color: var(--warn); }
    .error .badge { background: var(--bad-bg); color: var(--bad); }
    h1 { font-size: 1.25rem; line-height: 1.4; margin: 0 0 8px; }
    p { margin: 0 0 12px; }
    .account { padding: 12px 14px; border-radius: 10px; background: var(--bg); border: 1px solid var(--line); margin: 16px 0; }
    .account strong { word-break: break-all; }
    ul { margin: 0 0 12px; padding-left: 1.25rem; }
    li { margin-bottom: 4px; }
    .muted { color: var(--muted); font-size: 0.9rem; }
    footer { margin-top: 20px; padding-top: 12px; border-top: 1px solid var(--line); }
</style>
</head>
<body>
<main class="{{ $kind }}">
    <div class="badge" aria-hidden="true">
        @if ($kind === 'success')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        @elseif ($kind === 'cancelled')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 12h12"/></svg>
        @else
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 6.5v7"/><path d="M12 17.5v.01"/></svg>
        @endif
    </div>

    <h1>{{ $title }}</h1>

    @if ($kind === 'success' && $email)
        <div class="account">
            บัญชี Google <strong>{{ $email }}</strong>
            @if ($teacher)
                เชื่อมกับบัญชีครู <strong>{{ $teacher }}</strong> ใน EduVision แล้ว
            @else
                เชื่อมกับ EduVision แล้ว
            @endif
        </div>
    @endif

    <p>{{ $message }}</p>

    @if (! empty($missing))
        <p>สิทธิ์ที่ยังไม่ได้ติ๊กอนุญาต:</p>
        <ul>
            @foreach ($missing as $label)
                <li>{{ $label }}</li>
            @endforeach
        </ul>
    @endif

    @if ($kind === 'success')
        <p class="muted">ถ้าชื่อครูด้านบนไม่ใช่คุณ อย่าใช้ต่อ: ยกเลิกสิทธิ์ที่ myaccount.google.com → ความปลอดภัย → การเชื่อมต่อกับแอปและบริการของบุคคลที่สาม → EduVision</p>
    @endif

    <footer class="muted">EduVision · Google Classroom</footer>
</main>
</body>
</html>
