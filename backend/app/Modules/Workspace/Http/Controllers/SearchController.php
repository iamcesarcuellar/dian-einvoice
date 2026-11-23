<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Modules\Workspace\Services\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * GET /search?q=: the global search of the command palette. Invoices, credit and debit notes,
 * payments, contacts and items of the current company that match the text, a few of each group
 * with the total of matches, so the palette can say there are more.
 */
final class SearchController
{
    /** Shortest text worth searching. */
    private const int MIN_LENGTH = 2;

    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        /** @var array{q: string} $input */
        $input = $request->validate([
            'q' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:100'],
        ]);

        $term = trim($input['q']);

        if (Str::length($term) < self::MIN_LENGTH) {
            throw ValidationException::withMessages(['q' => __('workspace::errors.search_too_short', ['count' => self::MIN_LENGTH])]);
        }

        return response()->json(['data' => $search->find($term)]);
    }
}
