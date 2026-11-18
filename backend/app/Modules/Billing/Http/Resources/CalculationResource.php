<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Domain\Calculation;
use App\Modules\Reference\Enums\TaxType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Result of POST /invoices/preview: what the invoice would be, with nothing stored.
 *
 * @property Calculation $resource
 */
final class CalculationResource extends JsonResource
{
    /**
     * @param  list<array<string, mixed>>  $withholdings  The withholding copies that were calculated.
     */
    public function __construct(Calculation $calculation, private readonly array $withholdings)
    {
        parent::__construct($calculation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $calculation = $this->resource;

        return [
            'lines' => array_map(fn ($line, int $index): array => [
                'position' => $index + 1,
                'discount_amount' => (string) $line->discountAmount,
                'line_extension_amount' => (string) $line->lineExtensionAmount,
                'tax_amount' => (string) $line->taxAmount,
            ], $calculation->lines, array_keys($calculation->lines)),
            'taxes' => array_map(fn ($tax): array => [
                'dian_code' => $tax->code,
                'name' => TaxType::from($tax->code)->label(),
                'rate' => (string) $tax->rate,
                'taxable_amount' => (string) $tax->taxableAmount,
                'tax_amount' => (string) $tax->taxAmount,
            ], $calculation->taxes),
            'withholdings' => array_map(fn ($result, int $index): array => [
                'withholding_id' => $this->withholdings[$index]['withholding_id'],
                'dian_code' => $this->withholdings[$index]['dian_code'],
                'name' => $this->withholdings[$index]['name'],
                'rate' => (string) $this->withholdings[$index]['rate'],
                'base_amount' => (string) $result->baseAmount,
                'amount' => (string) $result->amount,
            ], $calculation->withholdings, array_keys($calculation->withholdings)),
            'totals' => $calculation->totals->toArray(),
        ];
    }
}
