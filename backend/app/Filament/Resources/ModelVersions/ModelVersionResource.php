<?php

namespace App\Filament\Resources\ModelVersions;

use App\Filament\Resources\ModelVersions\Pages\ManageModelVersions;
use App\Models\ModelVersion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * DESIGN §7.5 / §8.6: upload an exported .tflite with its metrics.json and
 * activate the version the app downloads (§9.8). The file goes to the
 * private disk at models/{name}/{version}.tflite (§7.3); sha256 is computed
 * from the stored file so the app can verify what it downloads.
 */
class ModelVersionResource extends Resource
{
    protected static ?string $model = ModelVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $modelLabel = 'โมเดล';

    protected static ?string $pluralModelLabel = 'โมเดลบนมือถือ';

    protected static ?string $navigationLabel = 'โมเดลบนมือถือ';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'version';

    public const DISK = 'local';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ชื่อโมเดล (name)')
                    ->default(ModelVersion::DIGIT_CRNN)
                    ->required()
                    ->maxLength(40)
                    ->rule('regex:/^[a-z0-9_]+$/')
                    ->validationMessages(['regex' => 'ใช้ได้เฉพาะ a–z, 0–9 และ _'])
                    ->disabledOn('edit'),
                TextInput::make('version')
                    ->label('เวอร์ชัน (version)')
                    ->placeholder('0.1.0')
                    ->required()
                    ->maxLength(20)
                    ->rule('regex:/^[A-Za-z0-9._-]+$/')
                    ->validationMessages(['regex' => 'ใช้ได้เฉพาะตัวอักษร ตัวเลข . _ -'])
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('name', $get('name')))
                    ->disabledOn('edit'),
                FileUpload::make('file_path')
                    ->label('ไฟล์ .tflite')
                    ->helperText('float16 ไม่เกิน 2 MB ตาม DESIGN §12.2 (ฟอร์มรับได้ถึง 8 MB ถ้า upload_max_filesize ของ PHP อนุญาต ไม่งั้นใช้ artisan eduvision:register-model)')
                    ->disk(self::DISK)
                    ->directory(fn (Get $get) => 'models/'.($get('name') ?: ModelVersion::DIGIT_CRNN))
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file, Get $get) => ($get('version') ?: 'upload').'.tflite')
                    ->visibility('private')
                    ->maxSize(8192)
                    ->required()
                    ->hiddenOn('edit'),
                Textarea::make('metrics')
                    ->label('metrics.json')
                    ->helperText('วางเนื้อหา metrics.json ที่ export มาพร้อมโมเดล (มี decode contract ให้แอปอ่าน)')
                    ->rows(8)
                    ->required()
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        $decoded = is_string($value) ? json_decode($value, true) : $value;
                        if (! is_array($decoded)) {
                            $fail('metrics ต้องเป็น JSON object');
                        }
                    })
                    ->formatStateUsing(fn (mixed $state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $state)
                    ->dehydrateStateUsing(fn (mixed $state) => is_string($state) ? json_decode($state, true) : $state),
                Toggle::make('is_active')
                    ->label('เปิดใช้งานทันที (is_active)')
                    ->helperText('เวอร์ชันอื่นของโมเดลชื่อเดียวกันจะถูกปิด')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('โมเดล')->badge()->sortable(),
                TextColumn::make('version')->label('เวอร์ชัน')->sortable()->fontFamily('mono'),
                IconColumn::make('is_active')->label('ใช้งาน')->boolean(),
                TextColumn::make('sha256')
                    ->label('sha256')
                    ->formatStateUsing(fn (string $state) => substr($state, 0, 12).'…')
                    ->copyable()
                    ->copyMessage('คัดลอก sha256 แล้ว')
                    ->fontFamily('mono'),
                TextColumn::make('file_path')
                    ->label('ขนาด')
                    ->formatStateUsing(fn (string $state) => self::size($state)),
                TextColumn::make('metrics.metrics.cer')
                    ->label('CER')
                    ->formatStateUsing(fn (mixed $state) => is_numeric($state) ? number_format((float) $state * 100, 1).' %' : '—')
                    ->placeholder('—'),
                TextColumn::make('metrics.metrics.exact_match')
                    ->label('exact')
                    ->formatStateUsing(fn (mixed $state) => is_numeric($state) ? number_format((float) $state * 100, 1).' %' : '—')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('ลงทะเบียนเมื่อ')
                    ->dateTime('d/m/Y H:i', 'Asia/Bangkok')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('name')
                    ->label('โมเดล')
                    ->options(fn () => ModelVersion::query()->distinct()->orderBy('name')->pluck('name', 'name')->all()),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label('เปิดใช้งาน')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('เปิดใช้งานเวอร์ชันนี้')
                    ->modalDescription(fn (ModelVersion $record) => "แอปจะดาวน์โหลด {$record->name} {$record->version} ในครั้งถัดไป เวอร์ชันอื่นของ {$record->name} จะถูกปิด")
                    ->visible(fn (ModelVersion $record) => ! $record->is_active)
                    ->authorize('update')
                    ->action(function (ModelVersion $record) {
                        $record->activate();
                        Notification::make()->title("เปิดใช้งาน {$record->name} {$record->version} แล้ว")->success()->send();
                    }),
                DeleteAction::make()
                    ->label('ลบ')
                    ->modalDescription('ลบทั้งแถวและไฟล์ .tflite บนเซิร์ฟเวอร์ แอปที่โหลดไว้แล้วยังใช้ไฟล์เดิมต่อได้')
                    ->before(function (ModelVersion $record) {
                        Storage::disk(self::DISK)->delete($record->file_path);
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function size(string $path): string
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($path)) {
            return 'ไฟล์หาย';
        }
        $bytes = $disk->size($path);

        return $bytes >= 1024 * 1024 ? number_format($bytes / 1024 / 1024, 2).' MB' : number_format($bytes / 1024).' KB';
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageModelVersions::route('/'),
        ];
    }
}
