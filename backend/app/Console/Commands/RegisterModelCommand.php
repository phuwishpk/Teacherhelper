<?php

namespace App\Console\Commands;

use App\Models\ModelVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * php artisan eduvision:register-model {dir} [--no-activate]
 *
 * Registers an exported on-device model (DESIGN §8.6, §9.8, §12): {dir}
 * holds metrics.json (name, version, sha256, file) and the .tflite it
 * names, e.g. ml/models/digit_crnn/0.1.0. The file is verified against the
 * sha256 of metrics.json, copied to the private disk
 * (models/{name}/{version}.tflite, §7.3) and upserted into model_versions
 * with metrics = the whole metrics.json. The version is activated unless
 * --no-activate is given, so a fresh install has a model the app can fetch.
 */
class RegisterModelCommand extends Command
{
    protected $signature = 'eduvision:register-model
                            {dir : Directory with metrics.json and the model file}
                            {--no-activate : Register without making it the active version}';

    protected $description = 'Register an exported .tflite model (metrics.json + file) in model_versions';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/');
        $metricsPath = $dir.'/metrics.json';
        if (! is_file($metricsPath)) {
            $this->error("No metrics.json in {$dir}");

            return self::INVALID;
        }

        try {
            $metrics = json_decode((string) file_get_contents($metricsPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("metrics.json is not valid JSON: {$e->getMessage()}");

            return self::INVALID;
        }
        if (! is_array($metrics)) {
            $this->error('metrics.json must be a JSON object');

            return self::INVALID;
        }

        $name = (string) ($metrics['name'] ?? '');
        $version = (string) ($metrics['version'] ?? '');
        if (preg_match('/^[a-z0-9_]{1,40}$/', $name) !== 1 || preg_match('/^[A-Za-z0-9._-]{1,20}$/', $version) !== 1) {
            $this->error('metrics.json needs "name" (a-z, 0-9, _) and "version" (letters, digits, . _ -)');

            return self::INVALID;
        }

        $file = $dir.'/'.basename((string) ($metrics['file'] ?? 'model.tflite'));
        if (! is_file($file)) {
            $this->error("Model file not found: {$file}");

            return self::INVALID;
        }
        $sha256 = hash_file('sha256', $file);
        $expected = strtolower((string) ($metrics['sha256'] ?? ''));
        if ($expected !== '' && $expected !== $sha256) {
            $this->error("sha256 mismatch: metrics.json says {$expected}, the file is {$sha256}");

            return self::FAILURE;
        }

        $path = ModelVersion::pathFor($name, $version);
        $disk = Storage::disk('local');
        $stream = fopen($file, 'rb');
        if ($stream === false || ! $disk->put($path, $stream)) {
            $this->error("Could not copy the model to the private disk at {$path}");

            return self::FAILURE;
        }
        fclose($stream);

        $model = ModelVersion::query()->updateOrCreate(
            ['name' => $name, 'version' => $version],
            ['file_path' => $path, 'sha256' => $sha256, 'metrics' => $metrics],
        );
        if (! $this->option('no-activate')) {
            $model->activate();
        }

        $this->info(sprintf(
            'Registered %s %s (%s, %d bytes) as model_versions #%d%s',
            $name,
            $version,
            substr($sha256, 0, 12).'…',
            filesize($file),
            $model->id,
            $model->is_active ? ', active' : '',
        ));

        return self::SUCCESS;
    }
}
