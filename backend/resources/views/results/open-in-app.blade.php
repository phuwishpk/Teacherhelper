{{--
    GET /r/{submission_id} (ResultLinkController, DESIGN §19.7): the link in a
    private Classroom announcement opens the result in the app. Shows no
    student data; self-contained (the CSP allows inline styles only).
--}}
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>เปิดผลในแอป EduVision</title>
<style>
    :root { --bg: #f4f6fb; --card: #ffffff; --text: #1d2433; --muted: #5b6475; --line: #e3e7ef; --accent: #2f5bd3; --on-accent: #ffffff; }
    @media (prefers-color-scheme: dark) {
        :root { --bg: #12151c; --card: #1c212b; --text: #e8ebf2; --muted: #a3abbb; --line: #2c3340; --accent: #8fb0ff; --on-accent: #0f1830; }
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        padding: 24px 16px; background: var(--bg); color: var(--text);
        font: 16px/1.6 system-ui, -apple-system, "Segoe UI", "Noto Sans Thai", "Sarabun", Tahoma, sans-serif;
    }
    main { width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; }
    h1 { font-size: 1.25rem; line-height: 1.4; margin: 0 0 8px; }
    p { margin: 0 0 12px; }
    .button {
        display: block; text-align: center; margin: 20px 0 16px; padding: 12px 16px; border-radius: 12px;
        background: var(--accent); color: var(--on-accent); font-weight: 600; text-decoration: none;
    }
    .muted { color: var(--muted); font-size: 0.9rem; }
    footer { margin-top: 20px; padding-top: 12px; border-top: 1px solid var(--line); }
</style>
</head>
<body>
<main>
    <h1>เปิดผลในแอป EduVision</h1>
    <p>ผลการตรวจงานพร้อมคำอธิบายรายข้ออยู่ในแอป EduVision กดปุ่มด้านล่างบนมือถือ Android ที่ติดตั้งแอปไว้</p>
    <a class="button" href="{{ $intentUrl }}">เปิดในแอป EduVision</a>
    <p class="muted">ถ้ากดแล้วไม่เปิด ให้เปิดแอป EduVision เอง เข้าสู่ระบบด้วยบัญชีนักเรียน แล้วไปที่ "ผลการตรวจ"</p>
    <footer class="muted">EduVision</footer>
</main>
</body>
</html>
