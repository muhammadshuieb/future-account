<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashBox;
use Illuminate\Validation\ValidationException;

/**
 * Ensures each cash box posts to an independent GL account (مطلوب لصرف العملات).
 */
class CashBoxGlService
{
    /** Default leaf GL accounts under current assets (11). */
    public const CURRENCY_DEFAULTS = [
        'USD' => ['code' => '1101', 'name' => 'صندوق نقدية دولار', 'name_en' => 'Cash USD'],
        'SYP' => ['code' => '1105', 'name' => 'صندوق نقدية ليرة سورية', 'name_en' => 'Cash SYP'],
        'TRY' => ['code' => '1107', 'name' => 'صندوق نقدية ليرة تركية', 'name_en' => 'Cash TRY'],
        'CNY' => ['code' => '1108', 'name' => 'صندوق نقدية يوان صيني', 'name_en' => 'Cash CNY'],
        'EUR' => ['code' => '1109', 'name' => 'صندوق نقدية يورو', 'name_en' => 'Cash EUR'],
        'SAR' => ['code' => '1110', 'name' => 'صندوق نقدية ريال سعودي', 'name_en' => 'Cash SAR'],
    ];

    /**
     * Seed / refresh standard per-currency cash GL accounts (idempotent).
     *
     * @return list<Account>
     */
    public function ensureStandardCurrencyAccounts(): array
    {
        $created = [];
        foreach (self::CURRENCY_DEFAULTS as $currency => $meta) {
            $created[] = $this->ensureAccount(
                $meta['code'],
                $meta['name'],
                $meta['name_en'],
                updateNames: true,
            );
        }

        return $created;
    }

    /**
     * Resolve (or create) the default GL account for a currency code.
     */
    public function ensureCurrencyAccount(string $currency): Account
    {
        $currency = strtoupper(trim($currency));
        $meta = self::CURRENCY_DEFAULTS[$currency] ?? null;

        if ($meta) {
            return $this->ensureAccount($meta['code'], $meta['name'], $meta['name_en'], updateNames: true);
        }

        $code = $this->nextAvailableCashCode();
        $name = "صندوق نقدية {$currency}";

        return $this->ensureAccount($code, $name, "Cash {$currency}", updateNames: false);
    }

    /**
     * Ensure the cash box has a dedicated postable GL account and return it.
     * Creates a currency default account, or a box-specific account when the
     * currency default is already linked to another box.
     */
    public function ensureBoxAccount(CashBox $box, bool $allowShared = false): Account
    {
        if ($box->account_id) {
            $account = Account::query()->find($box->account_id);
            $shared = $account && CashBox::query()
                ->where('account_id', $account->id)
                ->when($box->id, fn ($q) => $q->where('id', '!=', $box->id))
                ->exists();

            if ($account && ! $account->is_group && $account->is_active && ($allowShared || ! $shared)) {
                return $account;
            }
        }

        $currency = strtoupper((string) ($box->currency ?: 'USD'));
        $candidate = $this->ensureCurrencyAccount($currency);

        $takenByOther = CashBox::query()
            ->where('account_id', $candidate->id)
            ->when($box->id, fn ($q) => $q->where('id', '!=', $box->id))
            ->exists();

        if ($takenByOther) {
            $code = $this->nextAvailableCashCode();
            $suffix = $box->code ?: (string) ($box->id ?: $currency);
            $candidate = $this->ensureAccount(
                $code,
                "صندوق نقدية {$currency} ({$suffix})",
                "Cash {$currency} ({$suffix})",
                updateNames: false,
            );
        }

        if ((int) $box->account_id !== (int) $candidate->id) {
            $box->account_id = $candidate->id;
            if ($box->exists) {
                $box->save();
            }
        }

        return $candidate;
    }

    /**
     * Link missing / shared accounts for all cash boxes (safe production backfill).
     *
     * @return list<array{id:int,code:string,currency:string,account_id:int,account_code:string,action:string}>
     */
    public function backfillAll(?bool $dryRun = false): array
    {
        $this->ensureStandardCurrencyAccounts();
        $report = [];

        $boxes = CashBox::query()->orderBy('id')->get();
        foreach ($boxes as $box) {
            $before = $box->account_id;
            $currency = strtoupper((string) ($box->currency ?: 'USD'));

            if ($dryRun) {
                $preview = $this->previewLink($box);
                $report[] = [
                    'id' => (int) $box->id,
                    'code' => (string) $box->code,
                    'currency' => $currency,
                    'account_id' => $preview['account_id'],
                    'account_code' => $preview['account_code'],
                    'action' => $before && (int) $before === $preview['account_id']
                        ? 'unchanged'
                        : ($before ? 'relink' : 'link'),
                ];

                continue;
            }

            $account = $this->ensureBoxAccount($box);
            $report[] = [
                'id' => (int) $box->id,
                'code' => (string) $box->code,
                'currency' => $currency,
                'account_id' => (int) $account->id,
                'account_code' => (string) $account->code,
                'action' => $before && (int) $before === (int) $account->id
                    ? 'unchanged'
                    : ($before ? 'relink' : 'link'),
            ];
        }

        return $report;
    }

