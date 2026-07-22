<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
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
        return response()->json([
            'data' => Setting::query()->orderBy('group')->orderBy('key')->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['nullable'],
        ]);

        foreach ($data['settings'] as $item) {
            $key = $item['key'];
            $value = $item['value'] ?? '';

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

        return response()->json([
            'data' => Setting::query()->orderBy('group')->orderBy('key')->get(),
            'message' => 'تم حفظ الإعدادات.',
        ]);
    }

    protected function defaultGroup(string $key): string
    {
        return match ($key) {
            'tax_enabled', 'tax_rate', 'currency', 'multi_currency', 'fiscal_year_start' => 'finance',
            'backup_time_1', 'backup_time_2' => 'backup',
            'company_name', 'company_name_en' => 'company',
            default => 'general',
        };
    }

    protected function defaultType(string $key): string
    {
        return match ($key) {
            'tax_enabled', 'multi_currency' => 'boolean',
            'tax_rate' => 'number',
            'backup_time_1', 'backup_time_2' => 'time',
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
            default => null,
        };
    }
}
