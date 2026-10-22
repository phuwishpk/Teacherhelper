<?php

namespace App\Filament\Resources\ModelVersions\Pages;

use App\Filament\Resources\ModelVersions\ModelVersionResource;
use App\Models\ModelVersion;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Storage;

class ManageModelVersions extends ManageRecords
{
    protected static string $resource = ModelVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('อัปโหลดโมเดล')
                ->modalHeading('อัปโหลดเวอร์ชันใหม่')
                ->mutateDataUsing(function (array $data): array {
                    $path = is_array($data['file_path'] ?? null) ? (string) reset($data['file_path']) : (string) ($data['file_path'] ?? '');
                    $disk = Storage::disk(ModelVersionResource::DISK);
                    $data['file_path'] = $path;
                    $data['sha256'] = $path !== '' && $disk->exists($path) ? (string) hash('sha256', (string) $disk->get($path)) : str_repeat('0', 64);
                    if (is_string($data['metrics'] ?? null)) {
                        $data['metrics'] = json_decode($data['metrics'], true) ?: [];
                    }
                    $activate = (bool) ($data['is_active'] ?? false);
                    $data['is_active'] = false;
                    $data['_activate'] = $activate;

                    return $data;
                })
                ->using(function (array $data): ModelVersion {
                    $activate = (bool) ($data['_activate'] ?? false);
                    unset($data['_activate']);
                    $model = ModelVersion::create($data);
                    if ($activate) {
                        $model->activate();
                    }

                    return $model;
                }),
        ];
    }
}
