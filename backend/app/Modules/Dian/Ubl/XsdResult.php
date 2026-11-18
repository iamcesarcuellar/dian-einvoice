<?php

namespace App\Modules\Dian\Ubl;

final readonly class XsdResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public array $errors,
        /** Empty extensions (the reserved signature slot) left out of the validation. */
        public int $skippedExtensions,
    ) {}

    public function valid(): bool
    {
        return $this->errors === [];
    }
}
