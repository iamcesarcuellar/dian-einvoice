<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Domain\Calculation;
use App\Modules\Billing\Domain\DocumentCalculator;
use App\Modules\Billing\Domain\LineInput;
use App\Modules\Billing\Domain\WithholdingInput;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentLine;
use App\Modules\Billing\Models\DocumentWithholding;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Tax;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Reference\Enums\TaxType;
use App\Modules\Reference\Enums\UnitOfMeasure;
use App\Modules\Reference\Enums\WithholdingBase;
use App\Modules\Reference\Enums\WithholdingType;

/**
 * Turns draft input into the copies a document keeps, runs the calculator over them and writes
 * the results. Line copies carry the document_lines columns; withholding copies carry the
 * document_withholdings columns.
 */
final class DocumentPricing
{
    /** Fields of a line copy, before amounts. */
    private const array LINE_FIELDS = [
        'position',
        'item_id',
        'code',
        'description',
        'standard_scheme',
        'standard_code',
        'unit_code',
        'quantity',
        'unit_price',
        'discount_rate',
        'tax_id',
        'tax_code',
        'tax_name',
        'tax_rate',
    ];

    /** Fields of a withholding copy, before amounts. */
    private const array WITHHOLDING_FIELDS = ['position', 'withholding_id', 'dian_code', 'name', 'rate'];

    public function __construct(private readonly DocumentCalculator $calculator) {}

    /**
     * Copies the item and tax data of the current company into each line. A field left out falls
     * back to the item; an explicit null tax_id means excluded (no tax).
     *
     * @param  array<int, array<string, mixed>>  $lines  Validated input lines.
     * @return list<array<string, mixed>>
     */
    public function resolveLines(array $lines): array
    {
        $lines = array_values($lines);
        $items = Item::query()->with('tax')->whereKey(array_filter(array_column($lines, 'item_id')))->get()->keyBy('id');
        $taxes = Tax::query()->whereKey(array_filter(array_column($lines, 'tax_id')))->get()->keyBy('id');

        $resolved = [];

        foreach ($lines as $index => $line) {
            $item = isset($line['item_id']) ? $items->get($line['item_id']) : null;
            $tax = array_key_exists('tax_id', $line)
                ? ($line['tax_id'] === null ? null : $taxes->get($line['tax_id']))
                : $item?->tax;

            $resolved[] = [
                'position' => $index + 1,
                'item_id' => $item?->getKey(),
                'code' => $line['code'] ?? $item?->code,
                'description' => $line['description'] ?? $item?->name,
                // The product standard always comes from the item; free lines have none.
                'standard_scheme' => $item?->standard_scheme,
                'standard_code' => $item?->standard_code,
                'unit_code' => $line['unit_code'] ?? $item?->unit_code->value ?? UnitOfMeasure::Unit->value,
                'quantity' => (string) ($line['quantity'] ?? '0'),
                'unit_price' => (string) ($line['unit_price'] ?? $item?->unit_price ?? '0'),
                'discount_rate' => (string) ($line['discount_rate'] ?? '0'),
                'tax_id' => $tax?->getKey(),
                'tax_code' => $tax?->dian_code->value,
                'tax_name' => $tax?->name,
                'tax_rate' => $tax?->rate,
            ];
        }

        return $resolved;
    }

    /**
     * Copies the withholdings of the current company, in the order given.
     *
     * @param  array<int, string>  $ids
     * @return list<array<string, mixed>>
     */
    public function resolveWithholdings(array $ids): array
    {
        $withholdings = Withholding::query()->whereKey($ids)->get()->keyBy('id');
        $resolved = [];

        foreach (array_values($ids) as $id) {
            $withholding = $withholdings->get($id);

            if ($withholding !== null) {
                $resolved[] = [
                    'position' => count($resolved) + 1,
                    'withholding_id' => $withholding->getKey(),
                    'dian_code' => $withholding->dian_code->value,
                    'name' => $withholding->name,
                    'rate' => $withholding->rate,
                ];
            }
        }

        return $resolved;
    }

    /**
     * The line copies already stored on the document.
     *
     * @return list<array<string, mixed>>
     */
    public function storedLines(Document $document): array
    {
        return $document->lines()->get()
            ->map(fn (DocumentLine $line): array => $line->only(self::LINE_FIELDS))
            ->values()
            ->all();
    }

    /**
     * The withholding copies already stored on the document.
     *
     * @return list<array<string, mixed>>
     */
    public function storedWithholdings(Document $document): array
    {
        return $document->withholdings()->get()
            ->map(fn (DocumentWithholding $withholding): array => $withholding->only(self::WITHHOLDING_FIELDS))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $withholdings
     */
    public function calculate(array $lines, array $withholdings): Calculation
    {
        return $this->calculator->calculate(
            array_map(fn (array $line): LineInput => new LineInput(
                quantity: (string) $line['quantity'],
                unitPrice: (string) $line['unit_price'],
                discountRate: (string) $line['discount_rate'],
                taxCode: $line['tax_code'],
                taxRate: $line['tax_rate'] === null ? null : (string) $line['tax_rate'],
            ), $lines),
            array_map(fn (array $withholding): WithholdingInput => new WithholdingInput(
                rate: (string) $withholding['rate'],
                onTax: WithholdingType::from($withholding['dian_code'])->base() === WithholdingBase::Tax,
            ), $withholdings),
        );
    }

    /**
     * Replaces the lines, tax subtotals and withholdings of the document and writes its totals.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $withholdings
     */
    public function apply(Document $document, array $lines, array $withholdings): Calculation
    {
        $calculation = $this->calculate($lines, $withholdings);

        $document->lines()->delete();
        $document->taxes()->delete();
        $document->withholdings()->delete();

        foreach ($lines as $index => $line) {
            $result = $calculation->lines[$index];

            $document->lines()->create([
                ...$line,
                'discount_amount' => (string) $result->discountAmount,
                'line_extension_amount' => (string) $result->lineExtensionAmount,
                'tax_amount' => (string) $result->taxAmount,
            ]);
        }

        foreach ($calculation->taxes as $tax) {
            $document->taxes()->create([
                'dian_code' => $tax->code,
                'name' => TaxType::from($tax->code)->label(),
                'rate' => (string) $tax->rate,
                'taxable_amount' => (string) $tax->taxableAmount,
                'tax_amount' => (string) $tax->taxAmount,
            ]);
        }

        foreach ($withholdings as $index => $withholding) {
            $result = $calculation->withholdings[$index];

            $document->withholdings()->create([
                ...$withholding,
                'base_amount' => (string) $result->baseAmount,
                'amount' => (string) $result->amount,
            ]);
        }

        $document->fill($calculation->totals->toArray())->save();

        return $calculation;
    }
}
