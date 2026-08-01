<?php

use App\Models\Account;
use App\Models\CashBox;
use App\Services\CashBoxGlService;
use Illuminate\Database\Migrations\Migration;

/**
 * Ensure each currency cash box has an independent GL account for FX posting.
 * Does not wipe business data — only creates/updates COA leaves and links boxes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs seed COA after migrations; production already has account 11.
        if (! Account::query()->where('code', '11')->exists()) {
            return;
        }

        $gl = app(CashBoxGlService::class);
        $gl->ensureStandardCurrencyAccounts();
        $gl->backfillAll(dryRun: false);

        // Prefer canonical currency mapping when demo boxes still share / mis-link.
        $this->preferLink('CASH-USD', 'USD', '1101');
        $this->preferLink('CASH-01', 'SYP', '1105');
        $this->preferLink('CASH-SYP', 'SYP', '1105');
        $this->preferLink('CASH-TRY', 'TRY', '1107');
        $this->preferLink('CASH-CNY', 'CNY', '1108');
    }

    public function down(): void
    {
        // Keep accounts and links — reversing would break posted FX journals.
    }

    protected function preferLink(string $boxCode, string $currency, string $accountCode): void
    {
        $box = CashBox::query()->where('code', $boxCode)->first();
        if (! $box) {
            return;
        }

        $account = Account::query()->where('code', $accountCode)->first();
        if (! $account) {
            return;
        }

        $currency = strtoupper($currency);
        $boxCurrency = strtoupper((string) ($box->currency ?: ''));
        if ($boxCurrency && $boxCurrency !== $currency) {
            return;
        }

        $taken = CashBox::query()
            ->where('account_id', $account->id)
            ->where('id', '!=', $box->id)
            ->exists();

        if ($taken) {
            return;
        }

        if ((int) $box->account_id !== (int) $account->id) {
            $box->update(['account_id' => $account->id]);
        }
    }
};
