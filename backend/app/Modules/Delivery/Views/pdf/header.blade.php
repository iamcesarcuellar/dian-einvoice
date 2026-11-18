{{-- Running header of every page (a fixed element): the visible test mark and the document. --}}
<div class="running-header">
    <table>
        <tr>
            <td class="band"><span class="band-mark">{{ $r->testMark }}</span>&nbsp;&nbsp;{{ $r->testNotice }}</td>
            <td class="band-ref" style="width: 34%;">{{ $r->title }}&nbsp;&nbsp;<b>{{ $r->number }}</b></td>
        </tr>
    </table>
</div>
