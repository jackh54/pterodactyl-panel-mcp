@extends('ext-panel-mcp::oauth.layout')

@section('title', 'Connect an app')

@section('content')
    <h1>Connect {{ $client->name }}?</h1>
    <p class="muted">Signed in as <strong>{{ $user->username }}</strong>. This app is asking to use the panel as you.</p>

    <div class="note {{ $local ? '' : 'warn' }}">
        After you choose, you will be sent to <strong>{{ $returnsTo }}</strong>.
        @if($local)
            That is an app running on this device.
        @else
            Only continue if you started this from that app or site yourself. The app's name above is whatever it chose to call itself.
        @endif
    </div>

    <form method="post" action="/oauth/authorize">
        @csrf
        <input type="hidden" name="rid" value="{{ $rid }}">

        <p class="muted" style="margin-bottom:8px">Choose what it may do. It can never do more than your own account can.</p>

        @foreach($scopes as $key => $scope)
            <label class="scope">
                @if($key === $required)
                    <input type="checkbox" checked disabled>
                    <input type="hidden" name="scopes[]" value="{{ $key }}">
                @else
                    <input type="checkbox" name="scopes[]" value="{{ $key }}">
                @endif
                <div>
                    <b>{{ $scope['title'] }}</b>
                    <span>{{ $scope['detail'] }}</span>
                </div>
            </label>
        @endforeach

        <div class="actions">
            <button type="submit" name="decision" value="deny">Cancel</button>
            <button type="submit" name="decision" value="approve" class="primary">Connect</button>
        </div>
    </form>

    <p class="foot">You can remove this connection at any time under <a href="/oauth/connections">Connected apps</a>.</p>
@endsection
