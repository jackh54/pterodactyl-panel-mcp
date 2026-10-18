@extends('ext-panel-mcp::oauth.layout')

@section('title', 'Connected apps')
@section('width', '760px')

@section('content')
    <h1>Connected apps</h1>
    <p class="muted">Apps (such as Claude) that can use the panel as you. Removing one cuts it off straight away.</p>

    @if($removed)
        <div class="note ok">The connection was removed.</div>
    @endif

    @if($connections->isEmpty())
        <div class="note">No apps are connected.</div>
    @else
        <table>
            <thead>
                <tr><th>App</th><th>Access</th><th>Last used</th><th></th></tr>
            </thead>
            <tbody>
                @foreach($connections as $c)
                    <tr>
                        <td>
                            <b>{{ $c['name'] }}</b><br>
                            <span class="muted">Connected <time datetime="{{ $c['created'] }}">{{ $c['created'] }}</time></span>
                        </td>
                        <td>
                            @foreach($c['access'] as $a)<span class="tag">{{ $a }}</span>@endforeach
                        </td>
                        <td class="muted">
                            @if($c['last_used'])
                                <time datetime="{{ $c['last_used'] }}">{{ $c['last_used'] }}</time><br>{{ $c['last_ip'] }}
                            @else
                                Never
                            @endif
                        </td>
                        <td>
                            <form method="post" action="/oauth/connections/{{ $c['id'] }}/remove">
                                @csrf
                                <button type="submit" class="danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="note" style="margin:18px 0 0">
        <strong>To connect an app:</strong> add a custom connector (remote MCP server) in the app with this address, then sign in here when it asks.<br>
        <code>{{ $endpoint }}</code>
    </div>

    <p class="foot"><a href="/account">Back to your account</a></p>
@endsection

@section('below')
    <script>
        // Show times in the viewer's own timezone.
        document.querySelectorAll('time[datetime]').forEach(function (el) {
            var d = new Date(el.getAttribute('datetime'));
            if (!isNaN(d)) el.textContent = d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
        });
    </script>
@endsection
