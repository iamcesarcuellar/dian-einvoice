<?php

// The details of the error responses of payments.
return [

    'amount_mismatch' => 'The payment amount must equal the sum applied to its invoices, :total.',
    'exceeds_balance' => 'Invoice :number has :balance left to collect; :amount cannot be applied to it.',
    'zero_amount' => 'The amount to apply must be greater than zero.',
    'no_invoices' => 'Apply the payment to at least one invoice.',
    'already_voided' => 'Payment :number is already voided.',

];
