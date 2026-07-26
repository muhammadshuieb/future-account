<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ListSearch
{
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
}
