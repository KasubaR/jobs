@php
    $labels = ['new' => 'New', 'processing' => 'Processing', 'published' => 'Published', 'expired' => 'Expired', 'closed' => 'Closed', 'rejected' => 'Rejected', 'duplicate' => 'Duplicate'];
    $class = ['new' => 'warn', 'processing' => 'warn', 'published' => '', 'expired' => 'mut', 'closed' => 'mut', 'rejected' => 'bad', 'duplicate' => 'mut'][$status] ?? 'mut';
@endphp
<span class="pill {{ $class }}">{{ $labels[$status] ?? ucfirst($status) }}</span>
