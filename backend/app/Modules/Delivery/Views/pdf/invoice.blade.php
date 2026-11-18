{{-- The graphic representation of an invoice, as a whole HTML document for dompdf. Data: $r, an InvoiceRepresentation built from the frozen copies, and $qr, the QR code as base64 SVG. --}}
@php
    $issuerContact = array_filter([$r->company['phone'], $r->company['email']]);
    $issuerAddress = array_filter([$r->company['address'], $r->company['city']]);
    $customerLines = array_filter([$r->customer['address'], $r->customer['city'], $r->customer['email'], $r->customer['phone']]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $r->metadata['title'] }}</title>
    <style>
        @include('delivery::pdf.styles')
    </style>
</head>
<body>
    @include('delivery::pdf.header')
    @include('delivery::pdf.footer')

    <table>
        <tr>
            <td class="masthead" style="width: 62%;">
                <div class="issuer-name">{{ $r->company['legal_name'] }}</div>
                <div class="issuer-line">{{ $r->company['nit'] }}</div>
                @if ($issuerAddress !== [])
                    <div class="issuer-line">{{ implode(' · ', $issuerAddress) }}</div>
                @endif
                @if ($issuerContact !== [])
                    <div class="issuer-line">{{ implode(' · ', $issuerContact) }}</div>
                @endif
                <div class="regime">{{ $r->company['regime'] }}</div>
            </td>
            <td class="masthead" style="width: 38%;">
                <div class="doc-label">{{ $r->title }}</div>
                <div class="doc-number">{{ $r->number }}</div>
                <div class="doc-test">{{ $r->testMark }}</div>
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td class="party" style="width: 50%;">
                <div class="label">{{ __('delivery::pdf.bill_to') }}</div>
                <div class="party-name">{{ $r->customer['name'] }}</div>
                <div>{{ $r->customer['identification'] }}</div>
                @foreach ($customerLines as $line)
                    <div class="muted">{{ $line }}</div>
                @endforeach
            </td>
            <td class="facts" style="width: 50%;">
                <table>
                    @foreach ($r->details as $detail)
                        <tr>
                            <td class="fact-label" style="width: 34%;"><span class="label">{{ $detail['label'] }}</span></td>
                            <td class="fact-value">{{ $detail['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th class="col" style="width: 4%;">{{ __('delivery::pdf.columns.position') }}</th>
                <th class="col" style="width: 9%;">{{ __('delivery::pdf.columns.code') }}</th>
                <th class="col">{{ __('delivery::pdf.columns.description') }}</th>
                <th class="col-r" style="width: 6%;">{{ __('delivery::pdf.columns.quantity') }}</th>
                <th class="col" style="width: 7%;">{{ __('delivery::pdf.columns.unit') }}</th>
                <th class="col-r" style="width: 15%;">{{ __('delivery::pdf.columns.unit_price') }}</th>
                <th class="col-r" style="width: 6%;">{{ __('delivery::pdf.columns.discount') }}</th>
                <th class="col" style="width: 10%;">{{ __('delivery::pdf.columns.tax') }}</th>
                <th class="col-r" style="width: 16%;">{{ __('delivery::pdf.columns.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($r->lines as $line)
                <tr class="line">
                    <td class="cell-muted">{{ $line['position'] }}</td>
                    <td class="cell-muted">{{ $line['code'] }}</td>
                    <td class="cell">{{ $line['description'] }}</td>
                    <td class="cell-r">{{ $line['quantity'] }}</td>
                    <td class="cell">{{ $line['unit'] }}</td>
                    <td class="cell-r">{{ $line['unit_price'] }}</td>
                    <td class="cell-r">{{ $line['discount'] }}</td>
                    <td class="cell">{{ $line['tax'] }}</td>
                    <td class="cell-r">{{ $line['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <table>
            <tr>
                <td class="words" style="width: 50%;">
                    <div class="label">{{ __('delivery::pdf.in_words') }}</div>
                    <div class="words-text">{{ $r->amountInWords }}</div>
                    @if ($r->notes !== null)
                        <div class="notes">
                            <div class="label">{{ __('delivery::pdf.notes') }}</div>
                            <div class="notes-text">{!! nl2br(e($r->notes)) !!}</div>
                        </div>
                    @endif
                </td>
                <td style="width: 50%;">
                    <table>
                        @foreach ($r->totals as $row)
                            @php
                                $suffix = match ($row['kind']) {
                                    'total' => '-total',
                                    'net' => '-net',
                                    default => '',
                                };
                            @endphp
                            <tr>
                                <td class="t-label{{ $suffix }}" style="width: 56%;">
                                    {{ $row['label'] }}
                                    @if ($row['detail'] !== null)
                                        <br><span class="t-detail">{{ $row['detail'] }}</span>
                                    @endif
                                </td>
                                <td class="t-value{{ $suffix }}">{{ $row['kind'] === 'deduction' ? '− ' : '' }}{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
