<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ListSearch
{
    /** @var array<string, list<string>> table => column names */
    protected static array $columnCache = [];

    /**
     * Resolve search term from `q` or legacy `search` query params.
     */
    public static function term(Request $request): ?string
    {
        $term = $request->filled('q')
            ? (string) $request->string('q')
            : ($request->filled('search') ? (string) $request->string('search') : '');

        $term = trim($term);

        return $term !== '' ? $term : null;
    }

    /**
     * Apply a LIKE search across columns and optional relation columns.
     *
     * @param  list<string>  $columns  e.g. ['invoice_number', 'notes', 'total']
     * @param  array<string, list<string>>  $relations  e.g. ['customer' => ['name', 'code']]
     */
    public static function apply(Builder $query, Request $request, array $columns, array $relations = []): Builder
    {
        $term = self::term($request);
        if ($term === null) {
            return $query;
        }

        $like = '%'.$term.'%';
        // Some documents share a column list but not every column exists on every table.
        $columns = self::existingColumns($query, $columns);

        return $query->where(function (Builder $q) use ($columns, $relations, $like) {
            foreach ($columns as $i => $column) {
                if ($i === 0) {
                    $q->where($column, 'like', $like);
                } else {
                    $q->orWhere($column, 'like', $like);
                }
            }

            foreach ($relations as $relation => $relColumns) {
                $q->orWhereHas($relation, function (Builder $rq) use ($relColumns, $like) {
                    foreach ($relColumns as $i => $column) {
                        if ($i === 0) {
                            $rq->where($column, 'like', $like);
                        } else {
                            $rq->orWhere($column, 'like', $like);
                        }
                    }
                });
            }
        });
    }

    /**
     * Filter posted invoices with outstanding balance (total - paid_amount > 0).
     *
     * Accepts: unsettled=1|true|yes, or payment_status=open|unsettled.
     * Optional sort=remaining|remaining_asc|remaining_desc (default desc when unsettled).
     */
    public static function applyUnsettledInvoiceFilter(Builder $query, Request $request): Builder
    {
        $wantsUnsettled = $request->boolean('unsettled')
            || in_array(strtolower((string) $request->query('payment_status', '')), ['open', 'unsettled'], true);

        if (! $wantsUnsettled) {
            $sort = strtolower((string) $request->query('sort', ''));
            if (in_array($sort, ['remaining', 'remaining_asc', 'remaining_desc'], true)) {
                $dir = $sort === 'remaining_asc' ? 'asc' : 'desc';
                $query->reorder()
                    ->orderByRaw('(total - paid_amount) '.$dir)
                    ->orderByDesc('id');
            }

            return $query;
        }

        $query->where('status', 'posted')
            ->whereRaw('(total - paid_amount) > 0.001');

        $sort = strtolower((string) $request->query('sort', 'remaining_desc'));
        if ($sort === '' || $sort === 'remaining') {
            $sort = 'remaining_desc';
        }
        if (in_array($sort, ['remaining_asc', 'remaining_desc'], true)) {
            $dir = $sort === 'remaining_asc' ? 'asc' : 'desc';
            $query->reorder()
                ->orderByRaw('(total - paid_amount) '.$dir)
                ->orderByDesc('id');
        }

        return $query;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    protected static function existingColumns(Builder $query, array $columns): array
    {
        $table = $query->getModel()->getTable();

        if (! isset(self::$columnCache[$table])) {
            self::$columnCache[$table] = $query->getModel()->getConnection()->getSchemaBuilder()->getColumnListing($table);
        }

        $known = self::$columnCache[$table];

        return array_values(array_filter(
            $columns,
            fn (string $column) => str_contains($column, '.') || in_array($column, $known, true)
        ));
    }
}
