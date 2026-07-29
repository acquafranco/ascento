@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'space-y-0.5']) }}>
        @foreach ((array) $messages as $message)
            <li class="flex items-start gap-1.5 text-xs font-medium text-red-600">
                <svg width="13" height="13" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M8 5V8.5M8 11H8.01" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                {{ $message }}
            </li>
        @endforeach
    </ul>
@endif
