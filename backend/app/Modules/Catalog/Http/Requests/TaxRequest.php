<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Tax;
use App\Modules\Reference\Enums\TaxType;

final class TaxRequest extends RateRequest
{
    protected function table(): string
    {
        return 'taxes';
    }

    protected function codes(): string
    {
        return TaxType::class;
    }

    protected function record(): ?Tax
    {
        return $this->route('tax');
    }
}
