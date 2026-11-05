{{-- The SUS questionnaire itself (DESIGN §39.9), shared by /sus and /usability. --}}
@use('App\Domain\Qa\QaSus')
            <section class="card">
                <fieldset>
                    <legend>คุณใช้ Krucheck บนอุปกรณ์ใด</legend>
                    <div class="chips">
                        @foreach (QaSus::PLATFORMS as $value => $label)
                            <label class="choice"><input type="radio" name="platform" value="{{ $value }}" @checked(old('platform') === $value) required><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                    @error('platform')<p class="err">{{ $message }}</p>@enderror
                </fieldset>
                <fieldset style="margin-top:14px;">
                    <legend>ประสบการณ์กับ Krucheck</legend>
                    <div class="chips">
                        @foreach (QaSus::EXPERIENCE as $value => $label)
                            <label class="choice"><input type="radio" name="experience" value="{{ $value }}" @checked(old('experience') === $value) required><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                    @error('experience')<p class="err">{{ $message }}</p>@enderror
                </fieldset>
            </section>

            @foreach (QaSus::ITEMS as $i => $item)
                @php $name = 'q'.($i + 1); @endphp
                <section class="card q" id="{{ $name }}">
                    <fieldset>
                        <legend><span class="num">{{ $i + 1 }}</span>{{ $item['th'] }}</legend>
                        <p class="en" lang="en">{{ $item['en'] }}</p>
                        <div class="likert">
                            @foreach ([1, 2, 3, 4, 5] as $n)
                                <label class="choice"><input type="radio" name="{{ $name }}" value="{{ $n }}" @checked((string) old($name) === (string) $n) required aria-label="ข้อ {{ $i + 1 }} ระดับ {{ $n }}"><span>{{ $n }}</span></label>
                            @endforeach
                        </div>
                        <div class="ends"><span>ไม่เห็นด้วยอย่างยิ่ง</span><span>เห็นด้วยอย่างยิ่ง</span></div>
                        @error($name)<p class="err">{{ $message }}</p>@enderror
                    </fieldset>
                </section>
            @endforeach

            <section class="card q" id="q11">
                <fieldset>
                    <legend><span class="num">11</span>โดยรวมแล้ว ฉันให้ระดับความเป็นมิตรต่อผู้ใช้ของระบบนี้ว่า</legend>
                    <p class="en" lang="en">Overall, I would rate the user-friendliness of this product as:</p>
                    <div class="stack">
                        @foreach (QaSus::ADJECTIVES as $n => $adj)
                            <label class="choice"><input type="radio" name="adjective" value="{{ $n }}" @checked((string) old('adjective') === (string) $n) required><span>{{ $adj['th'] }}<em lang="en">{{ $adj['en'] }}</em></span></label>
                        @endforeach
                    </div>
                    @error('adjective')<p class="err">{{ $message }}</p>@enderror
                </fieldset>
            </section>

            <section class="card">
                <label class="field">ความเห็นเพิ่มเติม (ไม่บังคับ)
                    <textarea name="comment" maxlength="2000" placeholder="ชอบอะไร ติดตรงไหน">{{ old('comment') }}</textarea>
                </label>
            </section>
