<?php

// The texts the electronic document chain stores, in the language of the company's documents: the reason a step
// failed, which the timeline shows as it is, and what each transmission attempt recorded.
return [

    'unexpected' => 'Unexpected error: :error',
    'job_failed' => 'the job failed.',
    'generation_failed' => 'Generation failed: :error',
    'signing_failed' => 'Signing failed: :error',
    'attempt_failed' => 'Attempt :attempt of :max: :error',
    'no_response' => 'no response',
    'interrupted' => 'No response was recorded: the worker stopped during the call.',
    'registration_failed' => 'The company could not be registered with the local simulator: :error',

    // What a call to the DIAN web service, here the local simulator, recorded when it got no result.
    'gateway' => [
        'not_connected' => 'Could not connect. :detail',
        'timeout' => 'No response within :seconds s. :detail',
        'closed' => 'The connection closed without a response. :detail',
        'no_result' => 'HTTP :status without a :operation result.',
    ],

];
