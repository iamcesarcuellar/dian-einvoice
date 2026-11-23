<?php

// The details of the error responses of invoices, credit and debit notes and their numbering.
return [

    'issued' => [
        'invoice' => 'This invoice is already issued.',
        'credit_note' => 'This credit note is already issued.',
        'debit_note' => 'This debit note is already issued.',
    ],
    'issued_edit' => [
        'invoice' => 'Issued invoices cannot be edited.',
        'credit_note' => 'Issued credit notes cannot be edited.',
        'debit_note' => 'Issued debit notes cannot be edited.',
    ],
    'issued_delete' => [
        'invoice' => 'Issued invoices cannot be deleted.',
        'credit_note' => 'Issued credit notes cannot be deleted.',
        'debit_note' => 'Issued debit notes cannot be deleted.',
    ],

    'zero_total' => 'The total must be greater than zero.',
    'choose_customer' => 'Choose the customer.',
    'inactive_customer' => 'The customer is inactive. Activate it or choose another one.',
    'no_lines' => 'Add at least one line.',
    'describe_line' => 'Describe the line.',
    'quantity' => 'The quantity must be greater than zero.',
    'unit_price' => 'The unit price cannot be negative.',
    'payment_form' => 'Choose the payment form.',
    'payment_means' => 'Choose the payment means.',
    'due_date_required' => 'A credit invoice needs a due date.',
    'due_date_past' => 'The due date cannot be before today.',

    'invoice_not_accepted' => 'Invoice :number has not been accepted by the local simulator, so no note can correct it yet.',
    'invoice_voided' => 'Invoice :number is voided, so no more notes can correct it.',
    'nothing_to_credit' => 'Nothing is left to credit on invoice :number.',
    'choose_concept' => [
        'credit_note' => 'Choose the concept of the credit note.',
        'debit_note' => 'Choose the concept of the debit note.',
    ],
    'describe_correction' => 'Describe the correction in :min to :max characters.',
    'choose_invoice' => 'Choose the issued invoice the note corrects.',
    'credit_exceeds' => 'The credit note is for :amount, more than the :remaining left to credit on invoice :number.',
    'void_mismatch' => 'A credit note that voids invoice :number must be for all that is left to credit, :remaining, or less than one peso below it; this one is for :amount.',
    'void_reason' => 'Void of invoice :number of :date: all that was left to credit, :remaining, is credited.',

    'rate_inactive' => ':name is no longer active. Review the draft and choose another one.',
    'rate_changed' => ':name changed from :from % to :to % since the draft was saved. Review the draft and save it again.',

    'numbering' => [
        'choose' => [
            'invoice' => 'Choose the numbering for the invoice. Add one in Settings → Numbering.',
            'credit_note' => 'Choose the numbering for the credit note. Add one in Settings → Numbering.',
            'debit_note' => 'Choose the numbering for the debit note. Add one in Settings → Numbering.',
        ],
        'wrong_type' => [
            'invoice' => 'This numbering is not for invoices.',
            'credit_note' => 'This numbering is not for credit notes.',
            'debit_note' => 'This numbering is not for debit notes.',
        ],
        'range' => ':prefix :from to :to',
        'inactive' => 'The numbering :name is inactive. Activate it or choose another one.',
        'exhausted' => 'The numbering :name has no numbers left. Add the next range in Settings.',
        'expired' => 'The numbering :name expired on :date. Add the next range in Settings.',
        'upcoming' => 'The numbering :name is valid from :date.',
        'in_use' => 'This numbering is used by documents. Deactivate it instead.',
    ],

    'receipts' => [
        'inactive' => 'The payment receipt numbering is inactive. Activate it in Settings → Numbering.',
        'exhausted' => 'The payment receipt numbering has no numbers left. Add the next range in Settings → Numbering.',
        'expired' => 'The payment receipt numbering expired. Add the next range in Settings → Numbering.',
        'upcoming' => 'The payment receipt numbering is not valid yet.',
        'not_created' => 'The payment receipt numbering could not be created.',
    ],
    'not_payable' => 'Only invoices of the customer of the payment accepted by the local DIAN simulator take payments.',
    'not_payable_number' => 'Only invoices of the customer of the payment accepted by the local DIAN simulator take payments; :number is not one.',

    'no_xml' => 'Drafts have no XML. Issue the document first.',
    'xml_pending' => 'The signed XML of this document is not available yet.',
    'no_response' => 'The local DIAN simulator has not responded for this document yet.',

];
