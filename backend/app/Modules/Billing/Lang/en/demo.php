<?php

// SYNTHETIC DEMO DATA: the free lines, the descriptions of the demo notes and the reason of the voided payment.
return [

    'lines' => [
        'export_documentation' => 'Export documentation handling',
        'late_interest' => 'Late payment interest, ten days past the due date',
    ],

    'notes' => [
        'paper_discount' => 'Discount of 10 % on the twenty-five packs of paper, agreed for a late delivery.',
        'wrong_customer' => 'The invoice was issued to the wrong customer, so it is voided in full.',
        'damaged_return' => 'Return of one office chair and five packs of paper that arrived damaged.',
        'late_interest' => 'Late payment interest: the invoice was paid ten days after its due date.',
        'chair_refused' => 'Return of one office chair that the customer did not accept on delivery.',
        'fewer_guests' => 'Five fewer guests than the forty invoiced attended the corporate lunch.',
        'consulting_rebate' => 'Rebate of 10 % on the consulting hours, agreed after the review of the project.',
    ],

    'voids' => [
        'returned_check' => 'The bank returned the check for insufficient funds.',
    ],

];
