<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashBox;
use App\Models\EquityPartner;
use App\Models\ProfitDistribution;
use App\Models\ProfitDistributionLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Arabic SME pattern: توزيع أرباح / مسحوبات شركاء from a cash box.
 *
 * Posting (GL-balanced, base currency):
 *   Dr partner equity/drawings account (or Retained Earnings 3102) — per line
 *   Cr cash box GL account — total
 */
class ProfitDistributionService
{
    public function __construct(
        protected JournalEntryService $journals,
        protected CurrencyService $currencies,
        protected CashService $cash,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   distribution_date:string,
     *   cash_box_id:int,
     *   total_amount?:float|int|string|null,
     *   currency?:string|null,
     *   exchange_rate?:float|int|string|null,
     *   notes?:string|null,
     *   status?:string|null,
     *   auto_split?:bool|null,
     *   lines?:list<array{equity_partner_id:int, amount?:float|int|string|null, share_percent?:float|int|string|null}>
     * }  $data
     */
    public function create(array $data, User $user): ProfitDistribution
    {
        return DB::transaction(function () use ($data, $user) {
            $box = CashBox::query()->findOrFail((int) $data['cash_box_id']);
            $currency = strtoupper((string) ($data['currency'] ?? $box->currency ?: $this->currencies->baseCurrency()));
            $this->cash->assertCashBoxCurrency($box->id, $currency);

            $linesInput = $data['lines'] ?? [];
            $autoSplit = (bool) ($data['auto_split'] ?? false);

            if ($autoSplit || $linesInput === []) {
                $totalAmount = round((float) ($data['total_amount'] ?? 0), 2);
                if ($totalAmount <= 0) {
                    throw ValidationException::withMessages([
                        'total_amount' => ['يجب تحديد المبلغ الإجمالي لتوزيعه تلقائياً حسب نسب الشركاء.'],
                    ]);
                }
                $linesInput = $this->buildAutoSplitLines($totalAmount);
            }

            $prepared = $this->prepareLines($linesInput, $currency, $data['distribution_date'] ?? null, isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null);
            $totalAmount = $prepared['total_amount'];
            $fx = $prepared['fx'];
            $lineRows = $prepared['lines'];

            $distribution = ProfitDistribution::query()->create([
                'distribution_number' => $this->nextNumber(),
                'distribution_date' => $data['distribution_date'],
                'cash_box_id' => $box->id,
                'total_amount' => $totalAmount,
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($lineRows as $row) {
                ProfitDistributionLine::query()->create([
                    'profit_distribution_id' => $distribution->id,
                    'equity_partner_id' => $row['equity_partner_id'],
                    'share_percent' => $row['share_percent'],
                    'amount' => $row['amount'],
                    'base_amount' => $row['base_amount'],
                ]);
            }

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->post($distribution->fresh(['lines.partner', 'cashBox']), $user);
            }

            return $distribution->fresh(['lines.partner', 'cashBox', 'creator']);
        });
    }

    public function post(ProfitDistribution $distribution, User $user): ProfitDistribution
    {
        if ($distribution->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['توزيع الأرباح مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($distribution, $user) {
            $distribution->load(['lines.partner.account', 'cashBox']);

            if ($distribution->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => ['يجب وجود سطور شركاء قبل الترحيل.']]);
            }

            $box = $distribution->cashBox;
            if (! $box) {
                throw ValidationException::withMessages(['cash_box_id' => ['الصندوق غير موجود.']]);
            }

            $this->cash->assertCashBoxCurrency($box->id, $distribution->currency);

            $available = $this->cash->cashBoxCurrencyBalance($box);
            if ($available + 0.0001 < (float) $distribution->total_amount) {
                throw ValidationException::withMessages([
                    'total_amount' => ["رصيد الصندوق غير كافٍ. المتاح: {$available}"],
                ]);
            }

            $cashAccount = $this->cash->resolveCashBoxAccount($box);
            $defaultDebit = $this->defaultDrawingsAccount();

            $jeLines = [];
            foreach ($distribution->lines as $line) {
                $partner = $line->partner;
                $debitAccount = ($partner && $partner->account_id)
                    ? Account::query()->findOrFail($partner->account_id)
                    : $defaultDebit;

                $base = (float) ($line->base_amount ?: $line->amount);
                if ($base <= 0) {
                    continue;
                }

                $memo = 'توزيع أرباح — '.($partner?->name ?? 'شريك');
                $jeLines[] = [
                    'account_id' => $debitAccount->id,
                    'debit' => $base,
                    'credit' => 0,
                    'memo' => $memo,
                ];
            }

            $totalBase = (float) ($distribution->base_amount ?: $distribution->total_amount);
            if ($jeLines === [] || $totalBase <= 0) {
                throw ValidationException::withMessages(['total_amount' => ['المبلغ يجب أن يكون أكبر من صفر.']]);
            }

            $jeLines[] = [
                'account_id' => $cashAccount->id,
                'debit' => 0,
                'credit' => $totalBase,
                'memo' => 'صرف توزيع أرباح من '.$box->name,
            ];

            $entry = $this->journals->create([
                'entry_date' => $distribution->distribution_date->toDateString(),
                'branch_id' => $box->branch_id,
                'description' => 'توزيع أرباح '.$distribution->distribution_number,
                'reference' => $distribution->distribution_number,
                'status' => 'posted',
            ], $jeLines, $user);

            $distribution->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
            ]);

