@extends('subandl::layout')

@section('title', 'License verification required')

@section('content')
    <section class="card">
        <h1>License verification required</h1>
        <p class="muted">This installation of {{ config('app.name') }} could not be verified, so access is paused. No data has been changed.</p>
        <p>Status: <span class="badge bad">{{ $status }}</span></p>
        @if ($message)
            <p class="bad">{{ $message }}</p>
        @endif
        <p>Enter or refresh your license key on the <a href="{{ route('subscription.license') }}">subscription page</a>, or contact your provider.</p>
    </section>
@endsection
