<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CurrencyService
{
    public function baseCurrency(): string
    {
        return strtoupper((string) Setting::getValue('currency', 'SYP'));
    }

    public function supportedCodes(): array
    {
        return Currency::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    /**
     * Rate meaning: 1 unit of $fromCurrency = $rate units of $toCurrency.
     */
    public function getRate(string $fromCurrency, string $toCurrency, ?string $asOf = null): float
    {
        $from = strtoupper($fromCurrency);
        $to = strtoupper($toCurrency);

        if ($from === $to) {
            return 1.0;
        }

        $asOf = $asOf ?: now()->toDateString();

        $direct = ExchangeRate::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->whereDate('rate_date', '<=', $asOf)
            ->orderByDesc('rate_date')
            ->value('rate');

        if ($direct !== null) {
            return (float) $direct;
        }

        $inverse = ExchangeRate::query()
            ->where('from_currency', $to)
            ->where('to_currency', $from)
            ->whereDate('rate_date', '<=', $asOf)
            ->orderByDesc('rate_date')
            ->value('rate');

        if ($inverse !== null && (float) $inverse != 0.0) {
            return round(1 / (float) $inverse, 8);
        }

        // Cross via base currency
        $base = $this->baseCurrency();
        if ($from !== $base && $to !== $base) {
            $toBase = $this->getRate($from, $base, $asOf);
            $fromBase = $this->getRate($base, $to, $asOf);

            return round($toBase * $fromBase, 8);
        }

        throw ValidationException::withMessages([
            'exchange_rate' => ["لا يوجد سعر صرف من {$from} إلى {$to} بتاريخ {$asOf}."],
        ]);
    }

    public function convert(float $amount, string $fromCurrency, string $toCurrency, ?string $asOf = null, ?float $overrideRate = null): float
    {
        $rate = $overrideRate ?? $this->getRate($fromCurrency, $toCurrency, $asOf);

        return round($amount * $rate, 2);
    }

    /**
     * Resolve document FX fields relative to system base currency.
     *
     * @return array{currency: string, exchange_rate: float, base_amount: float}
     */
    public function resolveDocumentFx(
        float $documentAmount,
        ?string $currency = null,
        ?float $exchangeRate = null,
        ?string $asOf = null,
    ): array {
        $base = $this->baseCurrency();
        $currency = strtoupper($currency ?: $base);
        $asOf = $asOf ?: now()->toDateString();

        if ($currency === $base) {
            $rate = 1.0;
        } else {
            $rate = $exchangeRate !== null && $exchangeRate > 0
                ? (float) $exchangeRate
                : $this->getRate($currency, $base, $asOf);
        }

        return [
            'currency' => $currency,
            'exchange_rate' => round($rate, 8),
            'base_amount' => round($documentAmount * $rate, 2),
        ];
    }

    public function ensureSeeded(): void
    {
        $defaults = [
            ['code' => 'SYP', 'name' => 'الليرة السورية', 'name_en' => 'Syrian Pound', 'symbol' => 'ل.س', 'decimal_places' => 2],
            ['code' => 'TRY', 'name' => 'الليرة التركية', 'name_en' => 'Turkish Lira', 'symbol' => '₺', 'decimal_places' => 2],
            ['code' => 'USD', 'name' => 'الدولار الأمريكي', 'name_en' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2],
        ];

        foreach ($defaults as $row) {
            Currency::query()->updateOrCreate(['code' => $row['code']], $row + ['is_active' => true]);
        }
    }

    public function seedDemoRates(?Carbon $date = null): void
    {
        $date = ($date ?? now())->toDateString();
        $this->ensureSeeded();

        // Rates expressed as: 1 FROM = rate TO (base SYP)
        $rows = [
            ['from_currency' => 'USD', 'to_currency' => 'SYP', 'rate' => 15000],
            ['from_currency' => 'TRY', 'to_currency' => 'SYP', 'rate' => 450],
            ['from_currency' => 'SYP', 'to_currency' => 'USD', 'rate' => round(1 / 15000, 8)],
            ['from_currency' => 'SYP', 'to_currency' => 'TRY', 'rate' => round(1 / 450, 8)],
            ['from_currency' => 'USD', 'to_currency' => 'TRY', 'rate' => round(15000 / 450, 8)],
            ['from_currency' => 'TRY', 'to_currency' => 'USD', 'rate' => round(450 / 15000, 8)],
        ];

        foreach ($rows as $row) {
            ExchangeRate::query()->updateOrCreate(
                [
                    'from_currency' => $row['from_currency'],
                    'to_currency' => $row['to_currency'],
                    'rate_date' => $date,
                ],
                [
                    'rate' => $row['rate'],
                    'notes' => 'سعر تجريبي',
                ]
            );
        }
    }
}
