<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Cached access to business settings, falling back to config('salon.settings') defaults. */
class SettingsService
{
    private const CACHE_KEY = 'salon.settings';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = Cache::rememberForever(self::CACHE_KEY, function () {
            $values = [];
            foreach (Setting::all() as $setting) {
                $values[$setting->key] = $setting->typedValue();
            }

            return $values;
        });

        // Defaults are merged on every read (not cached), so settings added in a later release
        // work immediately instead of waiting for the cache to be cleared.
        return $this->loaded = $stored + $this->defaults();
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        $values = [];
        foreach (config('salon.settings') as $key => $default) {
            $values[$key] = (new Setting($default))->typedValue();
        }

        return $values;
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): void
    {
        $defaults = config('salon.settings');

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                continue;
            }
            $type = $defaults[$key]['type'];
            $stored = match ($type) {
                'boolean' => $value ? '1' : '0',
                'json' => is_string($value) ? $value : json_encode($value),
                default => $value === null ? '' : (string) $value,
            };
            Setting::updateOrCreate(['key' => $key], ['value' => $stored, 'type' => $type]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    public function logoUrl(): ?string
    {
        $path = $this->get('salon_logo');

        return $path ? Storage::disk(FileUploadService::publicDisk())->url($path) : asset('images/dgs-logo-256.png');
    }

    /** @return list<int> minutes before start at which reminders go out */
    public function reminderOffsets(): array
    {
        $offsets = $this->get('reminder_offsets_minutes', [1440, 120]);

        return collect(is_array($offsets) ? $offsets : [])
            ->map(fn ($m) => (int) $m)->filter(fn ($m) => $m > 0)->unique()->sortDesc()->values()->all();
    }
}
