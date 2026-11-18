<?php

// Email that delivers the invoice to the customer. The subject follows annex 1.9, section 9.1, and has no text to translate.
return [

    'preheader' => ':company sent you invoice :number.',
    'greeting' => 'Hello,',
    'intro' => ':company sent you electronic sales invoice :number.',
    'company' => 'Issued by',
    'number' => 'Invoice',
    'total' => 'Total',
    'due_date' => 'Due date',
    'cash' => 'Cash payment, no due date',
    'attachment' => 'The attached zip file :file holds the AttachedDocument (the signed invoice with its validation) and the PDF of the invoice.',
    'reply' => 'Reply to this email to reach :company.',
    'test_title' => 'Test document',
    'test_notice' => 'This invoice was issued in the DIAN habilitación (test) environment and validated by a local simulator, not by the DIAN. It has no tax validity. The email was caught by a local development mailbox and did not leave the computer that sent it.',
    'signature' => 'Sent by :company with dian-einvoice.',

];
