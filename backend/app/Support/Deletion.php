<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class Deletion
{
    /** MySQL error 1451: a foreign key with ON DELETE RESTRICT still references the row. */
    private const int ROW_IS_REFERENCED = 1451;

    /**
     * Deletes the model, or answers 409 Conflict while other records (such as documents) use it.
     */
    public static function deleteOrConflict(Model $model, string $message): void
    {
        try {
            $model->delete();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === self::ROW_IS_REFERENCED) {
                throw new ConflictHttpException($message, $exception);
            }

            throw $exception;
        }
    }
}
