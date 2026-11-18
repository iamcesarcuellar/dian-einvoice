<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Withholding;
use App\Modules\Reference\Enums\WithholdingType;

final class WithholdingRequest extends RateRequest
{
    protected function table(): string
    {
        return 'withholdings';
    }

    protected function codes(): string
    {
        return WithholdingType::class;
    }

    protected function record(): ?Withholding
    {
        return $this->route('withholding');
    }

    protected function defaultsName(): string
    {
        return 'Default withholdings';
    }
}
