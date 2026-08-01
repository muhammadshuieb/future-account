<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashBox;
use App\Models\CurrencyExchange;
use App\Models\JournalDetail;
use App\Models\User;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashExchangeTest extends TestCase
{
    use RefreshDatabase;

    protected CashBox $sypBox;

    protected CashBox $usdBox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);
        app(CurrencyService::class)->ensureSeeded();
        app(CurrencyService::class)->seedDemoRates();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $cash = Account::query()->where('code', '1101')->firstOrFail();
        $fxCash = Account::query()->where('code', '1105')->firstOrFail();

        $this->sypBox = CashBox::query()->create([
            'code' => 'SYP-1',
            'name' => 'صندوق ليرة',
            'account_id' => $fxCash->id,
            'opening_balance' => 2_000_000,
            'currency' => 'SYP',
            'is_active' => true,
        ]);
        $this->usdBox = CashBox::query()->create([
            'code' => 'USD-1',
            'name' => 'صندوق دولار',
            'account_id' => $cash->id,
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    public function test_posts_currency_exchange_to_distinct_gl_accounts(): void
    {
        $response = $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'source_currency' => 'SYP',
            'target_currency' => 'USD',
            'source_amount' => 1_500_000,
            'target_amount' => 100,
            'exchange_rate' => 15000,
            'status' => 'posted',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'posted');

        $entryId = CurrencyExchange::query()->firstOrFail()->journal_entry_id;
        $this->assertNotNull($entryId);

        $sourceAccId = (int) $this->sypBox->account_id;
        $targetAccId = (int) $this->usdBox->account_id;
        $this->assertNotSame($sourceAccId, $targetAccId);

        $creditOnSource = (float) JournalDetail::query()
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $sourceAccId)
            ->sum('credit');
        $debitOnTarget = (float) JournalDetail::query()
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $targetAccId)
            ->sum('debit');

        $this->assertGreaterThan(0, $creditOnSource);
        $this->assertGreaterThan(0, $debitOnTarget);

        // Must not dump both sides into a single shared cash account.
        $shared = JournalDetail::query()
            ->where('journal_entry_id', $entryId)
            ->select('account_id')
            ->groupBy('account_id')
            ->havingRaw('SUM(debit) > 0 AND SUM(credit) > 0')
            ->exists();
        $this->assertFalse($shared);
    }

    public function test_rejects_exchange_when_boxes_share_gl_account(): void
    {
        $shared = Account::query()->where('code', '1101')->firstOrFail();
        $this->sypBox->update(['account_id' => $shared->id]);
        $this->usdBox->update(['account_id' => $shared->id]);

        $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'source_currency' => 'SYP',
            'target_currency' => 'USD',
            'source_amount' => 150_000,
            'target_amount' => 10,
            'exchange_rate' => 15000,
            'status' => 'posted',
        ])->assertStatus(422)->assertJsonValidationErrors(['target_cash_box_id']);

        $this->postJson('/api/cash-boxes', [
            'code' => 'USD-SHARED',
            'name' => 'صندوق دولار مكرر',
            'account_id' => $shared->id,
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);
    }

    public function test_requires_account_id_when_creating_cash_box(): void
    {
        $this->postJson('/api/cash-boxes', [
            'code' => 'TRY-1',
            'name' => 'صندوق تركي',
            'opening_balance' => 0,
            'currency' => 'TRY',
            'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);

        $tryAcc = Account::query()->where('code', '1107')->first()
            ?? app(\App\Services\CashBoxGlService::class)->ensureCurrencyAccount('TRY');

        $this->postJson('/api/cash-boxes', [
            'code' => 'TRY-1',
            'name' => 'صندوق تركي',
            'account_id' => $tryAcc->id,
            'opening_balance' => 0,
            'currency' => 'TRY',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.account_id', $tryAcc->id)
            ->assertJsonPath('data.account.code', '1107');
    }

    public function test_posts_currency_exchange_and_adjusts_balances(): void
    {
        $response = $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'source_currency' => 'SYP',
            'target_currency' => 'USD',
            'source_amount' => 1_500_000,
            'target_amount' => 100,
            'exchange_rate' => 15000,
            'notes' => 'صراف السوق',
            'status' => 'posted',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.source_amount', '1500000.00')
            ->assertJsonPath('data.target_amount', '100.00');

        $exchange = CurrencyExchange::query()->firstOrFail();
        $this->assertNotNull($exchange->journal_entry_id);

        $entryId = $exchange->journal_entry_id;
        $debit = (float) JournalDetail::query()->where('journal_entry_id', $entryId)->sum('debit');
        $credit = (float) JournalDetail::query()->where('journal_entry_id', $entryId)->sum('credit');
        $this->assertEqualsWithDelta($debit, $credit, 0.01);

        $boxes = $this->getJson('/api/cash-boxes')->json('data');
        $syp = collect($boxes)->firstWhere('id', $this->sypBox->id);
        $usd = collect($boxes)->firstWhere('id', $this->usdBox->id);
        $this->assertEqualsWithDelta(500_000, (float) $syp['balance'], 0.01);
        $this->assertEqualsWithDelta(100, (float) $usd['balance'], 0.01);
    }

    public function test_exchange_uses_posted_source_box_balance_before_deducting(): void
    {
        // Dedicated GL for the funding box so transfer/exchange keep distinct accounts.
        $fundingAccount = Account::query()->create([
            'code' => '1110',
            'name' => 'صندوق تمويل ليرة',
            'name_en' => 'SYP Funding Cash',
            'parent_id' => Account::query()->where('code', '11')->value('id'),
            'type' => 'asset',
            'nature' => 'debit',
            'level' => 3,
            'is_group' => false,
            'is_active' => true,
        ]);
        $fundingBox = CashBox::query()->create([
            'code' => 'SYP-2',
            'name' => 'صندوق تمويل ليرة',
            'account_id' => $fundingAccount->id,
            'opening_balance' => 2_000_000,
            'currency' => 'SYP',
            'is_active' => true,
        ]);

        $this->postJson('/api/cash-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_type' => 'cash_box',
            'from_id' => $fundingBox->id,
            'to_type' => 'cash_box',
            'to_id' => $this->sypBox->id,
            'amount' => 750_000,
            'status' => 'posted',
        ])->assertCreated();

        $this->sypBox->update(['opening_balance' => 0]);

        $response = $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'source_currency' => 'SYP',
            'target_currency' => 'USD',
            'source_amount' => 750_000,
            'target_amount' => 50,
            'exchange_rate' => 15000,
            'status' => 'posted',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'posted');

        $boxes = $this->getJson('/api/cash-boxes')->json('data');
        $syp = collect($boxes)->firstWhere('id', $this->sypBox->id);
        $usd = collect($boxes)->firstWhere('id', $this->usdBox->id);
        $this->assertEqualsWithDelta(0, (float) $syp['balance'], 0.01);
        $this->assertEqualsWithDelta(50, (float) $usd['balance'], 0.01);
    }

    public function test_posts_fx_loss_when_received_below_official_rate(): void
    {
        $response = $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'source_currency' => 'SYP',
            'target_currency' => 'USD',
            'source_amount' => 1_500_000,
            'target_amount' => 90,
            'exchange_rate' => 16666.66666667,
            'status' => 'posted',
        ]);

        $response->assertCreated();
        $lossId = Account::query()->where('code', '5105')->value('id');
        $lossDebit = (float) JournalDetail::query()
            ->where('account_id', $lossId)
            ->sum('debit');
        $this->assertGreaterThan(0, $lossDebit);
    }

    public function test_rejects_insufficient_source_balance(): void
    {
        $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'source_currency' => 'SYP',
            'target_currency' => 'USD',
            'source_amount' => 5_000_000,
            'target_amount' => 300,
            'exchange_rate' => 15000,
            'status' => 'posted',
        ])->assertStatus(422);
    }

    public function test_rejects_same_box(): void
    {
        $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->sypBox->id,
            'source_currency' => 'SYP',
            'target_currency' => 'USD',
            'source_amount' => 1000,
            'target_amount' => 1,
            'exchange_rate' => 1000,
            'status' => 'posted',
        ])->assertStatus(422);
    }

    public function test_rejects_box_currency_mismatch(): void
    {
        $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'source_currency' => 'USD',
            'target_currency' => 'SYP',
            'source_amount' => 100,
            'target_amount' => 1_500_000,
            'exchange_rate' => 0.00006667,
            'status' => 'posted',
        ])->assertStatus(422);
    }

    public function test_accepts_from_to_currency_aliases(): void
    {
        $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'from_currency' => 'SYP',
            'to_currency' => 'USD',
            'source_amount' => 150_000,
            'target_amount' => 10,
            'exchange_rate' => 15000,
            'status' => 'posted',
        ])->assertCreated()
            ->assertJsonPath('data.source_currency', 'SYP')
            ->assertJsonPath('data.target_currency', 'USD');
    }

    public function test_rejects_missing_explicit_currencies(): void
    {
        $this->postJson('/api/currency-exchanges', [
            'exchange_date' => now()->toDateString(),
            'source_cash_box_id' => $this->sypBox->id,
            'target_cash_box_id' => $this->usdBox->id,
            'source_amount' => 1500,
            'target_amount' => 1,
            'exchange_rate' => 1500,
            'status' => 'posted',
        ])->assertStatus(422);
    }
}