            $this->audit->log($user, 'profit_distribution.posted', $distribution);

            return $distribution->fresh(['lines.partner', 'cashBox', 'journalEntry', 'creator']);
        });
    }

    public function delete(ProfitDistribution $distribution): void
    {
        if ($distribution->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['لا يمكن حذف توزيع مرحّل.']]);
        }

        $distribution->lines()->delete();
        $distribution->delete();
    }

    /**
     * @return list<array{equity_partner_id:int, amount:float, share_percent:float}>
     */
    protected function buildAutoSplitLines(float $totalAmount): array
    {
        $partners = EquityPartner::query()
            ->where('is_active', true)
            ->where('share_percent', '>', 0)
            ->orderBy('id')
            ->get();

        if ($partners->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['لا يوجد شركاء نشطون بنسب مشاركة لتوزيع المبلغ تلقائياً.'],
            ]);
        }

        $shareSum = (float) $partners->sum(fn (EquityPartner $p) => (float) $p->share_percent);
        if ($shareSum <= 0) {
            throw ValidationException::withMessages([
                'lines' => ['مجموع نسب الشركاء يجب أن يكون أكبر من صفر.'],
            ]);
        }

        $lines = [];
        $allocated = 0.0;
        $lastIndex = $partners->count() - 1;

        foreach ($partners->values() as $i => $partner) {
            $share = (float) $partner->share_percent;
            if ($i === $lastIndex) {
                $amount = round($totalAmount - $allocated, 2);
            } else {
                $amount = round($totalAmount * ($share / $shareSum), 2);
                $allocated += $amount;
            }

            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'equity_partner_id' => $partner->id,
                'amount' => $amount,
                'share_percent' => $share,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'total_amount' => ['تعذّر توزيع المبلغ على الشركاء.'],
            ]);
        }

        return $lines;
    }

    /**
     * @param  list<array{equity_partner_id:int, amount?:float|int|string|null, share_percent?:float|int|string|null}>  $linesInput
     * @return array{total_amount:float, fx:array{currency:string, exchange_rate:float, base_amount:float}, lines:list<array{equity_partner_id:int, share_percent:float, amount:float, base_amount:float}>}
     */
    protected function prepareLines(array $linesInput, string $currency, ?string $asOf, ?float $exchangeRate): array
    {
        if ($linesInput === []) {
            throw ValidationException::withMessages(['lines' => ['أضف سطراً واحداً على الأقل لشريك.']]);
        }

        $seen = [];
        $rows = [];
        $totalAmount = 0.0;

        foreach ($linesInput as $index => $line) {
            $partnerId = (int) ($line['equity_partner_id'] ?? 0);
            if ($partnerId <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.equity_partner_id" => ['الشريك مطلوب.'],
                ]);
            }
            if (isset($seen[$partnerId])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.equity_partner_id" => ['لا يمكن تكرار نفس الشريك في المستند.'],
                ]);
            }
            $seen[$partnerId] = true;

            $partner = EquityPartner::query()->find($partnerId);
            if (! $partner || ! $partner->is_active) {
                throw ValidationException::withMessages([
                    "lines.{$index}.equity_partner_id" => ['الشريك غير موجود أو غير نشط.'],
                ]);
            }

            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.amount" => ['مبلغ الشريك يجب أن يكون أكبر من صفر.'],
                ]);
            }

            $share = array_key_exists('share_percent', $line) && $line['share_percent'] !== null && $line['share_percent'] !== ''
                ? (float) $line['share_percent']
                : (float) $partner->share_percent;

            $rows[] = [
                'equity_partner_id' => $partner->id,
                'share_percent' => $share,
                'amount' => $amount,
            ];
            $totalAmount += $amount;
        }

        $totalAmount = round($totalAmount, 2);
        $fx = $this->currencies->resolveDocumentFx($totalAmount, $currency, $exchangeRate, $asOf);

        $lineRows = [];
        $baseAllocated = 0.0;
        $last = count($rows) - 1;
        foreach ($rows as $i => $row) {
            if ($i === $last) {
                $base = round((float) $fx['base_amount'] - $baseAllocated, 2);
            } else {
                $ratio = $totalAmount > 0 ? ($row['amount'] / $totalAmount) : 0;
                $base = round((float) $fx['base_amount'] * $ratio, 2);
                $baseAllocated += $base;
            }
            $lineRows[] = $row + ['base_amount' => $base];
        }

        return [
            'total_amount' => $totalAmount,
            'fx' => $fx,
            'lines' => $lineRows,
        ];
    }

    protected function defaultDrawingsAccount(): Account
    {
        $account = Account::query()->where('code', '3103')->first()
            ?? Account::query()->where('code', '3102')->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'account_id' => ['حساب الأرباح المحتجزة / مسحوبات الشركاء غير موجود في دليل الحسابات.'],
            ]);
        }

        return $account;
    }

    protected function nextNumber(): string
    {
        $year = now()->format('Y');
        $full = "PD-{$year}-";
        $last = ProfitDistribution::query()
            ->where('distribution_number', 'like', $full.'%')
            ->orderByDesc('distribution_number')
            ->value('distribution_number');
        $seq = $last ? ((int) substr((string) $last, strlen($full))) + 1 : 1;

        return $full.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
