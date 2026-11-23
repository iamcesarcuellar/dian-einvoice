<?php

// The texts the delivery to the customer stores, in the language of the company's documents: the reason a step
// failed, which the timeline shows as it is, and what each email attempt recorded.
return [

    'unexpected' => 'Unexpected error: :error',
    'job_failed' => 'the job failed.',
    'attached_failed' => 'The AttachedDocument could not be built: :error',
    'pdf_failed' => 'The PDF could not be generated: :error',
    'zip_failed' => 'The zip could not be built: :error',
    'attempt_failed' => 'Attempt :attempt of :max: :error',
    'no_outcome' => 'no outcome',
    'interrupted' => 'No outcome was recorded: the worker stopped during the call.',

    // Why the mail server did not take an email.
    'mailer' => [
        'invalid_address' => 'The address is not valid: :error',
        'server_returned' => 'The mail server returned :code: :error',
        'unreachable' => 'The mail server could not be reached: :error',
        'unexpected' => 'Unexpected error: :error',
    ],

];
