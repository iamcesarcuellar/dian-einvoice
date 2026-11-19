<?php

namespace App\Support;

use Exception;
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
        } catch (Exception $exception) {
            // A QueryException, which Model::delete() does not declare among the ones it throws.
            if ($exception instanceof QueryException && ($exception->errorInfo[1] ?? null) === self::ROW_IS_REFERENCED) {
                throw new ConflictHttpException($message, $exception);
            }

            throw $exception;
        }
    }
}
