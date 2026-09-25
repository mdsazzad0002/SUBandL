@extends('subandl::layout')

@section('title', 'Terms')

@section('content')
    <section class="sb-card">
        <h1>License terms</h1>
        <ol id="sb-terms"></ol>
        <p><a href="{{ $urls['license'] }}">Back to subscription</a></p>
    </section>
@endsection

@push('scripts')
<script type="module">
{!! \SUBandL\Support\Assets::inline('subandl.js') !!}
document.getElementById('sb-terms').append(...TERMS.map((t) => Object.assign(document.createElement('li'), { textContent: t })));
</script>
@endpush
