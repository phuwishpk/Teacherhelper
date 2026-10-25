{{-- The manual web test checklist with its test files (DESIGN §39.7). --}}
@use('App\Filament\Qa\Pages\MyResults')
<x-filament-panels::page>
    @include('filament.qa._styles')
    <style>
        .qa-md { font-size: .925rem; line-height: 1.7; display: grid; gap: 10px; min-width: 0; }
        .qa-md h1 { font-size: 1.35rem; font-weight: 700; }
        .qa-md h2 { font-size: 1.1rem; font-weight: 600; margin-top: 18px; padding-top: 12px; border-top: 1px solid rgba(127,127,127,.25); }
        .qa-md strong { font-weight: 600; }
        .qa-md ul { padding-left: 1.3em; list-style: disc; display: grid; gap: 4px; }
        .qa-md li:has(> input[type=checkbox]) { list-style: none; margin-left: -1.3em; display: flex; gap: 8px; align-items: baseline; }
        .qa-md li input[type=checkbox] { width: 1.05rem; height: 1.05rem; flex: none; position: relative; top: 2px; cursor: pointer; accent-color: var(--primary-600); }
        .qa-md li.qa-done { opacity: .55; }
        .qa-md a, .qa-sticky a { color: var(--primary-600); text-decoration: underline; text-underline-offset: 2px; }
        .dark .qa-md a, .dark .qa-sticky a { color: var(--primary-400); }
        .qa-md code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .82rem; background: rgba(127,127,127,.15); border-radius: 4px; padding: 1px 4px; overflow-wrap: anywhere; }
        .qa-md blockquote { border-left: 3px solid var(--primary-500); padding: 6px 12px; background: color-mix(in oklab, var(--primary-500) 8%, transparent); border-radius: 0 8px 8px 0; }
        .qa-md hr { border: 0; border-top: 1px solid rgba(127,127,127,.25); }
        .qa-md table { display: block; max-width: 100%; overflow-x: auto; border-collapse: collapse; font-size: .85rem; }
        .qa-md th { text-align: left; font-size: .75rem; font-weight: 600; opacity: .75; padding: 6px 8px; border-bottom: 1px solid rgba(127,127,127,.3); }
        .qa-md td { padding: 6px 8px; border-bottom: 1px solid rgba(127,127,127,.15); vertical-align: top; }
        .qa-files { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 8px; }
        .qa-file { display: flex; align-items: center; gap: 10px; border: 1px solid rgba(127,127,127,.25); border-radius: 10px; padding: 10px 12px; }
        .qa-file:hover { border-color: var(--primary-500); }
        .qa-file .name { font-weight: 600; font-size: .875rem; overflow-wrap: anywhere; }
        .qa-sticky { position: sticky; top: 64px; z-index: 5; padding: 8px 12px; border-radius: 10px; background: var(--gray-50); border: 1px solid rgba(127,127,127,.2); }
        .dark .qa-sticky { background: var(--gray-950); }
    </style>
    @php $files = $this->files(); @endphp

    <x-filament::section>
        <x-slot name="heading">ไฟล์ทดสอบ (กดเพื่อดาวน์โหลด)</x-slot>
        <x-slot name="description">ข้อมูลสมมติทั้งหมด การบ้านคณิตศาสตร์พื้นฐาน ม.4 ใช้ในข้อ 37, 40, 43, 50 และ 51</x-slot>
        <div class="qa-files">
            @foreach ($files as $f)
                <a class="qa-file" href="{{ $f['url'] }}" download>
                    <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-5 w-5" />
                    <span>
                        <span class="name">{{ $f['name'] }}</span><br>
                        <span class="qa-small qa-muted">PDF · {{ number_format($f['size'] / 1024) }} KB</span>
                    </span>
                </a>
            @endforeach
        </div>
    </x-filament::section>

    <div
        x-data="{
            key: @js($this->storageKey()),
            done: 0,
            total: 0,
            boxes() { return [...this.$refs.md.querySelectorAll('input[data-qa-check]')] },
            load() { try { return JSON.parse(localStorage.getItem(this.key) || '{}') } catch (e) { return {} } },
            save(state) { try { localStorage.setItem(this.key, JSON.stringify(state)) } catch (e) {} },
            refresh() {
                this.total = this.boxes().length;
                this.done = this.boxes().filter(b => b.checked).length;
                this.boxes().forEach(b => b.closest('li')?.classList.toggle('qa-done', b.checked));
            },
            init() {
                const state = this.load();
                this.boxes().forEach(b => { b.checked = !! state[b.dataset.qaCheck] });
                this.refresh();
            },
            toggle(e) {
                if (! e.target.matches('input[data-qa-check]')) return;
                const state = this.load();
                state[e.target.dataset.qaCheck] = e.target.checked;
                this.save(state);
                this.refresh();
            },
            clear() {
                if (! confirm('ล้างเครื่องหมายทั้งหมดในเบราว์เซอร์นี้?')) return;
                this.save({});
                this.boxes().forEach(b => { b.checked = false });
                this.refresh();
            },
        }"
        class="qa-grid"
    >
        <div class="qa-row qa-sticky">
            <span class="qa-small">ทำแล้ว <b x-text="done"></b> / <span x-text="total"></span> ข้อ</span>
            <div class="qa-bar" style="flex:1;max-width:320px;"><i class="qa-ok" :style="`width:${total ? done / total * 100 : 0}%`"></i></div>
            <span class="qa-small qa-muted">จำไว้ในเบราว์เซอร์นี้เท่านั้น บันทึกผลจริงที่ <a href="{{ MyResults::getUrl() }}">ผลของฉัน</a></span>
            <x-filament::button size="xs" color="gray" x-on:click="clear()">ล้างเครื่องหมาย</x-filament::button>
        </div>

        <article class="qa-md" x-ref="md" x-on:change="toggle($event)" wire:ignore>
            {!! $this->html() !!}
        </article>
    </div>
</x-filament-panels::page>
