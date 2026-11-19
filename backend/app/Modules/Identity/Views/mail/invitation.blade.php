{{-- HTML body of the invitation email. Data: app, company, inviter (null when unknown), email, role, roleDescription, expires, url. --}}
@php
    $intro = $inviter === null
        ? __('identity::invitation.intro_anonymous', ['company' => $company, 'app' => $app])
        : __('identity::invitation.intro', ['inviter' => $inviter, 'company' => $company, 'app' => $app]);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $intro }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #18181b;">
    <span style="display: none; max-height: 0; overflow: hidden;">{{ __('identity::invitation.preheader', ['company' => $company, 'app' => $app, 'role' => $role]) }}</span>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 8px;">
                    <tr>
                        <td style="padding: 28px 32px 8px 32px; font-size: 15px; line-height: 1.6;">
                            <p style="margin: 0 0 12px 0;">{{ __('identity::invitation.greeting') }}</p>
                            <p style="margin: 0 0 20px 0;">{{ $intro }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; line-height: 1.5; border-top: 1px solid #e4e4e7;">
                                <tr>
                                    <td style="padding: 10px 0; color: #71717a;">{{ __('identity::invitation.company') }}</td>
                                    <td style="padding: 10px 0; text-align: right;">{{ $company }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; color: #71717a; border-top: 1px solid #f4f4f5;">{{ __('identity::invitation.role') }}</td>
                                    <td style="padding: 10px 0; text-align: right; border-top: 1px solid #f4f4f5; font-weight: 600;">{{ $role }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; color: #71717a; border-top: 1px solid #f4f4f5;">{{ __('identity::invitation.email') }}</td>
                                    <td style="padding: 10px 0; text-align: right; border-top: 1px solid #f4f4f5;">{{ $email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; color: #71717a; border-top: 1px solid #f4f4f5;">{{ __('identity::invitation.expires') }}</td>
                                    <td style="padding: 10px 0; text-align: right; border-top: 1px solid #f4f4f5; font-variant-numeric: tabular-nums;">{{ __('identity::invitation.expires_value', ['date' => $expires]) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 32px 0 32px; font-size: 14px; line-height: 1.6; color: #3f3f46;">
                            <p style="margin: 0;">{{ $roleDescription }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px 8px 32px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius: 6px; background-color: #4f46e5;">
                                        <a href="{{ $url }}" style="display: inline-block; padding: 11px 20px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 6px;">{{ __('identity::invitation.button') }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 32px 0 32px; font-size: 13px; line-height: 1.6; color: #52525b;">
                            <p style="margin: 0 0 4px 0;">{{ __('identity::invitation.link') }}</p>
                            <p style="margin: 0 0 12px 0; word-break: break-all;"><a href="{{ $url }}" style="color: #4f46e5;">{{ $url }}</a></p>
                            <p style="margin: 0 0 12px 0;">{{ __('identity::invitation.account') }}</p>
                            <p style="margin: 0;">{{ __('identity::invitation.ignore') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px 28px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; border-left: 3px solid #4f46e5; border-radius: 4px;">
                                <tr>
                                    <td style="padding: 12px 14px; font-size: 13px; line-height: 1.55; color: #3f3f46;">
                                        <strong style="color: #4f46e5; text-transform: uppercase; letter-spacing: 0.06em; font-size: 12px;">{{ __('identity::invitation.test_title') }}</strong><br>
                                        {{ __('identity::invitation.test_notice') }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <p style="margin: 16px 0 0 0; font-size: 12px; color: #71717a;">{{ __('identity::invitation.signature', ['app' => $app]) }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
