@php
    use App\Extraction\Extractor;
    $labels = ['employer' => 'Employer'] + Extractor::FIELDS;
@endphp

<div class="space-y-4 text-sm">
    @if ($preview['problems'])
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
            <p class="font-medium">Fix these first:</p>
            <ul class="list-disc pl-5">
                @foreach ($preview['problems'] as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @elseif ($preview['total'] === 0)
        <p>This source has no stored ads yet, so there is nothing to try the rules on.</p>
    @else
        <p class="text-gray-600 dark:text-gray-400">
            Tried on the {{ $preview['total'] }} most recent stored ads. Nothing was fetched from the website.
        </p>

        <div class="flex flex-wrap gap-2">
            @foreach ($preview['coverage'] as $field => $count)
                <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-white/10">
                    {{ $labels[$field] ?? $field }}: <strong>{{ $count }}/{{ $preview['total'] }}</strong>
                </span>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-1 pr-3">Title</th>
                        <th class="py-1 pr-3">Employer</th>
                        @foreach (Extractor::FIELDS as $field => $label)
                            <th class="py-1 pr-3">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview['rows'] as $row)
                        <tr class="border-b border-gray-100 align-top dark:border-white/5">
                            <td class="py-1 pr-3">
                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline">{{ $row['title'] }}</a>
                                @foreach ($row['warnings'] as $warning)
                                    <div class="text-xs text-warning-600">{{ $warning }}</div>
                                @endforeach
                            </td>
                            <td class="py-1 pr-3">{{ $row['employer'] ?? '-' }}</td>
                            @foreach (Extractor::FIELDS as $field => $label)
                                <td class="py-1 pr-3">{{ $row['values'][$field] ?? '-' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
