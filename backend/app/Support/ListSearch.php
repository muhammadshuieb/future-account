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
     * Partner / contact fields commonly shown on list pages.
     *
     * @return list<string>
     */
    public static function partnerColumns(): array
    {
        return ['name', 'code', 'phone', 'email', 'address', 'tax_number', 'notes'];
    }

    /**
     * Product identity fields used across document lines and inventory.
     *
     * @return list<string>
     */
    public static function productColumns(): array
    {
        return ['name', 'sku', 'barcode', 'brand', 'model', 'description'];
    }

    /**
     * Shared document-list columns. Non-existent columns are dropped per table
     * by {@see existingColumns()} so callers can reuse one list safely.
     *
     * @param  list<string>  $extra
     * @return list<string>
     */
    public static function documentColumns(string $numberColumn, array $extra = []): array
    {
        return array_values(array_unique(array_merge([
            $numberColumn,
            'notes',
            'currency',
            'total',
            'amount',
            'status',
            'payment_type',
            'paid_amount',
            'discount_amount',
            'tax_amount',
            'subtotal',
            'method',
            'applied_amount',
            'base_amount',
            'exchange_rate',
            'invoice_date',
            'quote_date',
            'order_date',
            'return_date',
            'receipt_date',
            'payment_date',
            'request_date',
            'transfer_date',
            'count_date',
            'movement_date',
            'entry_date',
            'report_date',
            'exchange_date',
            'statement_date',
            'attendance_date',
            'created_at',
            'posted_at',
        ], $extra)));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function customerRelations(): array
    {
        return ['customer' => self::partnerColumns()];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function supplierRelations(): array
    {
        return ['supplier' => self::partnerColumns()];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function productLineRelations(string $relation = 'lines.product'): array
    {
        return [$relation => self::productColumns()];
    }

    /**
     * Warehouse / branch lookup used on many documents.
     *
     * @return array<string, list<string>>
     */
    public static function placeRelations(bool $withBranch = true, bool $withWarehouse = true): array
    {
        $relations = [];
        if ($withBranch) {
            $relations['branch'] = ['name', 'code', 'city'];
        }
        if ($withWarehouse) {
            $relations['warehouse'] = ['name', 'code', 'location'];
        }

        return $relations;
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
                self::whereLike($q, $column, $like, $i === 0);
            }

            foreach ($relations as $relation => $relColumns) {
                $q->orWhereHas($relation, function (Builder $rq) use ($relColumns, $like) {
                    foreach ($relColumns as $i => $column) {
                        self::whereLike($rq, $column, $like, $i === 0);
                    }
                });
            }
        });
    }

    /**
     * Case-insensitive partial match. Values are cast to text so numeric and date
     * columns stay searchable on PostgreSQL, where LIKE is also case-sensitive.
     */
    public static function whereLike(Builder $query, string $column, string $like, bool $first = true): Builder
    {
        $driver = $query->getConnection()->getDriverName();
        $cast = in_array($driver, ['mysql', 'mariadb'], true) ? 'CHAR' : 'TEXT';
        $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        $expression = "CAST({$wrapped} AS {$cast}) {$operator} ?";

        return $first
            ? $query->whereRaw($expression, [$like])
            : $query->orWhereRaw($expression, [$like]);
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
