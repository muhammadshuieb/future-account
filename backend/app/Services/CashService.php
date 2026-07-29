<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bank;
use App\Models\BankReconciliation;
use App\Models\CashBox;
use App\Models\CashTransfer;
use App\Models\CurrencyExchange;
use App\Models\JournalDetail;
use App\Models\Receipt;
use App\Models\Setting;
use App\Models\SupplierPayment;
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
        $this->assertSameCurrencyTransfer(
            $data['from_type'],
            (int) $data['from_id'],
            $data['to_type'],
            (int) $data['to_id'],
        );

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

        $this->assertSameCurrencyTransfer(
            $transfer->from_type,
            (int) $transfer->from_id,
            $transfer->to_type,
            (int) $transfer->to_id,
        );

        return DB::transaction(function () use ($transfer, $user) {
            $fromAccount = $this->resolveAccount($transfer->from_type, $transfer->from_id);
            $toAccount = $this->resolveAccount($transfer->to_type, $transfer->to_id);

            $entry = $this->journals->create([
                'entry_date' => $transfer->transfer_date->toDateString(),
                'branch_id' => $this->resolveTransferBranchId($transfer),
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

        $sourceCurrency = strtoupper((string) ($data['source_currency'] ?? $data['from_currency'] ?? $source->currency ?? 'USD'));
        $targetCurrency = strtoupper((string) ($data['target_currency'] ?? $data['to_currency'] ?? $target->currency ?? 'USD'));

        if ($sourceCurrency === $targetCurrency) {
            throw ValidationException::withMessages([
                'target_currency' => ['عملة المصدر والهدف يجب أن تكونا مختلفتين. استخدم التحويل العادي لنفس العملة.'],
            ]);
        }

        $sourceBoxCurrency = strtoupper((string) ($source->currency ?: 'USD'));
        $targetBoxCurrency = strtoupper((string) ($target->currency ?: 'USD'));

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
                'branch_id' => $this->resolveExchangeBranchId($source, $target),
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
     * Balance in the bank's own currency (mirrors cashBoxCurrencyBalance).
     * Base-currency banks with a dedicated GL account use ledger balance.
     * Non-base banks are tracked from their own posted bank movements.
     */
    public function bankCurrencyBalance(Bank $bank): float
    {
        $currency = strtoupper((string) ($bank->currency ?: $this->currencies->baseCurrency()));
        $base = $this->currencies->baseCurrency();

        if ($currency === $base && $bank->account_id) {
            return $this->bookBalance($bank);
        }

        $receiptsIn = Receipt::query()
            ->where('bank_id', $bank->id)
            ->where('status', 'posted')
            ->get()
            ->sum(fn (Receipt $receipt) => $this->boxMovementAmount(
                $currency,
                $receipt->currency,
                (float) $receipt->amount,
                $receipt->base_amount !== null ? (float) $receipt->base_amount : null,
                $receipt->receipt_date?->toDateString()
            ));

        $paymentsOut = SupplierPayment::query()
            ->where('bank_id', $bank->id)
            ->where('status', 'posted')
            ->get()
            ->sum(fn (SupplierPayment $payment) => $this->boxMovementAmount(
                $currency,
                $payment->currency,
                (float) $payment->amount,
                $payment->base_amount !== null ? (float) $payment->base_amount : null,
                $payment->payment_date?->toDateString()
            ));

        $transfersIn = (float) CashTransfer::query()
            ->where('to_type', 'bank')
            ->where('to_id', $bank->id)
            ->where('status', 'posted')
            ->sum('amount');

        $transfersOut = (float) CashTransfer::query()
            ->where('from_type', 'bank')
            ->where('from_id', $bank->id)
            ->where('status', 'posted')
            ->sum('amount');

        return round(
            (float) $bank->opening_balance
            + (float) $receiptsIn
            - (float) $paymentsOut
            + $transfersIn
            - $transfersOut,
            2
        );
    }

    /**
     * Dashboard / cash-banks totals. Never mixes currencies into one native sum:
     * filtered currency → totals in that currency; all currencies → native by_currency
     * plus base-currency equivalents for the headline tiles.
     *
     * @return array{
     *   currency: string,
     *   cash: float,
     *   bank: float,
     *   liquidity: float,
     *   by_currency: list<array{currency: string, cash: float, bank: float, liquidity: float}>|null,
     *   boxes: list<array{id: int, code: string, name: string, currency: string, branch_id: int|null, balance: float}>,
     *   banks: list<array{id: int, code: string, name: string, currency: string, branch_id: int|null, balance: float}>
     * }
     */
    public function liquiditySummary(?int $branchId = null, ?string $currencyFilter = null): array
    {
        $currencyFilter = $currencyFilter !== null && $currencyFilter !== ''
            ? strtoupper(trim($currencyFilter))
            : null;
        $base = $this->currencies->baseCurrency();
        $asOf = now()->toDateString();

        $boxes = CashBox::query()
            ->where('is_active', true)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($currencyFilter !== null, fn ($q) => $q->whereRaw('UPPER(COALESCE(currency, ?)) = ?', [$base, $currencyFilter]))
            ->orderBy('code')
            ->get();

        $banks = Bank::query()
            ->where('is_active', true)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($currencyFilter !== null, fn ($q) => $q->whereRaw('UPPER(COALESCE(currency, ?)) = ?', [$base, $currencyFilter]))
            ->orderBy('code')
            ->get();

        $boxRows = $boxes->map(function (CashBox $box) {
            $currency = strtoupper((string) ($box->currency ?: $this->currencies->baseCurrency()));

            return [
                'id' => (int) $box->id,
                'code' => (string) $box->code,
                'name' => (string) $box->name,
                'currency' => $currency,
                'branch_id' => $box->branch_id ? (int) $box->branch_id : null,
                'balance' => $this->cashBoxCurrencyBalance($box),
            ];
        })->values()->all();

        $bankRows = $banks->map(function (Bank $bank) {
            $currency = strtoupper((string) ($bank->currency ?: $this->currencies->baseCurrency()));

            return [
                'id' => (int) $bank->id,
                'code' => (string) $bank->code,
                'name' => (string) $bank->name,
                'currency' => $currency,
                'branch_id' => $bank->branch_id ? (int) $bank->branch_id : null,
                'balance' => $this->bankCurrencyBalance($bank),
            ];
        })->values()->all();

        $byCurrencyMap = [];
        foreach ($boxRows as $row) {
            $code = $row['currency'];
            $byCurrencyMap[$code] ??= ['currency' => $code, 'cash' => 0.0, 'bank' => 0.0];
            $byCurrencyMap[$code]['cash'] += $row['balance'];
        }
        foreach ($bankRows as $row) {
            $code = $row['currency'];
            $byCurrencyMap[$code] ??= ['currency' => $code, 'cash' => 0.0, 'bank' => 0.0];
            $byCurrencyMap[$code]['bank'] += $row['balance'];
        }

        $byCurrency = collect($byCurrencyMap)
            ->sortBy(function (array $row) use ($base) {
                return $row['currency'] === $base ? '0'.$row['currency'] : '1'.$row['currency'];
            })
            ->values()
            ->map(function (array $row) {
                $cash = round($row['cash'], 2);
                $bank = round($row['bank'], 2);

                return [
                    'currency' => $row['currency'],
                    'cash' => $cash,
                    'bank' => $bank,
                    'liquidity' => round($cash + $bank, 2),
                ];
            })
            ->all();

        if ($currencyFilter !== null) {
            $cash = round(array_sum(array_column($boxRows, 'balance')), 2);
            $bank = round(array_sum(array_column($bankRows, 'balance')), 2);

            return [
                'currency' => $currencyFilter,
                'cash' => $cash,
                'bank' => $bank,
                'liquidity' => round($cash + $bank, 2),
                'by_currency' => null,
                'boxes' => $boxRows,
                'banks' => $bankRows,
            ];
        }

        $cashBase = 0.0;
        $bankBase = 0.0;
        foreach ($byCurrency as $row) {
            $cashBase += $this->toBaseAmount($row['cash'], $row['currency'], $asOf);
            $bankBase += $this->toBaseAmount($row['bank'], $row['currency'], $asOf);
        }
        $cashBase = round($cashBase, 2);
        $bankBase = round($bankBase, 2);

        return [
            'currency' => $base,
            'cash' => $cashBase,
            'bank' => $bankBase,
            'liquidity' => round($cashBase + $bankBase, 2),
            'by_currency' => $byCurrency,
            'boxes' => $boxRows,
            'banks' => $bankRows,
        ];
    }

    protected function toBaseAmount(float $amount, string $currency, ?string $asOf = null): float
    {
        if ($amount == 0.0) {
            return 0.0;
        }

        $currency = strtoupper($currency);
        $base = $this->currencies->baseCurrency();
        if ($currency === $base) {
            return round($amount, 2);
        }

        try {
            return $this->currencies->convert($amount, $currency, $base, $asOf);
        } catch (ValidationException) {
            // Never treat foreign currency as 1:1 with base — that falsely mixes currencies.
            return 0.0;
        }
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
     * Base-currency boxes with a dedicated GL account use ledger balance.
     * Non-base boxes are tracked from their own posted box movements.
     */
    public function cashBoxCurrencyBalance(CashBox $box): float
    {
        $currency = strtoupper((string) ($box->currency ?: 'USD'));
        $base = $this->currencies->baseCurrency();

        if ($currency === $base && $box->account_id) {
            // Ledger already reflects posted movements in the system base currency.
            return $this->cashBoxBalance($box);
        }

        $receiptsIn = Receipt::query()
            ->where('cash_box_id', $box->id)
            ->where('status', 'posted')
            ->get()
            ->sum(fn (Receipt $receipt) => $this->boxMovementAmount(
                $currency,
                $receipt->currency,
                (float) $receipt->amount,
                $receipt->base_amount !== null ? (float) $receipt->base_amount : null,
                $receipt->receipt_date?->toDateString()
            ));

        $paymentsOut = SupplierPayment::query()
            ->where('cash_box_id', $box->id)
            ->where('status', 'posted')
            ->get()
            ->sum(fn (SupplierPayment $payment) => $this->boxMovementAmount(
                $currency,
                $payment->currency,
                (float) $payment->amount,
                $payment->base_amount !== null ? (float) $payment->base_amount : null,
                $payment->payment_date?->toDateString()
            ));

        $transfersIn = (float) CashTransfer::query()
            ->where('to_type', 'cash_box')
            ->where('to_id', $box->id)
            ->where('status', 'posted')
            ->sum('amount');

        $transfersOut = (float) CashTransfer::query()
            ->where('from_type', 'cash_box')
            ->where('from_id', $box->id)
            ->where('status', 'posted')
            ->sum('amount');

        $in = (float) CurrencyExchange::query()
            ->where('target_cash_box_id', $box->id)
            ->where('status', 'posted')
            ->sum('target_amount');
        $out = (float) CurrencyExchange::query()
            ->where('source_cash_box_id', $box->id)
            ->where('status', 'posted')
            ->sum('source_amount');
        $exchangeNet = $in - $out;

        return round(
            (float) $box->opening_balance
            + (float) $receiptsIn
            - (float) $paymentsOut
            + $transfersIn
            - $transfersOut
            + $exchangeNet,
            2
        );
    }

    protected function boxMovementAmount(
        string $boxCurrency,
        ?string $documentCurrency,
        float $amount,
        ?float $baseAmount = null,
        ?string $asOf = null,
    ): float {
        $documentCurrency = strtoupper((string) ($documentCurrency ?: $boxCurrency));
        if ($documentCurrency === $boxCurrency) {
            return round($amount, 2);
        }

        if ($baseAmount !== null && $baseAmount > 0) {
            try {
                return $this->currencies->convert($baseAmount, $this->currencies->baseCurrency(), $boxCurrency, $asOf);
            } catch (ValidationException) {
                return round($amount, 2);
            }
        }

        try {
            return $this->currencies->convert($amount, $documentCurrency, $boxCurrency, $asOf);
        } catch (ValidationException) {
            return round($amount, 2);
        }
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

    /**
     * A plain transfer moves one amount between two accounts, so both sides must share a currency.
     * Cross-currency moves need a documented rate and belong to currency exchange.
     */
    protected function assertSameCurrencyTransfer(string $fromType, int $fromId, string $toType, int $toId): void
    {
        $fromCurrency = $this->resolveEntityCurrency($fromType, $fromId);
        $toCurrency = $this->resolveEntityCurrency($toType, $toId);

        if ($fromCurrency !== $toCurrency) {
            throw ValidationException::withMessages([
                'to_id' => [
                    "لا يمكن التحويل بين عملتين مختلفتين ({$fromCurrency} → {$toCurrency}). استخدم صرف العملات لتحديد سعر الصرف.",
                ],
            ]);
        }
    }

    protected function resolveEntityCurrency(string $type, int $id): string
    {
        $currency = match ($type) {
            'cash_box' => CashBox::query()->whereKey($id)->value('currency'),
            'bank' => Bank::query()->whereKey($id)->value('currency'),
            default => throw ValidationException::withMessages(['type' => ['نوع مصدر/هدف غير صالح.']]),
        };

        return strtoupper((string) ($currency ?: $this->currencies->baseCurrency()));
    }

    protected function resolveTransferBranchId(CashTransfer $transfer): ?int
    {
        $fromBranchId = $this->resolveEntityBranchId($transfer->from_type, (int) $transfer->from_id);
        $toBranchId = $this->resolveEntityBranchId($transfer->to_type, (int) $transfer->to_id);

        return $fromBranchId ?: $toBranchId;
    }

    protected function resolveExchangeBranchId(CashBox $source, CashBox $target): ?int
    {
        return $source->branch_id ?: $target->branch_id;
    }

    protected function resolveEntityBranchId(string $type, int $id): ?int
    {
        if ($type === 'cash_box') {
            $branchId = CashBox::query()->whereKey($id)->value('branch_id');

            return $branchId ? (int) $branchId : null;
        }

        if ($type === 'bank') {
            $branchId = Bank::query()->whereKey($id)->value('branch_id');

            return $branchId ? (int) $branchId : null;
        }

        return null;
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
