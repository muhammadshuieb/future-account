<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bank;
use App\Models\BankReconciliation;
use App\Models\CashBox;
use App\Models\CashTransfer;
use App\Models\JournalDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashService
{
    public function __construct(
        protected JournalEntryService $journals,
        protected AuditLogger $audit,
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

        $debit = (float) JournalDetail::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->sum('debit');
        $credit = (float) JournalDetail::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->sum('credit');

        return round((float) $bank->opening_balance + $debit - $credit, 2);
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
}
