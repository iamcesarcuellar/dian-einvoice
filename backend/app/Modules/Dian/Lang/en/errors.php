<?php

// The details of the error responses of the DIAN settings, the signing certificate, the local simulator and the
// actions on an electronic document.
return [

    'attempt_running' => 'An attempt is running right now.',
    'retry_unavailable' => 'Retry now is only available while a transmission waits for its next attempt, or after the document failed.',
    'resend_unavailable' => 'Resend is only available after the local simulator rejected the document.',
    'resend_settings' => 'Complete the software ID and PIN in Settings → DIAN before resending.',
    'unknown_rule' => 'The local simulator does not implement this rule.',
    'simulator_down' => 'The local DIAN simulator is not responding. Start it with make up.',
    'simulator_unreachable' => 'The local DIAN simulator is not responding.',
    'simulator_http' => 'The local DIAN simulator returned HTTP :status: :error',
    'no_details' => 'no details',

    'certificate' => [
        'password' => 'The file could not be opened with this password. Check the password, and that the file is a .p12 or .pfx certificate.',
        'contents' => 'The file must contain the private key and its certificate.',
        'rsa' => 'The private key must be an RSA key.',
        'pair' => 'The private key does not belong to the certificate.',
    ],

    // What issuing needs and is missing, by the screen where each item is completed.
    'requirements' => [
        'company' => [
            'legal_name' => 'Add the legal name of the company.',
            'identification_number' => 'Add the NIT of the company.',
            'organization_type' => 'Choose the organization type of the company.',
            'tax_scheme' => 'Choose the tax scheme of the company.',
            'fiscal_responsibilities' => 'Choose the fiscal responsibilities of the company.',
            'address' => 'Add the address of the company.',
            'municipality_code' => 'Choose the municipality of the company.',
            'email' => 'Add the billing email of the company.',
        ],
        'software_id' => 'Add the software ID in the DIAN settings.',
        'software_pin' => 'Add the software PIN in the DIAN settings.',
        'technical_key' => 'Add the technical key of the numbering :numbering.',
        'resolution_date' => 'Add the date of the resolution of the numbering :numbering.',
        'customer_identification' => 'Add the identification of the customer.',
        'certificate' => 'Add a signing certificate in the DIAN settings.',
        'certificate_expired' => 'The signing certificate expired on :date. Upload a valid one in the DIAN settings.',
        'certificate_upcoming' => 'The signing certificate is valid from :date. Use one that is valid today.',
    ],

];
