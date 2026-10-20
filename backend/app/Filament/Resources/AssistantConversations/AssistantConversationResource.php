<?php

namespace App\Filament\Resources\AssistantConversations;

use App\Filament\Resources\AssistantConversations\Pages\ManageAssistantConversations;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DESIGN §37: the admin reads teachers' conversations with "ผู้ช่วย
 * Krucheck" to help when something goes wrong; each failed answer shows its
 * code and the ai_calls number ("การเรียก AI").
 */
class AssistantConversationResource extends Resource
{
    protected static ?string $model = AssistantConversation::class;

    protected static ?string $slug = 'assistant-conversations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'บทสนทนากับผู้ช่วย';

    protected static ?string $pluralModelLabel = 'บทสนทนากับผู้ช่วย';

    protected static ?string $navigationLabel = 'บทสนทนากับผู้ช่วย';

    protected static ?int $navigationSort = 51;

    public const ROLE_LABELS = [
        AssistantMessage::ROLE_USER => 'ครู',
        AssistantMessage::ROLE_ASSISTANT => 'ผู้ช่วย',
        AssistantMessage::ROLE_ERROR => 'ไม่สำเร็จ',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('updated_at')->label('ล่าสุด')->dateTime('d/m/Y H:i', 'Asia/Bangkok')->sortable(),
                TextColumn::make('user.name')->label('ครู')->searchable(),
                TextColumn::make('title')->label('เรื่อง')->searchable()->limit(60)->wrap(),
                TextColumn::make('messages_count')->label('ข้อความ')->counts('messages')->numeric(),
                TextColumn::make('input_tokens')->label('token เข้า')->numeric()->toggleable(),
                TextColumn::make('output_tokens')->label('token ออก')->numeric()->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordAction('view')
            ->recordActions([
                ViewAction::make()
                    ->label('อ่าน')
                    ->modalHeading(fn (AssistantConversation $record) => $record->title)
                    ->modalWidth('4xl')
                    ->schema(fn (Schema $schema) => $schema->components([
                        RepeatableEntry::make('messages')
                            ->hiddenLabel()
                            ->columns(5)
                            ->schema([
                                TextEntry::make('role')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->formatStateUsing(fn (string $state) => self::ROLE_LABELS[$state] ?? $state)
                                    ->color(fn (string $state) => match ($state) {
                                        AssistantMessage::ROLE_ERROR => 'danger',
                                        AssistantMessage::ROLE_ASSISTANT => 'info',
                                        default => 'gray',
                                    }),
                                TextEntry::make('content')
                                    ->hiddenLabel()
                                    ->columnSpan(3)
                                    ->helperText(fn (AssistantMessage $record) => self::note($record)),
                                TextEntry::make('created_at')->hiddenLabel()->dateTime('d/m H:i', 'Asia/Bangkok'),
                            ]),
                    ])),
            ])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user:id,name');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAssistantConversations::route('/'),
        ];
    }

    /** Under a message: its error code, or its plan card (DESIGN §37.6). */
    private static function note(AssistantMessage $m): ?string
    {
        if ($m->error_code !== null) {
            return "รหัส {$m->error_code}".($m->ai_call_id === null ? '' : " · #{$m->ai_call_id}");
        }
        if (! is_array($m->plan)) {
            return null;
        }
        $what = match ($m->plan['kind'] ?? 'questions') {
            'rubric' => 'การ์ด rubric ข้อ '.($m->plan['question']['position'] ?? '?'),
            'indicators' => 'การ์ดตัวชี้วัด '.count($m->plan['items'] ?? []).' รายการ',
            default => 'การ์ด '.count($m->plan['questions'] ?? []).' ข้อ',
        };
        $target = $m->plan['target']['title'] ?? 'ยังไม่เลือกงาน';
        $result = $m->plan['result'] ?? [];

        return match ($m->plan_status) {
            AssistantMessage::PLAN_APPLIED => isset($result['title'], $result['created']) && ! isset($result['question_id'])
                ? "{$what} · สร้างแล้ว {$result['created']} ข้อใน «{$result['title']}»"
                : "{$what} · ใช้แล้ว",
            AssistantMessage::PLAN_REPLACED => "{$what} · มีการ์ดใหม่แทน",
            default => "{$what} · «{$target}» · ยังไม่ได้ใช้",
        };
    }
}
