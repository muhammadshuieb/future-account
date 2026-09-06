<?php

namespace App\Support;

use App\Models\Account;
use App\Models\User;

/**
 * Capital (equity / رأس المال) and profits (P&L / الأرباح) are gated separately from
 * generic reports.view so elevated showroom roles can operate without seeing them.
 */
class SensitiveFinanceAccess
{
    public const PROFITS = 'reports.profits.view';

    public const CAPITAL = 'reports.capital.view';

    public static function canViewProfits(?User $user): bool
    {
        return (bool) ($user && $user->can(self::PROFITS));
    }

    public static function canViewCapital(?User $user): bool
    {
        return (bool) ($user && $user->can(self::CAPITAL));
    }

    /**
     * @param  array{rows?: list<array<string, mixed>>, total_debit?: float, total_credit?: float}  $data
     * @return array{rows?: list<array<string, mixed>>, total_debit?: float, total_credit?: float}
     */
    public static function redactTrialBalance(array $data, ?User $user): array
    {
        if (self::canViewCapital($user)) {
            return $data;
        }

        $rows = collect($data['rows'] ?? [])
            ->reject(fn ($row) => ($row['type'] ?? null) === 'equity')
            ->values()
            ->all();

        $data['rows'] = $rows;
        $data['total_debit'] = round(collect($rows)->sum('debit'), 2);
        $data['total_credit'] = round(collect($rows)->sum('credit'), 2);
        $data['capital_redacted'] = true;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redactBalanceSheet(array $data, ?User $user): array
    {
        $canCapital = self::canViewCapital($user);
        $canProfits = self::canViewProfits($user);

        if (! $canCapital) {
            $data['equity'] = [];
            $data['total_equity'] = null;
            $data['capital_redacted'] = true;
        }

        if (! $canProfits) {
            $data['net_income'] = null;
            $data['profits_redacted'] = true;
            if ($canCapital) {
                $data['total_equity'] = round(collect($data['equity'] ?? [])->sum(fn ($r) => $r['balance'] ?? 0), 2);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redactBranchComplete(array $data, ?User $user): array
    {
        if (self::canViewProfits($user)) {
            return $data;
        }

        $data['profit'] = null;
        $data['profits_redacted'] = true;

        return $data;
    }

    public static function assertAccountVisible(?User $user, Account $account): void
    {
        if ($account->type === 'equity' && ! self::canViewCapital($user)) {
            abort(403, 'ليس لديك صلاحية.');
        }
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    public static function reportTypesRequiringProfits(): array
    {
        return ['income-statement', 'profit'];
    }
}
