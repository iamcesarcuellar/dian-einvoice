{{-- Plain text body of the password reset email; plain text, so nothing is HTML-escaped. --}}
{!! __('identity::password_reset.greeting', ['name' => $name]) !!}

{!! __('identity::password_reset.intro', ['app' => $app, 'email' => $email]) !!}

{!! __('identity::password_reset.button') !!}: {!! $url !!}

{!! __('identity::password_reset.expires', ['minutes' => $minutes]) !!}
{!! __('identity::password_reset.ignore') !!}

{!! mb_strtoupper(__('identity::password_reset.test_title')) !!}: {!! __('identity::password_reset.test_notice') !!}

{!! __('identity::password_reset.signature', ['app' => $app]) !!}
