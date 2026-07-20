<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_rejects_unbalanced_journal_entry(): void
    {
        $cash = Account::query()->where('code', '1101')->firstOrFail();
        $capital = Account::query()->where('code', '3101')->firstOrFail();

        $response = $this->postJson('/api/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'غير متوازن',
            'details' => [
                ['account_id' => $cash->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $capital->id, 'debit' => 0, 'credit' => 50],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_posts_balanced_journal_entry(): void
    {
        $cash = Account::query()->where('code', '1101')->firstOrFail();
        $capital = Account::query()->where('code', '3101')->firstOrFail();

        $response = $this->postJson('/api/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'قيد افتتاحي',
            'status' => 'posted',
            'details' => [
                ['account_id' => $cash->id, 'debit' => 5000, 'credit' => 0],
                ['account_id' => $capital->id, 'debit' => 0, 'credit' => 5000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'posted');
    }
}
