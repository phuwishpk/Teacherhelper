{{--
    The SUS pages for evaluators (DESIGN §39.7): a questionnaire, not a back
    office. One calm column, large touch targets, no script (the CSP set by
    SusController allows inline styles only). Light and dark follow the
    device.
--}}
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title') · Krucheck</title>
<style>
    :root {
        --bg: #eef4f2; --paper: #ffffff; --ink: #18302c; --muted: #5d716c; --line: #d6e3df;
        --accent: #0f766e; --accent-ink: #ffffff; --accent-soft: #d8efe9;
        --error: #b42318; --error-soft: #fde8e6; --ok: #0e6b3f; --ok-soft: #dff3e6;
        --radius: 14px;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #0f1716; --paper: #172321; --ink: #e4efec; --muted: #9db3ad; --line: #28403b;
            --accent: #4fd1bf; --accent-ink: #062521; --accent-soft: #163a35;
            --error: #ff8f84; --error-soft: #3d1715; --ok: #6fdba0; --ok-soft: #14301f;
            color-scheme: dark;
        }
    }
    * { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body {
        margin: 0; background: var(--bg); color: var(--ink);
        font: 17px/1.7 "Sarabun", "Noto Sans Thai", "Leelawadee UI", "Thonburi", system-ui, sans-serif;
        padding: 0 16px 56px;
    }
    .wrap { max-width: 680px; margin: 0 auto; }
    header.top { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 22px 0 18px; }
    .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; letter-spacing: .01em; }
    .mark { width: 34px; height: 34px; border-radius: 10px; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; font-weight: 800; }
    .brand small { display: block; font-weight: 500; color: var(--muted); font-size: .8rem; line-height: 1.2; }
    h1 { font-size: 1.55rem; line-height: 1.35; margin: 8px 0 6px; text-wrap: balance; }
    h2 { font-size: 1.1rem; margin: 0 0 8px; }
    p { margin: 0 0 10px; }
    .muted { color: var(--muted); }
    .small { font-size: .88rem; }
    .card { background: var(--paper); border: 1px solid var(--line); border-radius: var(--radius); padding: 20px; margin: 0 0 14px; }
    .intro ol { margin: 6px 0 0; padding-left: 1.3em; }
    .intro li { margin-bottom: 4px; }
    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 4px 0 14px; }
    .tab { flex: 1 1 200px; text-decoration: none; color: var(--ink); border: 1px solid var(--line); background: var(--paper); border-radius: 12px; padding: 10px 14px; }
    .tab b { display: block; }
    .tab span { font-size: .85rem; color: var(--muted); }
    .tab.on { border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
    .q { display: grid; gap: 10px; }
    .q .num { color: var(--accent); font-weight: 800; margin-right: 6px; }
    .q .en { font-size: .85rem; color: var(--muted); font-style: italic; }
    fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
    legend { padding: 0; font-weight: 600; margin-bottom: 8px; }
    .likert { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; }
    .choice { position: relative; }
    .choice input { position: absolute; opacity: 0; inset: 0; margin: 0; cursor: pointer; }
    .choice span { display: grid; place-items: center; min-height: 52px; border: 1.5px solid var(--line); border-radius: 12px; font-weight: 700; font-size: 1.1rem; background: var(--paper); }
    .choice input:checked + span { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
    .choice input:focus-visible + span { outline: 3px solid var(--accent); outline-offset: 2px; }
    .ends { display: flex; justify-content: space-between; gap: 12px; font-size: .8rem; color: var(--muted); }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .chips .choice span { min-height: 44px; padding: 6px 14px; font-size: .95rem; font-weight: 600; }
    .stack { display: grid; gap: 8px; }
    .stack .choice span { place-items: center start; padding: 8px 14px; min-height: 46px; font-size: .95rem; font-weight: 600; text-align: left; }
    .stack .choice span em { font-style: normal; font-weight: 400; color: var(--muted); margin-left: 6px; }
    .stack .choice input:checked + span em { color: inherit; opacity: .85; }
    label.field { display: grid; gap: 6px; font-weight: 600; }
    input[type=email], input[type=password], textarea {
        width: 100%; font: inherit; color: inherit; background: var(--paper);
        border: 1.5px solid var(--line); border-radius: 12px; padding: 12px 14px;
    }
    input:focus, textarea:focus { outline: 3px solid var(--accent-soft); border-color: var(--accent); }
    textarea { min-height: 96px; resize: vertical; }
    .btn { display: inline-block; font: inherit; font-weight: 700; border: 0; border-radius: 12px; padding: 13px 22px; background: var(--accent); color: var(--accent-ink); cursor: pointer; text-decoration: none; }
    .btn.full { width: 100%; }
    .btn.ghost { background: transparent; color: var(--muted); border: 1px solid var(--line); padding: 7px 14px; font-weight: 600; font-size: .9rem; }
    .alert { border-radius: 12px; padding: 12px 16px; margin: 0 0 14px; }
    .alert.error { background: var(--error-soft); color: var(--error); }
    .alert.ok { background: var(--ok-soft); color: var(--ok); }
    .err { color: var(--error); font-size: .88rem; }
    .has-error { border-color: var(--error) !important; }
    footer { margin-top: 28px; text-align: center; font-size: .8rem; color: var(--muted); }
    @media (max-width: 420px) {
        body { font-size: 16px; }
        .card { padding: 16px; }
        .choice span { min-height: 48px; }
    }
</style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <div class="brand"><span class="mark" aria-hidden="true">K</span><span>Krucheck<small>แบบประเมินความง่ายในการใช้งาน</small></span></div>
        @yield('header-end')
    </header>
    @yield('content')
    <footer>แบบประเมิน System Usability Scale (SUS) · ใช้เพื่อปรับปรุง Krucheck เท่านั้น</footer>
</div>
</body>
</html>
