<?php

namespace App\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Page-based listing shared by every collection endpoint: ?page=, ?per_page= (1 to 100, default 25)
 * and ?search=, matched with LIKE against the given columns.
 */
final class Listing
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    /**
     * @param  list<string>  $searchable
     */
    public static function paginate(Builder $query, Request $request, array $searchable): LengthAwarePaginator
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($params['search'] ?? ''));

        if ($search !== '' && $searchable !== []) {
            $pattern = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $query) use ($searchable, $pattern): void {
                foreach ($searchable as $column) {
                    $query->orWhere($column, 'like', $pattern);
                }
            });
        }

        return $query->paginate((int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE))->withQueryString();
    }
}
