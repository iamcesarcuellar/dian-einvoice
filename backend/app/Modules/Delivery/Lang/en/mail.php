<?php

// Email that delivers the invoice, or one of its notes, to the customer. The subject follows annex 1.9, section 9.1, and has no text to translate.
return [

    'preheader' => ':company sent you :document :number.',
    'greeting' => 'Hello,',
    'intro' => ':company sent you :document :number.',
    'documents' => [
        'invoice' => 'electronic sales invoice',
        'credit_note' => 'credit note',
        'debit_note' => 'debit note',
    ],
    'company' => 'Issued by',
    'numbers' => [
        'invoice' => 'Invoice',
        'credit_note' => 'Credit note',
        'debit_note' => 'Debit note',
    ],
    'total' => 'Total',
    'due_date' => 'Due date',
    'cash' => 'Cash payment, no due date',
    'corrects' => 'Corrects invoice',
    'attachment' => 'The attached zip file :file holds the AttachedDocument (the signed document with its validation) and its PDF.',
    'reply' => 'Reply to this email to reach :company.',
    'test_title' => 'Test document',
    'test_notice' => 'This document was issued in the DIAN habilitación (test) environment and validated by a local simulator, not by the DIAN. It has no tax validity. The email was caught by a local development mailbox and did not leave the computer that sent it.',
    'signature' => 'Sent by :company with dian-einvoice.',

];
