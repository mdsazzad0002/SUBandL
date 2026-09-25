@extends('subandl::layout')

@section('title', 'Terms')

@section('content')
    <section class="card">
        <h1>License terms</h1>
        <ol>
            <li>Each license key is bound to a single installation. Moving to a new server requires the provider to release the binding.</li>
            <li>This software periodically contacts the provider to verify the license, check for updates and, when enabled, upload database backups.</li>
            <li>Updates are applied only while the update/support period is active.</li>
            <li>An expired or invalid license pauses access to the application; it never deletes data.</li>
        </ol>
        <p class="muted">Publish the views (<code>php artisan vendor:publish --tag=subandl-views</code>) to replace this text with your own terms.</p>
        <p><a href="{{ route('subscription.license') }}">Back to subscription</a></p>
    </section>
@endsection
