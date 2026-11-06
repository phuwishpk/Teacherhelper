<?php

namespace App\Domain\ClassPosts;

use App\Exceptions\ApiException;
use App\Models\ClassPostAttachment;
use App\Models\ClassPostFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The `attachments` input of class posts and assignments (DESIGN §30.2,
 * §30.12): each item is a file the teacher uploaded (`file_id`) or a link
 * (`url`, `title?`), kept in the order sent.
 */
final class AttachmentInput
{
    /**
     * @param  array<int, mixed>  $input
     * @return list<array{kind: string, file: ClassPostFile|null, url: string|null, title: string}>
     *
     * @throws ApiException 422 errors.<field>.<n>
     */
    public static function parse(User $teacher, array $input, string $field = 'attachments'): array
    {
        $rows = [];
        $seen = [];
        // The validator returns the items grouped by rule, not in the order sent.
        ksort($input);
        foreach (array_values($input) as $i => $item) {
            $item = is_array($item) ? $item : [];
            $hasFile = isset($item['file_id']);
            $url = isset($item['url']) ? trim((string) $item['url']) : '';
            if ($hasFile === ($url !== '')) {
                throw self::invalid("{$field}.{$i}", 'ไฟล์แนบแต่ละรายการต้องเป็นไฟล์หรือลิงก์อย่างใดอย่างหนึ่ง');
            }
            if ($hasFile) {
                $file = ClassPostFile::query()->where('owner_id', $teacher->id)->find((int) $item['file_id']);
                if ($file === null || isset($seen[$file->id])) {
                    throw self::invalid("{$field}.{$i}.file_id", 'ไม่พบไฟล์ที่แนบ หรือแนบไฟล์เดียวกันซ้ำ');
                }
                $seen[$file->id] = true;
                $rows[] = ['kind' => ClassPostAttachment::KIND_FILE, 'file' => $file, 'url' => null, 'title' => self::text($item['title'] ?? null) ?? (string) $file->original_name];

                continue;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw self::invalid("{$field}.{$i}.url", 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
            }
            $rows[] = ['kind' => ClassPostAttachment::KIND_LINK, 'file' => null, 'url' => $url, 'title' => self::text($item['title'] ?? null) ?? Str::limit($url, 197)];
        }

        return $rows;
    }

    /**
     * Creates the rows through the owner's attachments relation.
     *
     * @param  HasMany<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model>  $relation
     * @param  list<array{kind: string, file: ClassPostFile|null, url: string|null, title: string}>  $rows
     */
    public static function write(HasMany $relation, array $rows): void
    {
        foreach ($rows as $position => $row) {
            $relation->create([
                'kind' => $row['kind'],
                'class_post_file_id' => $row['file']?->id,
                'url' => $row['url'],
                'title' => $row['title'],
                'position' => $position,
            ]);
        }
    }

    /**
     * What identifies the list for "did the files change" (titles do not count:
     * Classroom shows a link by its url and a file by its Drive name).
     *
     * @param  iterable<object{kind: string, class_post_file_id: int|null, url: string|null}>  $attachments
     * @return list<string>
     */
    public static function signature(iterable $attachments): array
    {
        $out = [];
        foreach ($attachments as $a) {
            $out[] = $a->kind.':'.($a->class_post_file_id ?? $a->url);
        }

        return $out;
    }

    /**
     * @param  list<array{kind: string, file: ClassPostFile|null, url: string|null, title: string}>  $rows
     * @return list<string>
     */
    public static function rowsSignature(array $rows): array
    {
        return array_map(fn (array $a) => $a['kind'].':'.($a['file']?->id ?? $a['url']), $rows);
    }

    /** The file ids of a list of attachment rows (for deleteUnused after they are dropped). */
    public static function fileIds(iterable $attachments): array
    {
        $ids = [];
        foreach ($attachments as $a) {
            if ($a->class_post_file_id !== null) {
                $ids[] = (int) $a->class_post_file_id;
            }
        }

        return $ids;
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', trim((string) ($value ?? ''))));

        return $text === '' ? null : $text;
    }

    private static function invalid(string $field, string $message): ApiException
    {
        return new ApiException($message, 'validation_failed', 422, [$field => [$message]]);
    }
}
