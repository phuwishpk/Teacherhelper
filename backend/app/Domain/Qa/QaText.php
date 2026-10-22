<?php

namespace App\Domain\Qa;

use App\Filament\Qa\Pages\TestCaseDetail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Renders the use case text of a usability task (DESIGN §39.6): its
 * **labels** and `values` as inline Markdown with raw HTML stripped, and
 * every "UC-T13" turned into a link to that task in the Test Lab.
 */
final class QaText
{
    public static function render(string $text): HtmlString
    {
        $html = Str::inlineMarkdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = preg_replace_callback('/\bUC-[TSAX]\d+\b/', function (array $m) {
            return QaCatalog::find($m[0]) === null
                ? $m[0]
                : '<a class="qa-link" href="'.e(TestCaseDetail::getUrl(['code' => $m[0]])).'">'.$m[0].'</a>';
        }, $html) ?? $html;

        return new HtmlString($html);
    }
}
