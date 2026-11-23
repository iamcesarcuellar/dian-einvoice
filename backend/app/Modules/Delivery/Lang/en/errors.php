<?php

// The details of the error responses of the delivery to the customer and its files.
return [

    'no_email' => 'The customer has no email address. Enter the address to send the invoice to.',
    'regenerate_unavailable' => 'Regenerate PDF is only available after the PDF failed or when it is missing.',
    'not_accepted' => 'Only invoices accepted by the local simulator are delivered to the customer.',
    'running' => "This invoice's delivery is running right now.",
    'drafts' => 'Drafts are not delivered. Issue the document first.',
    'missing' => [
        'pdf' => 'The PDF of this document is not available yet.',
        'attached_document' => 'The AttachedDocument of this document is not available yet.',
        'package' => 'The zip of this document is not available yet.',
    ],

];
