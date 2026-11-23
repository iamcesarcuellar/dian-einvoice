<?php

// Email that invites someone to join a company. Spanish arrives in the Polish phase.
return [

    'subject' => ':inviter invited you to :company on :app',
    'subject_anonymous' => 'You are invited to :company on :app',
    'preheader' => 'Join :company on :app as :role.',
    'greeting' => 'Hello,',
    'intro' => ':inviter invited you to join :company on :app, its electronic invoicing platform.',
    'intro_anonymous' => 'You are invited to join :company on :app, its electronic invoicing platform.',
    'company' => 'Company',
    'role' => 'Role',
    'email' => 'Invited address',
    'expires' => 'Link valid until',
    'expires_value' => ':date (Colombian time)',
    'button' => 'Accept the invitation',
    'link' => 'If the button does not work, copy this link into your browser:',
    'account' => 'If you have no account yet, you will choose your name and password when you accept.',
    'ignore' => 'If you did not expect this invitation, ignore this email: nothing happens until the link is used.',
    'roles' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'member' => 'Member',
        'viewer' => 'Viewer',
    ],
    'role_descriptions' => [
        'owner' => 'Owners manage everything in the company, including its team and its owners.',
        'admin' => 'Admins manage everything in the company, including its team, except its owners.',
        'member' => 'Members manage contacts, items and documents, and record payments.',
        'viewer' => 'Viewers see everything and change nothing.',
    ],
    'test_title' => 'Test environment',
    'test_notice' => 'This platform runs locally with synthetic data. A local development mailbox caught this email, and it never left the computer that sent it.',
    'signature' => 'Sent by :app.',

];
