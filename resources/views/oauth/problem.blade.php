@extends('ext-panel-mcp::oauth.layout')

@section('title', 'Could not connect')

@section('content')
    <h1>This app could not be connected</h1>
    <p>{{ $message }}</p>
    <div class="actions">
        <a class="button" href="/">Back to the panel</a>
    </div>
@endsection
