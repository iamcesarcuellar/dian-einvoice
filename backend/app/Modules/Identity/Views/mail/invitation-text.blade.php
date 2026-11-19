{{-- Plain text body of the invitation email; plain text, so nothing is HTML-escaped. --}}
{!! __('identity::invitation.greeting') !!}

{!! $inviter === null ? __('identity::invitation.intro_anonymous', ['company' => $company, 'app' => $app]) : __('identity::invitation.intro', ['inviter' => $inviter, 'company' => $company, 'app' => $app]) !!}

{!! __('identity::invitation.company') !!}: {!! $company !!}
{!! __('identity::invitation.role') !!}: {!! $role !!}
{!! __('identity::invitation.email') !!}: {!! $email !!}
{!! __('identity::invitation.expires') !!}: {!! __('identity::invitation.expires_value', ['date' => $expires]) !!}

{!! $roleDescription !!}

{!! __('identity::invitation.button') !!}: {!! $url !!}

{!! __('identity::invitation.account') !!}
{!! __('identity::invitation.ignore') !!}

{!! mb_strtoupper(__('identity::invitation.test_title')) !!}: {!! __('identity::invitation.test_notice') !!}

{!! __('identity::invitation.signature', ['app' => $app]) !!}
