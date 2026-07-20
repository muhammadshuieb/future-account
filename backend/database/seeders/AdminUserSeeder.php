<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Ensures the demo admin account always exists with a known password.
 * Safe to run on every boot (updateOrCreate + role assign).
 */
class AdminUserSeeder extends Seeder
{
    public const DEMO_EMAIL = 'admin@future-account.test';

    public const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => self::DEMO_EMAIL],
            [
                'name' => 'مدير النظام',
                // Plain text: User model casts password to hashed.
                'password' => self::DEMO_PASSWORD,
                'is_active' => true,
            ]
        );

        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        $settings = [
            ['key' => 'company_name', 'value' => 'شركة ساينا', 'group' => 'company', 'label' => 'اسم الشركة'],
            ['key' => 'company_name_en', 'value' => 'Syna Co', 'group' => 'company', 'label' => 'Company Name'],
            ['key' => 'currency', 'value' => 'SYP', 'group' => 'finance', 'label' => 'العملة الأساسية'],
            ['key' => 'multi_currency', 'value' => '1', 'group' => 'finance', 'type' => 'boolean', 'label' => 'تفعيل تعدد العملات'],
            ['key' => 'fiscal_year_start', 'value' => '01-01', 'group' => 'finance', 'label' => 'بداية السنة المالية'],
            ['key' => 'tax_rate', 'value' => '15', 'group' => 'finance', 'type' => 'number', 'label' => 'نسبة الضريبة %'],
            ['key' => 'locale', 'value' => 'ar', 'group' => 'general', 'label' => 'اللغة'],
        ];

        foreach ($settings as $setting) {
            Setting::setValue(
                $setting['key'],
                $setting['value'],
                $setting['group'],
                $setting['type'] ?? 'string',
                $setting['label'] ?? null
            );
        }
    }
}
