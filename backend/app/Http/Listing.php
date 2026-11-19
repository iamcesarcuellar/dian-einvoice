<?php

namespace App\Http;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Page-based listing shared by every collection endpoint: ?page=, ?per_page= (1 to 100, default 25)
 * and ?search=, matched with LIKE against the given columns or by a custom search callback.
 */
final class Listing
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $searchable
     * @param  (Closure(Builder<TModel>, string): void)|null  $search  Receives the query and the LIKE pattern.
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(Builder $query, Request $request, array $searchable, ?Closure $search = null): LengthAwarePaginator
    {
        /** @var array{page?: int|string, per_page?: int|string, search?: string|null} $params */
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($params['search'] ?? ''));

        if ($term !== '' && ($searchable !== [] || $search !== null)) {
            $pattern = '%'.addcslashes($term, '%_\\').'%';

            $query->where(function (Builder $query) use ($searchable, $search, $pattern): void {
                if ($search !== null) {
                    $search($query, $pattern);

                    return;
                }

                foreach ($searchable as $column) {
                    $query->orWhere($column, 'like', $pattern);
                }
            });
        }

        return $query->paginate((int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE))->withQueryString();
    }
}
