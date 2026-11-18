{{-- Running footer of every page (a fixed element): QR code (annex 1.9, 11.7), CUFE or CUDE, validation, numbering authorization and software. The page number is drawn below it by the renderer. --}}
<div class="running-footer">
    <table>
        <tr>
            <td class="foot-qr"><img src="data:image/svg+xml;base64,{{ $qr }}" alt="{{ __('delivery::pdf.qr_label') }}" style="width: 25mm; height: 25mm;"></td>
            <td class="foot-text">
                <div class="label">{{ $r->footer['key_label'] }}</div>
                <div class="cufe">{{ $r->footer['cufe'] }}</div>
                <div class="validation">{{ $r->footer['validation'] }}</div>
                <div>{{ $r->footer['authorization'] }}</div>
                <div>{{ $r->footer['representation'] }} {{ $r->footer['software'] }}</div>
            </td>
        </tr>
    </table>
</div>
