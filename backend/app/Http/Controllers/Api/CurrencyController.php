<?php

namespace App\Http\Controllers\Api;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends ApiController
{
    public function __construct(protected CurrencyService $currencies) {}

    public function index(): JsonResponse
    {
        $this->currencies->ensureSeeded();

        $base = $this->currencies->baseCurrency();
        $asOf = now()->toDateString();

        $list = Currency::query()->orderBy('code')->get()->map(function (Currency $c) use ($base, $asOf) {
            $code = strtoupper((string) $c->code);
            $rate = 1.0;
            if ($code !== $base) {
                try {
                    $rate = $this->currencies->getRate($code, $base, $asOf);
                } catch (\Throwable) {
                    $rate = 0.0;
                }
            }

            return [
                'id' => $c->id,
                'code' => $code,
                'name' => $c->name,
                'name_en' => $c->name_en,
                'symbol' => $c->symbol,
                'decimal_places' => $c->decimal_places,
                'is_active' => (bool) $c->is_active,
                'rate_to_base' => $rate,
            ];
        });

        return $this->ok([
            'base_currency' => $base,
            'currencies' => $list,
        ]);
    }

    public function rates(Request $request): JsonResponse
    {
        $query = ExchangeRate::query()->with('creator:id,name')->orderByDesc('rate_date')->orderByDesc('id');

        if ($request->filled('from_currency')) {
            $query->where('from_currency', strtoupper($request->query('from_currency')));
        }
        if ($request->filled('to_currency')) {
            $query->where('to_currency', strtoupper($request->query('to_currency')));
        }

        return $this->ok($query->limit(200)->get());
    }

    public function storeRate(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');

        $data = $request->validate([
            'from_currency' => ['required', 'string', 'max:8'],
            'to_currency' => ['required', 'string', 'max:8', 'different:from_currency'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'rate_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $rate = ExchangeRate::query()->updateOrCreate(
            [
                'from_currency' => strtoupper($data['from_currency']),
                'to_currency' => strtoupper($data['to_currency']),
                'rate_date' => $data['rate_date'],
            ],
            [
                'rate' => $data['rate'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]
        );

        return $this->ok($rate->fresh('creator'), 201);
    }

    public function convert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric'],
            'from_currency' => ['required', 'string', 'max:8'],
            'to_currency' => ['required', 'string', 'max:8'],
            'as_of' => ['nullable', 'date'],
            'rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $rate = isset($data['rate'])
            ? (float) $data['rate']
            : $this->currencies->getRate($data['from_currency'], $data['to_currency'], $data['as_of'] ?? null);

        $converted = $this->currencies->convert(
            (float) $data['amount'],
            $data['from_currency'],
            $data['to_currency'],
            $data['as_of'] ?? null,
            $rate
        );

        return $this->ok([
            'amount' => (float) $data['amount'],
            'from_currency' => strtoupper($data['from_currency']),
            'to_currency' => strtoupper($data['to_currency']),
            'rate' => $rate,
            'converted' => $converted,
            'base_currency' => $this->currencies->baseCurrency(),
        ]);
    }
}
