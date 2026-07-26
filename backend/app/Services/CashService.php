<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bank;
use App\Models\BankReconciliation;
use App\Models\CashBox;
use App\Models\CashTransfer;
use App\Models\CurrencyExchange;
use App\Models\JournalDetail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashService
{
    public function __construct(
        protected JournalEntryService $journals,
        protected AuditLogger $audit,
        protected CurrencyService $currencies,
    ) {}

    public function createTransfer(array $data, User $user): CashTransfer
    {
        return DB::transaction(function () use ($data, $user) {
            $transfer = CashTransfer::query()->create([
                'transfer_number' => $this->nextNumber(),
                'transfer_date' => $data['transfer_date'],
                'from_type' => $data['from_type'],
                'from_id' => $data['from_id'],
                'to_type' => $data['to_type'],
                'to_id' => $data['to_id'],
                'amount' => $data['amount'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->postTransfer($transfer, $user);
            }

            return $transfer;
        });
    }

    public function postTransfer(CashTransfer $transfer, User $user): CashTransfer
    {
        if ($transfer->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['التحويل مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($transfer, $user) {
            $fromAccount = $this->resolveAccount($transfer->from_type, $transfer->from_id);
            $toAccount = $this->resolveAccount($transfer->to_type, $transfer->to_id);

            $entry = $this->journals->create([
                'entry_date' => $transfer->transfer_date->toDateString(),
                'description' => 'تحويل نقدي/بنكي '.$transfer->transfer_number,
                'reference' => $transfer->transfer_number,
                'status' => 'posted',
            ], [
                ['account_id' => $toAccount->id, 'debit' => (float) $transfer->amount, 'credit' => 0],
                ['account_id' => $fromAccount->id, 'debit' => 0, 'credit' => (float) $transfer->amount],
            ], $user);

            $transfer->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);
            $this->audit->log($user, 'cash_transfer.posted', $transfer);

            return $transfer->fresh('journalEntry');
        });
    }

    public function createExchange(array $data, User $user): CurrencyExchange
    {
        $source = CashBox::query()->findOrFail($data['source_cash_box_id']);
        $target = CashBox::query()->findOrFail($data['target_cash_box_id']);

        if ($source->id === $target->id) {
            throw ValidationException::withMessages([
                'target_cash_box_id' => ['يجب أن يختلف صندوق المصدر عن صندوق الهدف.'],
            ]);
        }

        $sourceCurrency = strtoupper((string) ($data['source_currency'] ?? $data['from_currency'] ?? $source->currency ?? 'SYP'));
        $targetCurrency = strtoupper((string) ($data['target_currency'] ?? $data['to_currency'] ?? $target->currency ?? 'SYP'));

        if ($sourceCurrency === $targetCurrency) {
            throw ValidationException::withMessages([
                'target_currency' => ['عملة المصدر والهدف يجب أن تكونا مختلفتين. استخدم التحويل العادي لنفس العملة.'],
            ]);
        }

        $sourceBoxCurrency = strtoupper((string) ($source->currency ?: 'SYP'));
        $targetBoxCurrency = strtoupper((string) ($target->currency ?: 'SYP'));

        if ($sourceBoxCurrency !== $sourceCurrency) {
            throw ValidationException::withMessages([
                'source_cash_box_id' => ["صندوق المصدر يجب أن يكون بعملة {$sourceCurrency}."],
            ]);
        }

        if ($targetBoxCurrency !== $targetCurrency) {
            throw ValidationException::withMessages([
                'target_cash_box_id' => ["صندوق الهدف يجب أن يكون بعملة {$targetCurrency}."],
            ]);
        }

        $sourceAmount = round((float) $data['source_amount'], 2);
        $targetAmount = round((float) $data['target_amount'], 2);
        $rate = round((float) $data['exchange_rate'], 8);

        if ($sourceAmount <= 0 || $targetAmount <= 0 || $rate <= 0) {
            throw ValidationException::withMessages([
                'source_amount' => ['المبالغ وسعر الصرف يجب أن تكون أكبر من صفر.'],
            ]);
        }

        return DB::transaction(function () use ($data, $user, $source, $target, $sourceCurrency, $targetCurrency, $sourceAmount, $targetAmount, $rate) {
            $exchange = CurrencyExchange::query()->create([
                'exchange_number' => $this->nextExchangeNumber(),
                'exchange_date' => $data['exchange_date'],
                'source_cash_box_id' => $source->id,
                'target_cash_box_id' => $target->id,
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'source_amount' => $sourceAmount,
                'target_amount' => $targetAmount,
                'exchange_rate' => $rate,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->postExchange($exchange, $user);
            }

            return $exchange->load(['sourceCashBox', 'targetCashBox']);
        });
    }

    public function postExchange(CurrencyExchange $exchange, User $user): CurrencyExchange
    {
        if ($exchange->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['عملية الصرف مرحّلة مسبقاً.']]);
        }

        return DB::transaction(function () use ($exchange, $user) {
            $source = CashBox::query()->findOrFail($exchange->source_cash_box_id);
            $target = CashBox::query()->findOrFail($exchange->target_cash_box_id);
            $asOf = $exchange->exchange_date->toDateString();

            $available = $this->cashBoxCurrencyBalance($source);
            if ($available + 0.0001 < (float) $exchange->source_amount) {
                throw ValidationException::withMessages([
                    'source_amount' => ["رصيد الصندوق المصدر غير كافٍ. المتاح: {$available}"],
                ]);
            }

            $sourceBase = $this->currencies->convert(
                (float) $exchange->source_amount,
                $exchange->source_currency,
                $this->currencies->baseCurrency(),
                $asOf
            );
            $targetBase = $this->currencies->convert(
                (float) $exchange->target_amount,
                $exchange->target_currency,
                $this->currencies->baseCurrency(),
                $asOf
            );

            $sourceAccount = $this->resolveAccount('cash_box', $source->id);
            $targetAccount = $this->resolveAccount('cash_box', $target->id);

            if ($sourceAccount->id === $targetAccount->id) {
                throw ValidationException::withMessages([
                    'target_cash_box_id' => ['يجب ربط كل صندوق عملة بحساب محاسبي مستقل لترحيل الصرف.'],
                ]);
            }

            $difference = round($targetBase - $sourceBase, 2);

            $lines = [
                ['account_id' => $targetAccount->id, 'debit' => $targetBase, 'credit' => 0, 'memo' => 'دخول '.$exchange->target_currency],
                ['account_id' => $sourceAccount->id, 'debit' => 0, 'credit' => $sourceBase, 'memo' => 'خروج '.$exchange->source_currency],
            ];

            if (abs($difference) >= 0.01) {
                if ($difference > 0) {
                    $gain = Account::query()->where('code', '4103')->firstOrFail();
                    $lines[] = ['account_id' => $gain->id, 'debit' => 0, 'credit' => $difference, 'memo' => 'ربح فرق صرف'];
                } else {
                    $loss = Account::query()->where('code', '5105')->firstOrFail();
                    $lines[] = ['account_id' => $loss->id, 'debit' => abs($difference), 'credit' => 0, 'memo' => 'خسارة فرق صرف'];
                }
            }

            $entry = $this->journals->create([
                'entry_date' => $asOf,
                'description' => 'صرف عملة '.$exchange->exchange_number,
                'reference' => $exchange->exchange_number,
                'status' => 'posted',
            ], $lines, $user);

            $exchange->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'source_base_amount' => $sourceBase,
                'target_base_amount' => $targetBase,
            ]);

            $this->audit->log($user, 'currency_exchange.posted', $exchange);

            return $exchange->fresh(['sourceCashBox', 'targetCashBox', 'journalEntry']);
        });
    }

    public function createReconciliation(array $data, User $user): BankReconciliation
    {
        $bank = Bank::query()->findOrFail($data['bank_id']);
        $bookBalance = $this->bookBalance($bank);

        return BankReconciliation::query()->create([
            'bank_id' => $bank->id,
            'statement_date' => $data['statement_date'],
            'statement_balance' => $data['statement_balance'],
            'book_balance' => $bookBalance,
            'difference' => round((float) $data['statement_balance'] - $bookBalance, 2),
            'status' => $data['status'] ?? 'open',
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    public function bookBalance(Bank $bank): float
    {
        $accountId = $bank->account_id
            ?? Account::query()->where('code', '1102')->value('id');

        if (! $accountId) {
            return (float) $bank->opening_balance;
        }

        return $this->ledgerBalance((int) $accountId, (float) $bank->opening_balance);
    }

    /**
     * Resolve the main/default cash box:
     * explicit id → setting default_cash_box_id → is_default → CASH-01 → first active.
     */
    public function resolveDefaultCashBoxId(?int $cashBoxId = null): ?int
    {
        if ($cashBoxId) {
            $exists = CashBox::query()->whereKey($cashBoxId)->where('is_active', true)->exists();

            return $exists ? $cashBoxId : null;
        }

        $setting = Setting::getValue('default_cash_box_id');
        if ($setting) {
            $id = (int) $setting;
            if ($id > 0 && CashBox::query()->whereKey($id)->where('is_active', true)->exists()) {
                return $id;
            }
        }

        $flagged = CashBox::query()->where('is_default', true)->where('is_active', true)->value('id');
        if ($flagged) {
            return (int) $flagged;
        }

        $cash01 = CashBox::query()->where('code', 'CASH-01')->where('is_active', true)->value('id');
        if ($cash01) {
            return (int) $cash01;
        }

        $first = CashBox::query()->where('is_active', true)->orderBy('id')->value('id');

        return $first ? (int) $first : null;
    }

    public function cashBoxBalance(CashBox $box): float
    {
        $accountId = $box->account_id
            ?? Account::query()->where('code', '1101')->value('id');

        if (! $accountId) {
            return (float) $box->opening_balance;
        }

        return $this->ledgerBalance((int) $accountId, (float) $box->opening_balance);
    }

    /**
     * Balance in the cash box's own currency.
     * Base-currency boxes with a dedicated GL account use ledger balance;
     * otherwise opening + posted currency exchanges (in/out).
     */
    public function cashBoxCurrencyBalance(CashBox $box): float
    {
        $currency = strtoupper((string) ($box->currency ?: 'SYP'));
        $base = $this->currencies->baseCurrency();

        $in = (float) CurrencyExchange::query()
            ->where('target_cash_box_id', $box->id)
            ->where('status', 'posted')
            ->sum('target_amount');
        $out = (float) CurrencyExchange::query()
            ->where('source_cash_box_id', $box->id)
            ->where('status', 'posted')
            ->sum('source_amount');
        $exchangeNet = $in - $out;

        if ($currency === $base && $box->account_id) {
            // Ledger already reflects posted exchange journals in base currency.
            return $this->cashBoxBalance($box);
        }

        return round((float) $box->opening_balance + $exchangeNet, 2);
    }

    protected function ledgerBalance(int $accountId, float $opening): float
    {
        $debit = (float) JournalDetail::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->sum('debit');
        $credit = (float) JournalDetail::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->sum('credit');

        return round($opening + $debit - $credit, 2);
    }

    protected function resolveAccount(string $type, int $id): Account
    {
        if ($type === 'cash_box') {
            $box = CashBox::query()->findOrFail($id);

            return $box->account_id
                ? Account::query()->findOrFail($box->account_id)
                : Account::query()->where('code', '1101')->firstOrFail();
        }

        if ($type === 'bank') {
            $bank = Bank::query()->findOrFail($id);

            return $bank->account_id
                ? Account::query()->findOrFail($bank->account_id)
                : Account::query()->where('code', '1102')->firstOrFail();
        }

        throw ValidationException::withMessages(['type' => ['نوع مصدر/هدف غير صالح.']]);
    }

    protected function nextNumber(): string
    {
        $year = now()->format('Y');
        $full = "CT-{$year}-";
        $last = CashTransfer::query()->where('transfer_number', 'like', $full.'%')->orderByDesc('transfer_number')->value('transfer_number');
        $seq = $last ? ((int) substr($last, strlen($full))) + 1 : 1;

        return $full.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    protected function nextExchangeNumber(): string
    {
        $year = now()->format('Y');
        $full = "CX-{$year}-";
        $last = CurrencyExchange::query()->where('exchange_number', 'like', $full.'%')->orderByDesc('exchange_number')->value('exchange_number');
        $seq = $last ? ((int) substr($last, strlen($full))) + 1 : 1;

        return $full.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
