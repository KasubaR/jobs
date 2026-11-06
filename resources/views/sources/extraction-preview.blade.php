@php
    use App\Extraction\Extractor;
    $labels = ['employer' => 'Employer'] + Extractor::FIELDS;
@endphp
<div style="font-size:13px">
    @if ($preview['problems'])
        <div class="alert bad">
            <b>Fix these first:</b>
            <ul>@foreach ($preview['problems'] as $problem)<li>{{ $problem }}</li>@endforeach</ul>
        </div>
    @elseif ($preview['total'] === 0)
        <p>This source has no stored ads yet, so there is nothing to try the rules on.</p>
    @else
        <p class="mut">Tried on the {{ $preview['total'] }} most recent stored ads. Nothing was fetched from the website.</p>
        <div class="btns" style="margin:10px 0">
            @foreach ($preview['coverage'] as $field => $count)
                <span class="pill mut">{{ $labels[$field] ?? $field }}: <b>{{ $count }}/{{ $preview['total'] }}</b></span>
            @endforeach
        </div>
        <div class="tw">
            <table class="tbl">
                <thead>
                    <tr><th>Title</th><th>Employer</th>@foreach (Extractor::FIELDS as $label)<th>{{ $label }}</th>@endforeach</tr>
                </thead>
                <tbody>
                @foreach ($preview['rows'] as $row)
                    <tr>
                        <td>
                            <a href="{{ preg_match('~^https?://~i', $row['url']) ? $row['url'] : '#' }}" target="_blank" rel="noopener noreferrer">{{ $row['title'] }}</a>
                            @foreach ($row['warnings'] as $warning)<div class="mut" style="color:var(--warn);font-size:12px">{{ $warning }}</div>@endforeach
                        </td>
                        <td>{{ $row['employer'] ?? '-' }}</td>
                        @foreach (array_keys(Extractor::FIELDS) as $field)<td>{{ $row['values'][$field] ?? '-' }}</td>@endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
