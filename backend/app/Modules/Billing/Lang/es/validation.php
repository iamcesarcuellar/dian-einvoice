<?php

// The Spanish names of the fields of each request, as validation messages write them, and their own messages.

return [

    'attributes' => [
        // A draft invoice, credit note or debit note.
        'document' => [
            'contact_id' => 'cliente',
            'related_document_id' => 'factura',
            'reason_code' => 'concepto',
            'reason_text' => 'descripción',
            'number_template_id' => 'numeración',
            'payment_form' => 'forma de pago',
            'payment_means_code' => 'medio de pago',
            'due_date' => 'fecha de vencimiento',
            'notes' => 'observaciones',
            'lines' => 'líneas',
            'lines.*' => 'línea',
            'lines.*.item_id' => 'ítem',
            'lines.*.code' => 'código',
            'lines.*.description' => 'descripción',
            'lines.*.unit_code' => 'unidad',
            'lines.*.quantity' => 'cantidad',
            'lines.*.unit_price' => 'precio unitario',
            'lines.*.discount_rate' => 'descuento',
            'lines.*.tax_id' => 'impuesto',
            'withholding_ids' => 'retenciones',
            'withholding_ids.*' => 'retención',
        ],
        'numbering' => [
            'document_type' => 'tipo de documento',
            'prefix' => 'prefijo',
            'from_number' => 'número inicial',
            'to_number' => 'número final',
            'next_number' => 'siguiente número',
            'resolution_number' => 'número de resolución',
            'resolution_date' => 'fecha de la resolución',
            'valid_from' => 'vigente desde',
            'valid_to' => 'vigente hasta',
            'technical_key' => 'clave técnica',
            'is_default' => 'predeterminada',
            'is_active' => 'activa',
        ],
    ],

    'messages' => [
        'numbering' => [
            'prefix.regex' => 'El prefijo tiene hasta 4 letras o dígitos.',
            'technical_key.regex' => 'La clave técnica tiene entre 8 y 128 letras o dígitos.',
        ],
    ],

    'numbering' => [
        'range_order' => 'El número final debe ser mayor o igual que el inicial.',
        'next_outside' => 'El siguiente número debe estar dentro del rango.',
        'validity_order' => 'El fin de la vigencia debe ser posterior a su inicio.',
        'resolution_after' => 'La resolución no puede tener una fecha posterior al inicio de su vigencia.',
        'required' => [
            'resolution_number' => 'El número de resolución es obligatorio para las facturas.',
            'resolution_date' => 'La fecha de la resolución es obligatoria para las facturas.',
            'valid_from' => 'El inicio de la vigencia es obligatorio para las facturas.',
            'valid_to' => 'El fin de la vigencia es obligatorio para las facturas.',
        ],
        'technical_key' => 'La clave técnica es obligatoria para las facturas.',
        'overlap' => 'Este rango se cruza con la numeración :prefix:from a :to.',
    ],

];
