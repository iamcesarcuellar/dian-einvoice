<?php

// The names of the fields of each request, as validation messages write them, and the messages of their own. A null
// name keeps the framework's: the field's key, its underscores as spaces (the index of a line included).
return [

    'attributes' => [
        // A draft invoice, credit note or debit note.
        'document' => [
            'contact_id' => 'customer',
            'related_document_id' => 'invoice',
            'reason_code' => 'concept',
            'reason_text' => 'description',
            'number_template_id' => 'numbering',
            'payment_form' => 'payment form',
            'payment_means_code' => 'payment means',
            'due_date' => null,
            'notes' => null,
            'lines' => null,
            'lines.*' => null,
            'lines.*.item_id' => 'item',
            'lines.*.code' => null,
            'lines.*.description' => 'description',
            'lines.*.unit_code' => 'unit',
            'lines.*.quantity' => 'quantity',
            'lines.*.unit_price' => 'unit price',
            'lines.*.discount_rate' => 'discount',
            'lines.*.tax_id' => 'tax',
            'withholding_ids' => null,
            'withholding_ids.*' => 'withholding',
        ],
        'numbering' => [
            'document_type' => null,
            'prefix' => null,
            'from_number' => null,
            'to_number' => null,
            'next_number' => null,
            'resolution_number' => null,
            'resolution_date' => null,
            'valid_from' => null,
            'valid_to' => null,
            'technical_key' => null,
            'is_default' => null,
            'is_active' => null,
        ],
    ],

    'messages' => [
        'numbering' => [
            'prefix.regex' => 'The prefix has up to 4 letters or digits.',
            'technical_key.regex' => 'The technical key has between 8 and 128 letters or digits.',
        ],
    ],

    'numbering' => [
        'range_order' => 'The last number must be greater than or equal to the first.',
        'next_outside' => 'The next number must be within the range.',
        'validity_order' => 'The end of the validity must be after its start.',
        'resolution_after' => 'The resolution cannot be dated after the start of its validity.',
        'required' => [
            'resolution_number' => 'The resolution number is required for invoices.',
            'resolution_date' => 'The date of the resolution is required for invoices.',
            'valid_from' => 'The start of the validity is required for invoices.',
            'valid_to' => 'The end of the validity is required for invoices.',
        ],
        'technical_key' => 'The technical key is required for invoices.',
        'overlap' => 'This range overlaps the numbering :prefix:from to :to.',
    ],

];
