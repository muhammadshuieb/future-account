<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Account::query()->where('code', '3103')->exists()) {
            return;
        }

        $parent = Account::query()->where('code', '3')->first();

        Account::query()->create([
            'code' => '3103',
            'name' => 'مسحوبات الشركاء',
            'name_en' => 'Partner Drawings',
            'parent_id' => $parent?->id,
            'type' => 'equity',
            'nature' => 'debit',
            'level' => $parent ? ((int) $parent->level + 1) : 2,
            'is_group' => false,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        // Keep the account — may already have journal activity.
    }
};
