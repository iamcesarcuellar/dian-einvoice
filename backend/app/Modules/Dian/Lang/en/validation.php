<?php

// The names of the fields of each request, as validation messages write them, and the messages of their own. A null
// name keeps the framework's: the field's key, its underscores as spaces.
return [

    'attributes' => [
        'settings' => [
            'environment' => null,
            'software_id' => 'software ID',
            'software_pin' => 'software PIN',
            'test_set_id' => 'test set ID',
        ],
        'certificate' => [
            'file' => 'certificate file',
            'password' => null,
        ],
        'simulator' => [
            'mode' => null,
            'rule' => null,
            'delay_ms' => 'delay',
            'failure_rate' => 'failure percentage',
            'count' => 'number of requests',
        ],
    ],

    'messages' => [
        'settings' => [
            'environment.enum' => 'Only the test environment (2) is available. Production is not part of this project.',
            'software_id.uuid' => 'The software ID is the identifier the DIAN assigned, such as 56f2ae4e-9812-4fad-9255-08fcfcd5ccb0.',
            'test_set_id.uuid' => 'The test set ID is the identifier the DIAN assigned to the habilitación test set.',
            'software_pin.regex' => 'The software PIN cannot contain spaces.',
        ],
        'certificate' => [
            'file.extensions' => 'Upload a .p12 or .pfx certificate file.',
        ],
    ],

];