    /**
     * Strict resolve for FX posting: box must already be linked to a postable account.
     */
    public function requireLinkedAccount(CashBox $box): Account
    {
        if (! $box->account_id) {
            throw ValidationException::withMessages([
                'account_id' => ["الصندوق {$box->code} غير مربوط بحساب محاسبي. اربطه من الصناديق والبنوك قبل صرف العملات."],
            ]);
        }

        $account = Account::query()->find($box->account_id);
        if (! $account || $account->is_group || ! $account->is_active) {
            throw ValidationException::withMessages([
                'account_id' => ["الحساب المحاسبي المرتبط بالصندوق {$box->code} غير صالح."],
            ]);
        }

        return $account;
    }

    /**
     * Assert two cash boxes resolve to different GL accounts (for FX posting).
     */
    public function assertDistinctAccounts(CashBox $source, CashBox $target): void
    {
        $sourceAccount = $this->requireLinkedAccount($source);
        $targetAccount = $this->requireLinkedAccount($target);

        if ((int) $sourceAccount->id === (int) $targetAccount->id) {
            throw ValidationException::withMessages([
                'target_cash_box_id' => ['يجب ربط كل صندوق عملة بحساب محاسبي مستقل لترحيل الصرف.'],
            ]);
        }
    }

    /**
     * @return array{account_id:int,account_code:string}
     */
    protected function previewLink(CashBox $box): array
    {
        if ($box->account_id) {
            $existing = Account::query()->find($box->account_id);
            if ($existing && ! $existing->is_group) {
                $conflict = CashBox::query()
                    ->where('account_id', $existing->id)
                    ->where('id', '!=', $box->id)
                    ->exists();
                if (! $conflict) {
                    return ['account_id' => (int) $existing->id, 'account_code' => (string) $existing->code];
                }
            }
        }

        $currency = strtoupper((string) ($box->currency ?: 'USD'));
        $meta = self::CURRENCY_DEFAULTS[$currency] ?? null;
        $code = $meta['code'] ?? $this->nextAvailableCashCode();
        $account = Account::query()->where('code', $code)->first();
        if ($account) {
            $taken = CashBox::query()
                ->where('account_id', $account->id)
                ->where('id', '!=', $box->id)
                ->exists();
            if (! $taken) {
                return ['account_id' => (int) $account->id, 'account_code' => (string) $account->code];
            }
        }

        return ['account_id' => 0, 'account_code' => $code.'*' ];
    }

    protected function ensureAccount(string $code, string $name, string $nameEn, bool $updateNames): Account
    {
        $parent = Account::query()->where('code', '11')->first();
        if (! $parent) {
            throw ValidationException::withMessages([
                'account_id' => ['حساب الأصول المتداولة (11) غير موجود في دليل الحسابات.'],
            ]);
        }

        $existing = Account::query()->where('code', $code)->first();
        if ($existing) {
            if ($updateNames && ($existing->name !== $name || $existing->name_en !== $nameEn)) {
                $existing->update([
                    'name' => $name,
                    'name_en' => $nameEn,
                    'is_group' => false,
                    'is_active' => true,
                ]);
            }

            return $existing->fresh();
        }

        return Account::query()->create([
            'code' => $code,
            'name' => $name,
            'name_en' => $nameEn,
            'parent_id' => $parent->id,
            'type' => 'asset',
            'nature' => 'debit',
            'level' => ((int) $parent->level) + 1,
            'is_group' => false,
            'is_active' => true,
        ]);
    }

    protected function nextAvailableCashCode(): string
    {
        // Reserve 1111–1199 for additional cash boxes / currencies (1110 = SAR).
        for ($n = 1111; $n <= 1199; $n++) {
            $code = (string) $n;
            if (! Account::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Extremely unlikely fallback.
        return '11'.substr((string) (microtime(true) * 1000), -4);
    }
}
