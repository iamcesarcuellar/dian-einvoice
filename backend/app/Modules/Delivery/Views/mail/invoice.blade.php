{{-- HTML body of the delivery email. Data: company, document, numberLabel, number, total, dueDate (null for cash), corrects (the invoice of a note, else null), packageName. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('delivery::mail.intro', ['company' => $company, 'document' => $document, 'number' => $number]) }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #18181b;">
    <span style="display: none; max-height: 0; overflow: hidden;">{{ __('delivery::mail.preheader', ['company' => $company, 'document' => $document, 'number' => $number]) }}</span>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 8px;">
                    <tr>
                        <td style="padding: 28px 32px 8px 32px; font-size: 15px; line-height: 1.6;">
                            <p style="margin: 0 0 12px 0;">{{ __('delivery::mail.greeting') }}</p>
                            <p style="margin: 0 0 20px 0;">{{ __('delivery::mail.intro', ['company' => $company, 'document' => $document, 'number' => $number]) }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; line-height: 1.5; border-top: 1px solid #e4e4e7;">
                                <tr>
                                    <td style="padding: 10px 0; color: #71717a;">{{ __('delivery::mail.company') }}</td>
                                    <td style="padding: 10px 0; text-align: right;">{{ $company }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; color: #71717a; border-top: 1px solid #f4f4f5;">{{ $numberLabel }}</td>
                                    <td style="padding: 10px 0; text-align: right; border-top: 1px solid #f4f4f5; font-variant-numeric: tabular-nums;">{{ $number }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; color: #71717a; border-top: 1px solid #f4f4f5;">{{ __('delivery::mail.total') }}</td>
                                    <td style="padding: 10px 0; text-align: right; border-top: 1px solid #f4f4f5; font-weight: 600; font-variant-numeric: tabular-nums;">{{ $total }}</td>
                                </tr>
                                <tr>
                                    @if ($corrects !== null)
                                        <td style="padding: 10px 0; color: #71717a; border-top: 1px solid #f4f4f5;">{{ __('delivery::mail.corrects') }}</td>
                                        <td style="padding: 10px 0; text-align: right; border-top: 1px solid #f4f4f5; font-variant-numeric: tabular-nums;">{{ $corrects }}</td>
                                    @else
                                        <td style="padding: 10px 0; color: #71717a; border-top: 1px solid #f4f4f5;">{{ __('delivery::mail.due_date') }}</td>
                                        <td style="padding: 10px 0; text-align: right; border-top: 1px solid #f4f4f5; font-variant-numeric: tabular-nums;">{{ $dueDate ?? __('delivery::mail.cash') }}</td>
                                    @endif
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px 0 32px; font-size: 14px; line-height: 1.6; color: #3f3f46;">
                            <p style="margin: 0 0 12px 0;">{{ __('delivery::mail.attachment', ['file' => $packageName]) }}</p>
                            <p style="margin: 0;">{{ __('delivery::mail.reply', ['company' => $company]) }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px 28px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; border-left: 3px solid #4f46e5; border-radius: 4px;">
                                <tr>
                                    <td style="padding: 12px 14px; font-size: 13px; line-height: 1.55; color: #3f3f46;">
                                        <strong style="color: #4f46e5; text-transform: uppercase; letter-spacing: 0.06em; font-size: 12px;">{{ __('delivery::mail.test_title') }}</strong><br>
                                        {{ __('delivery::mail.test_notice') }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <p style="margin: 16px 0 0 0; font-size: 12px; color: #71717a;">{{ __('delivery::mail.signature', ['company' => $company]) }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
