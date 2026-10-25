<x-filament-panels::page>
    @if ($validationError)
        <p role="alert">{{ $validationError }} Update the approved port pools in plugin settings.</p>
    @else
        @include('pelican-pangolin::infrastructure-blocks')
    @endif
</x-filament-panels::page>
