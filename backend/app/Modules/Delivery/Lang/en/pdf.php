<?php

// Graphic representation of the invoice and its notes (PDF). Proper terms of the DIAN (NIT, CUFE, CUDE, IVA, DIAN) stay as they are.
return [

    'titles' => [
        'invoice' => 'Electronic sales invoice',
        'credit_note' => 'Credit note',
        'debit_note' => 'Debit note',
    ],
    'test_mark' => 'Test document',
    'test_notice' => 'DIAN habilitación (test) environment · validated by a local simulator, not by the DIAN · no tax validity',
    'watermark' => 'TEST DOCUMENT',

    'nit_value' => 'NIT :nit',
    'regime' => 'Tax scheme: :scheme · Fiscal responsibilities: :responsibilities',

    'bill_to' => 'Bill to',
    'invoice_details' => 'Invoice details',
    'issued' => 'Issued',
    'issued_value' => ':date, :time (UTC:offset)',
    'due_date' => 'Due date',
    'no_due_date' => 'None (cash)',
    'payment_form' => 'Payment form',
    'payment_means' => 'Payment means',

    'columns' => [
        'position' => '#',
        'code' => 'Code',
        'description' => 'Description',
        'quantity' => 'Qty',
        'unit' => 'Unit',
        'unit_price' => 'Unit price',
        'discount' => 'Disc.',
        'tax' => 'Tax',
        'total' => 'Total',
    ],
    'excluded' => 'Excluded',

    'subtotal' => 'Subtotal',
    'discounts' => 'Discounts',
    'on' => 'on :base',
    'rate_on' => ':rate on :base',
    'total' => 'Total',
    'net' => 'Net to receive',
    'in_words' => 'Total in words',
    'words' => ':words Colombian pesos',
    'words_cents' => ':words Colombian pesos and :cents/100',
    'notes' => 'Notes',

    'reference' => [
        'title' => 'Corrected invoice',
        'invoice' => 'Electronic sales invoice :number, dated :date',
        'cufe' => 'Invoice CUFE',
        'concept' => 'Concept :code · :name',
    ],

    'validation' => 'Validated on :date at :time by this project\'s local DIAN simulator, a development tool. The DIAN did not validate this document.',
    'authorization' => 'Numbering authorized by DIAN resolution :number, dated :date: prefix :prefix, numbers :from to :to, valid from :valid_from to :valid_to.',
    'authorization_no_prefix' => 'Numbering authorized by DIAN resolution :number, dated :date: numbers :from to :to, valid from :valid_from to :valid_to.',
    'note_numbering' => 'The issuer\'s own numbering for this type of note, without a DIAN resolution: prefix :prefix, numbers :from to :to.',
    'representations' => [
        'invoice' => 'Graphic representation of the electronic sales invoice.',
        'credit_note' => 'Graphic representation of a credit note to an electronic sales invoice.',
        'debit_note' => 'Graphic representation of a debit note to an electronic sales invoice.',
    ],
    'software' => 'Software: the issuer\'s own software, :name, NIT :nit.',
    'page' => 'Page :page of :pages',
    'qr_label' => 'Verification QR code',

    'metadata_title' => ':title :number (test document)',
    'metadata_subject' => 'Graphic representation of a :title. A test document validated by a local simulator, with no tax validity.',

];
