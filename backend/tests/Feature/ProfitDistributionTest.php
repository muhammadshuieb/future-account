<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashBox;
use App\Models\EquityPartner;
use App\Models\JournalDetail;
use App\Models\ProfitDistribution;
use App\Models\User;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfitDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected CashBox $cashBox;

    protected EquityPartner $partnerA;

    protected EquityPartner $partnerB;

    protected Account $drawings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);
        app(CurrencyService::class)->ensureSeeded();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $cash = Account::query()->where('code', '1101')->firstOrFail();
        $this->drawings = Account::query()->where('code', '3103')->firstOrFail();

        $this->cashBox = CashBox::query()->create([
            'code' => 'CASH-USD',
            'name' => 'صندوق دولار',
            'account_id' => $cash->id,
            'opening_balance' => 10_000,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->partnerA = EquityPartner::query()->create([
            'code' => 'P1',
            'name' => 'شريك أ',
            'share_percent' => 60,
            'is_active' => true,
        ]);
        $this->partnerB = EquityPartner::query()->create([
            'code' => 'P2',
            'name' => 'شريك ب',
            'share_percent' => 40,
            'is_active' => true,
        ]);
    }

    public function test_creates_and_posts_auto_split_profit_distribution(): void
    {
        $response = $this->postJson('/api/profit-distributions', [
            'distribution_date' => now()->toDateString(),
            'cash_box_id' => $this->cashBox->id,
            'total_amount' => 1000,
            'currency' => 'USD',
            'auto_split' => true,
            'status' => 'posted',
            'notes' => 'توزيع أرباح تجريبي',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.total_amount', '1000.00');

        $distribution = ProfitDistribution::query()->with('lines')->firstOrFail();
        $this->assertCount(2, $distribution->lines);
        $this->assertEquals(600.0, (float) $distribution->lines->firstWhere('equity_partner_id', $this->partnerA->id)->amount);
        $this->assertEquals(400.0, (float) $distribution->lines->firstWhere('equity_partner_id', $this->partnerB->id)->amount);
        $this->assertNotNull($distribution->journal_entry_id);

        $entryId = (int) $distribution->journal_entry_id;
        $debitDrawings = (float) JournalDetail::query()
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $this->drawings->id)
            ->sum('debit');
        $creditCash = (float) JournalDetail::query()
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $this->cashBox->account_id)
            ->sum('credit');

        $this->assertEquals(1000.0, $debitDrawings);
        $this->assertEquals(1000.0, $creditCash);

        $boxes = $this->getJson('/api/cash-boxes')->json('data');
        $box = collect($boxes)->firstWhere('id', $this->cashBox->id);
        $this->assertEquals(9000.0, (float) ($box['balance'] ?? 0));
    }

    public function test_posts_manual_lines_with_partner_account(): void
    {
        $capital = Account::query()->where('code', '3101')->firstOrFail();
        $this->partnerA->update(['account_id' => $capital->id]);

        $response = $this->postJson('/api/profit-distributions', [
            'distribution_date' => now()->toDateString(),
            'cash_box_id' => $this->cashBox->id,
            'currency' => 'USD',
            'status' => 'posted',
            'lines' => [
                ['equity_partner_id' => $this->partnerA->id, 'amount' => 250],
                ['equity_partner_id' => $this->partnerB->id, 'amount' => 150],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'posted');

        $entryId = (int) ProfitDistribution::query()->value('journal_entry_id');
        $debitCapital = (float) JournalDetail::query()
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $capital->id)
            ->sum('debit');
        $debitDrawings = (float) JournalDetail::query()
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $this->drawings->id)
            ->sum('debit');
        $creditCash = (float) JournalDetail::query()
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $this->cashBox->account_id)
            ->sum('credit');

        $this->assertEquals(250.0, $debitCapital);
        $this->assertEquals(150.0, $debitDrawings);
        $this->assertEquals(400.0, $creditCash);
    }

    public function test_equity_partner_crud(): void
    {
        $create = $this->postJson('/api/equity-partners', [
            'code' => 'P3',
            'name' => 'شريك ج',
            'share_percent' => 10,
            'is_active' => true,
        ]);
        $create->assertCreated()->assertJsonPath('data.code', 'P3');

        $id = (int) $create->json('data.id');
        $this->putJson("/api/equity-partners/{$id}", [
            'code' => 'P3',
            'name' => 'شريك ج محدث',
            'share_percent' => 15,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.name', 'شريك ج محدث');

        $this->getJson('/api/equity-partners?q=محدث')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
