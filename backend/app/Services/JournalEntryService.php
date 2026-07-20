<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalEntryService
{
    /**
     * @param  array<int, array{account_id:int, debit?:float|int|string, credit?:float|int|string, memo?:string|null}>  $lines
     */
    public function create(array $data, array $lines, User $user): JournalEntry
    {
        $this->assertBalanced($lines);
        $this->assertPostableAccounts($lines);

        return DB::transaction(function () use ($data, $lines, $user) {
            $entry = JournalEntry::query()->create([
                'entry_number' => $data['entry_number'] ?? $this->nextEntryNumber(),
                'entry_date' => $data['entry_date'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'status' => 'draft',
                'created_by' => $user->id,
            ]);

            $this->syncLines($entry, $lines);

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->post($entry, $user);
            }

            return $entry->load(['details.account', 'creator']);
        });
    }

    /**
     * @param  array<int, array{account_id:int, debit?:float|int|string, credit?:float|int|string, memo?:string|null}>  $lines
     */
    public function update(JournalEntry $entry, array $data, array $lines): JournalEntry
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعديل قيد مرحّل أو ملغى.'],
            ]);
        }

        $this->assertBalanced($lines);
        $this->assertPostableAccounts($lines);

        return DB::transaction(function () use ($entry, $data, $lines) {
            $entry->update([
                'entry_date' => $data['entry_date'] ?? $entry->entry_date,
                'description' => $data['description'] ?? $entry->description,
                'reference' => $data['reference'] ?? $entry->reference,
            ]);

            $entry->details()->delete();
            $this->syncLines($entry, $lines);

            return $entry->fresh(['details.account', 'creator']);
        });
    }

    public function post(JournalEntry $entry, User $user): JournalEntry
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['يمكن ترحيل القيود المسودة فقط.'],
            ]);
        }

        $entry->load('details');

        if (! $entry->isBalanced()) {
            throw ValidationException::withMessages([
                'details' => ['يجب أن يتساوى مجموع المدين مع مجموع الدائن قبل الترحيل.'],
            ]);
        }

        $entry->update([
            'status' => 'posted',
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        return $entry->fresh(['details.account', 'creator', 'poster']);
    }

    public function void(JournalEntry $entry): JournalEntry
    {
        if ($entry->status === 'void') {
            throw ValidationException::withMessages([
                'status' => ['القيد ملغى مسبقاً.'],
            ]);
        }

        $entry->update(['status' => 'void']);

        return $entry->fresh(['details.account']);
    }

    /**
     * @param  array<int, array{account_id:int, debit?:float|int|string, credit?:float|int|string, memo?:string|null}>  $lines
     */
    protected function syncLines(JournalEntry $entry, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages([
                    "details.{$index}" => ['لا يمكن أن يحتوي السطر على مدين ودائن معاً.'],
                ]);
            }

            if ($debit <= 0 && $credit <= 0) {
                throw ValidationException::withMessages([
                    "details.{$index}" => ['يجب إدخال مبلغ مدين أو دائن لكل سطر.'],
                ]);
            }

            $entry->details()->create([
                'account_id' => $line['account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line['memo'] ?? null,
                'line_order' => $index + 1,
            ]);
        }
    }

    /**
     * @param  array<int, array{account_id:int, debit?:float|int|string, credit?:float|int|string}>  $lines
     */
    protected function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'details' => ['يجب أن يحتوي القيد على سطرين على الأقل.'],
            ]);
        }

        $debit = 0.0;
        $credit = 0.0;

        foreach ($lines as $line) {
            $debit += (float) ($line['debit'] ?? 0);
            $credit += (float) ($line['credit'] ?? 0);
        }

        if (round($debit, 2) !== round($credit, 2) || $debit <= 0) {
            throw ValidationException::withMessages([
                'details' => [
                    sprintf(
                        'القيد غير متوازن: مدين %s ≠ دائن %s',
                        number_format(round($debit, 2), 2),
                        number_format(round($credit, 2), 2)
                    ),
                ],
            ]);
        }
    }

    /**
     * @param  array<int, array{account_id:int}>  $lines
     */
    protected function assertPostableAccounts(array $lines): void
    {
        $ids = collect($lines)->pluck('account_id')->unique()->values();

        $accounts = Account::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $account = $accounts->get($id);

            if (! $account) {
                throw ValidationException::withMessages([
                    'details' => ["الحساب رقم {$id} غير موجود."],
                ]);
            }

            if ($account->is_group) {
                throw ValidationException::withMessages([
                    'details' => ["لا يمكن الترحيل على حساب تجميعي: {$account->code} — {$account->name}"],
                ]);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'details' => ["الحساب غير نشط: {$account->code} — {$account->name}"],
                ]);
            }
        }
    }

    public function nextEntryNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "JE-{$year}-";

        $last = JournalEntry::query()
            ->where('entry_number', 'like', $prefix.'%')
            ->orderByDesc('entry_number')
            ->value('entry_number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
