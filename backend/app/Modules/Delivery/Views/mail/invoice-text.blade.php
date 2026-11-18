{{-- Plain text body of the delivery email; plain text, so nothing is HTML-escaped. --}}
{!! __('delivery::mail.greeting') !!}

{!! __('delivery::mail.intro', ['company' => $company, 'document' => $document, 'number' => $number]) !!}

{!! __('delivery::mail.company') !!}: {!! $company !!}
{!! $numberLabel !!}: {!! $number !!}
{!! __('delivery::mail.total') !!}: {!! $total !!}
@if ($corrects !== null)
{!! __('delivery::mail.corrects') !!}: {!! $corrects !!}
@else
{!! __('delivery::mail.due_date') !!}: {!! $dueDate ?? __('delivery::mail.cash') !!}
@endif

{!! __('delivery::mail.attachment', ['file' => $packageName]) !!}
{!! __('delivery::mail.reply', ['company' => $company]) !!}

{!! mb_strtoupper(__('delivery::mail.test_title')) !!}: {!! __('delivery::mail.test_notice') !!}

{!! __('delivery::mail.signature', ['company' => $company]) !!}
