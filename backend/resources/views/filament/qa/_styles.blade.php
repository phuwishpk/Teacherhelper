{{-- Shared look of the Test Lab pages (DESIGN §39); neutrals follow Filament's light and dark themes. --}}
<style>
    .qa-grid { display: grid; gap: 16px; }
    .qa-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
    .qa-stat { border: 1px solid rgba(127,127,127,.25); border-radius: 12px; padding: 14px 16px; display: grid; gap: 2px; }
    .qa-stat .k { font-size: .8rem; opacity: .7; }
    .qa-stat .v { font-size: 1.6rem; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.25; }
    .qa-stat .s { font-size: .8rem; opacity: .7; }
    .qa-table-wrap { overflow-x: auto; }
    .qa-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .qa-table th { text-align: left; font-size: .75rem; font-weight: 600; opacity: .7; padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.3); white-space: nowrap; }
    .qa-table td { padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.15); vertical-align: top; }
    .qa-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; white-space: nowrap; }
    .qa-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .qa-muted { opacity: .7; }
    .qa-small { font-size: .8rem; }
    .qa-bar { display: flex; height: 8px; border-radius: 999px; overflow: hidden; background: rgba(127,127,127,.18); min-width: 90px; }
    .qa-bar i { display: block; height: 100%; }
    .qa-ok { background: rgb(22 163 74); } .qa-warn { background: rgb(217 119 6); } .qa-bad { background: rgb(220 38 38); }
    .qa-link { color: rgb(var(--primary-600)); text-decoration: underline; text-underline-offset: 2px; }
    .dark .qa-link { color: rgb(var(--primary-400)); }
    .qa-card { border: 1px solid rgba(127,127,127,.25); border-radius: 12px; padding: 16px; display: grid; gap: 10px; }
    .qa-card h3 { font-weight: 600; font-size: 1rem; }
    .qa-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .qa-seg { display: flex; flex-wrap: wrap; gap: 6px; }
    .qa-seg label { cursor: pointer; }
    .qa-seg input { position: absolute; opacity: 0; pointer-events: none; }
    .qa-seg span { display: inline-block; padding: 5px 12px; border-radius: 8px; border: 1px solid rgba(127,127,127,.35); font-size: .85rem; }
    .qa-seg input:checked + span { border-color: rgb(var(--primary-600)); background: rgba(var(--primary-500), .15); font-weight: 600; }
    .qa-seg input:focus-visible + span { outline: 2px solid rgb(var(--primary-500)); outline-offset: 2px; }
    .qa-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
    .qa-field { display: grid; gap: 4px; min-width: 0; }
    .qa-field label { font-size: .8rem; opacity: .75; }
    .qa-input { width: 100%; border-radius: 8px; border: 1px solid rgba(127,127,127,.35); background: transparent; padding: 7px 10px; color: inherit; }
    .qa-input:focus { outline: 2px solid rgb(var(--primary-500)); outline-offset: 1px; }
    .qa-ol { padding-left: 1.3em; list-style: decimal; display: grid; gap: 4px; }
    .qa-ul { padding-left: 1.3em; list-style: disc; display: grid; gap: 4px; }
    .qa-likert { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; max-width: 400px; }
    .qa-likert label { cursor: pointer; }
    .qa-likert input { position: absolute; opacity: 0; pointer-events: none; }
    .qa-likert span { display: grid; place-items: center; height: 38px; border-radius: 8px; border: 1px solid rgba(127,127,127,.35); font-weight: 600; }
    .qa-likert input:checked + span { background: rgb(var(--primary-600)); border-color: rgb(var(--primary-600)); color: #fff; }
    .qa-likert input:focus-visible + span { outline: 2px solid rgb(var(--primary-500)); outline-offset: 2px; }
</style>
