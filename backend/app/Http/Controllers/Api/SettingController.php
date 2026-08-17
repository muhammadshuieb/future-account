<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends ApiController
{
    public function bootstrap(): JsonResponse
    {
        return response()->json([
            'data' => [
                'default_locale' => Setting::defaultLocale(),
                'tax_enabled' => Setting::taxEnabled(),
                'tax_rate' => Setting::defaultTaxRate(),
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        $rows = Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->reject(fn (Setting $s) => Setting::isHiddenFromSettingsApi($s->key))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['nullable'],
        ]);

        foreach ($data['settings'] as $item) {
            $key = $item['key'];
            $value = $item['value'] ?? '';

            if (Setting::isHiddenFromSettingsApi($key) || $key === 'backup_last_cleanup') {
                continue;
            }

            if ($key === 'tax_enabled') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) || $value === '1' || $value === 1 || $value === true
                    ? '1'
                    : '0';
            }

            if ($key === 'default_locale') {
                if (! in_array($value, ['ar', 'en', 'tr'], true)) {
                    continue;
                }
            }

            if (in_array($key, ['backup_time_1', 'backup_time_2'], true)) {
                $value = Setting::normalizeTime((string) $value, $key === 'backup_time_1' ? '02:00' : '14:00');
            }

            if ($key === 'backup_retention_days') {
                $n = (int) $value;
                $value = (string) max(1, min(365, $n > 0 ? $n : 7));
            }

            if ($key === 'backup_min_keep') {
                $n = (int) $value;
                $value = (string) max(1, min(50, $n > 0 ? $n : 3));
            }

            if ($key === 'backup_last_cleanup') {
                continue;
            }

            $existing = Setting::query()->where('key', $key)->first();
            Setting::setValue(
                $key,
                $value,
                $existing?->group ?? $this->defaultGroup($key),
                $existing?->type ?? $this->defaultType($key),
                $existing?->label ?? $this->defaultLabel($key),
            );

            if ($key === 'default_locale') {
                Setting::setValue('locale', $value, 'general', 'string', 'اللغة');
            }
        }

        $rows = Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->reject(fn (Setting $s) => Setting::isHiddenFromSettingsApi($s->key))
            ->values();

        return response()->json([
            'data' => $rows,
            'message' => 'تم حفظ الإعدادات.',
        ]);
    }

    protected function defaultGroup(string $key): string
    {
        return match ($key) {
            'tax_enabled', 'tax_rate', 'currency', 'multi_currency', 'fiscal_year_start' => 'finance',
            'backup_time_1', 'backup_time_2', 'backup_retention_days', 'backup_min_keep', 'backup_last_cleanup' => 'backup',
            'company_name', 'company_name_en' => 'company',
            default => 'general',
        };
    }

    protected function defaultType(string $key): string
    {
        return match ($key) {
            'tax_enabled', 'multi_currency' => 'boolean',
            'tax_rate', 'backup_retention_days', 'backup_min_keep' => 'number',
            'backup_time_1', 'backup_time_2' => 'time',
            'backup_last_cleanup' => 'json',
            default => 'string',
        };
    }

    protected function defaultLabel(string $key): ?string
    {
        return match ($key) {
            'tax_enabled' => 'تفعيل الضريبة',
            'tax_rate' => 'نسبة الضريبة %',
            'default_locale' => 'اللغة الافتراضية',
            'backup_time_1' => 'وقت النسخة الأولى',
            'backup_time_2' => 'وقت النسخة الثانية',
            'backup_retention_days' => 'مدة الاحتفاظ بالنسخ (أيام)',
            'backup_min_keep' => 'الحد الأدنى لعدد النسخ المحتفظ بها',
            'backup_last_cleanup' => 'آخر نتيجة لتنظيف النسخ',
            default => null,
        };
    }
}
