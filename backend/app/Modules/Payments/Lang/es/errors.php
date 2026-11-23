<?php

// The details of the error responses of payments, in Spanish.

return [

    'amount_mismatch' => 'El valor del pago debe ser igual a la suma aplicada a sus facturas, :total.',
    'exceeds_balance' => 'A la factura :number le quedan :balance por cobrar; no se le pueden aplicar :amount.',
    'zero_amount' => 'El valor que vas a aplicar debe ser mayor que cero.',
    'no_invoices' => 'Aplica el pago al menos a una factura.',
    'already_voided' => 'El pago :number ya está anulado.',

];
