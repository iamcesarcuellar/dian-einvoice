<?php

// The details of the error responses of accounts, companies, teams and invitations.
return [

    'not_registered' => 'We could not create an account with these details. If you already have an account, sign in or reset your password; if your company already uses this platform, ask one of its owners to invite you.',
    'session_only' => 'Session sign-in is only available to the web app.',
    'too_many_sign_ins' => 'Too many sign-in attempts. Try again in :seconds seconds.',
    'link_sent' => 'If an account uses this address, a link to choose a new password is on its way. It works once, for 60 minutes.',
    'invalid_link' => 'This password reset link is no longer valid: it was used, it expired or it belongs to another address. Ask for a new one.',
    'password_changed' => 'Your password was changed. Sign in with it.',

    'other_company_key' => "This API key belongs to another company. Leave out X-Company-Id, or send the key's company.",
    'read_only_key' => 'The API key :key is read-only: it cannot write.',
    'missing_company' => 'Send the current company in the X-Company-Id header.',
    'not_member' => 'You are not a member of this company.',
    'role_forbids' => 'Your role in this company does not allow this action.',
    'key_settings' => 'API keys cannot change settings: an owner or admin changes them in the web app.',
    'key_forbids' => 'This API key does not allow this action.',
    'edit_company' => 'Only owners and admins can edit this company.',

    'team' => [
        'last_owner_role' => 'The company needs at least one owner. Make another member an owner first.',
        'remove_self' => 'You cannot remove yourself from the company. Another owner or admin can do it.',
        'remove_last_owner' => 'The company needs at least one owner, so its last owner cannot be removed.',
        'grant_owner' => 'Only an owner can make someone an owner.',
        'manage_owner' => 'Only an owner can change or remove an owner.',
    ],

    'invitations' => [
        'member' => ':email already belongs to this company.',
        'pending' => ':email already has a pending invitation to this company; resend it instead.',
        'resend_accepted' => 'This invitation was already accepted.',
        'resend_canceled' => 'This invitation was canceled; send a new one instead.',
        'resend_owner' => 'Only an owner can resend an invitation to become an owner.',
        'resend_member' => ':email already belongs to this company; cancel this invitation instead.',
        'cancel_accepted' => 'This invitation was already accepted; remove the member instead.',
        'expired' => 'This invitation has expired. Ask the company to send it again.',
        'canceled' => 'This invitation was canceled.',
        'accepted' => 'This invitation has already been accepted.',
        'account_exists' => 'An account for :email exists now. Sign in to accept the invitation.',
        'web_app_only' => 'Invitations are accepted in the web app.',
        'sign_in_as' => 'Sign in as :email to accept this invitation.',
        'other_user' => 'This invitation is for :email. Sign out, then sign in as :email to accept it.',
    ],

];
