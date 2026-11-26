@php
    /** @var \Laravel\Passport\Client $client */
    $redirectUri = request()->query('redirect_uri', $client->redirect_uris[0] ?? null);
    $redirectHost = $redirectUri ? (parse_url($redirectUri, PHP_URL_HOST) ?: $redirectUri) : null;
    $appName = $client->name ?: 'This app';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
@include('post-mcp::partials.head')
<title>Let {{ $appName }} use your posts · {{ $siteName }}</title>
<style>
    body { min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; }

    .card { width: 100%; max-width: 460px; background: var(--plate); border-radius: 24px; box-shadow: var(--lift), 0 30px 60px -30px rgb(0 0 0 / .35); overflow: clip; }
    .head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; border-bottom: 1px solid var(--seam); }
    .head svg { width: 36px; height: 36px; flex: none; border-radius: 9px; box-shadow: 0 2px 6px rgb(0 0 0 / .2); }
    .head b { display: block; font: 600 17px/1.1 var(--label); }
    .head span { font-size: 13px; color: var(--muted); }

    .body { padding: 26px 22px 8px; display: grid; gap: 18px; }
    h1 { margin: 0; font: 600 34px/1.02 var(--label); font-stretch: 88%; letter-spacing: -.01em; text-wrap: balance; }
    .lede { margin: -6px 0 0; color: var(--muted); }

    .well { background: var(--well); border-radius: 14px; box-shadow: var(--inset); padding: 14px 16px; }
    .well-title { margin: 0 0 8px; font: 500 13px var(--label); color: var(--muted); }

    .levels { display: flex; flex-wrap: wrap; gap: 8px 22px; margin: 0; padding: 0; list-style: none; }
    .levels li { display: flex; align-items: baseline; gap: 8px; }
    .levels b { font: 600 26px/1 var(--label); font-variant-numeric: tabular-nums; color: var(--c); }
    .levels span { font-size: 13.5px; color: var(--muted); }
    .levels i { width: 9px; height: 9px; border-radius: 50%; background: var(--c); box-shadow: 0 0 8px var(--c); align-self: center; }
    .change { display: inline-block; margin-top: 10px; font-size: 13.5px; color: var(--edit); text-underline-offset: 3px; }

    .scopes { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
    .scopes li { display: flex; gap: 10px; align-items: baseline; }
    .scopes li::before { content: ""; width: 7px; height: 7px; flex: none; border-radius: 50%; background: var(--edit); translate: 0 -1px; }

    .facts { margin: 0; display: grid; gap: 12px; }
    .facts div { display: grid; gap: 1px; }
    .facts dt { font: 500 13px var(--label); color: var(--muted); }
    .facts dd { margin: 0; overflow-wrap: anywhere; }
    .facts small { display: block; font: 12px var(--code); color: var(--faint); }

    .actions { display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; padding: 22px; }
    .actions form { display: contents; }
    .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; font: 600 15.5px var(--label); letter-spacing: .01em; border: 0; border-radius: 12px; padding: 13px 18px; cursor: pointer; transition: transform .08s, box-shadow .08s; }
    .button.allow { background: var(--edit-solid); color: #fff; box-shadow: inset 0 1px 0 rgb(255 255 255 / .25), 0 2px 0 color-mix(in oklab, var(--edit-solid) 55%, #000), 0 6px 14px -6px color-mix(in oklab, var(--edit) 70%, transparent); }
    .button.cancel { background: var(--raised); color: var(--ink); box-shadow: var(--lift); }
    .button:active { transform: translateY(2px); }
    .button:disabled { opacity: .7; cursor: progress; }
    .spinner { width: 15px; height: 15px; border-radius: 50%; border: 2px solid rgb(255 255 255 / .4); border-top-color: #fff; animation: spin .7s linear infinite; }
    .spinner[hidden] { display: none; }
    @keyframes spin { to { rotate: 360deg; } }

    .foot { margin: 0; padding: 0 22px 20px; font-size: 12.5px; color: var(--faint); }

    @media (max-width: 420px) {
        h1 { font-size: 29px; }
        .actions { grid-template-columns: 1fr; }
        .actions .allow { order: -1; }
    }
    @media (prefers-reduced-motion: reduce) {
        .spinner { animation-duration: 2s; }
    }
</style>
</head>
<body>
<main class="card">
    <header class="head">
        @include('post-mcp::partials.logo')
        <div><b>Post MCP</b><span>for {{ $siteName }}</span></div>
    </header>

    <div class="body">
        <h1>Let {{ $appName }} use your posts?</h1>
        <p class="lede">{{ $appName }} is asking to connect to {{ $siteName }}. If you allow it, it can work with your posts, only as far as your settings let it.</p>

        @if ($canEdit !== null)
            <div class="well">
                <p class="well-title">With your current settings, {{ $appName }}</p>
                <ul class="levels">
                    <li style="--c: var(--edit)"><i aria-hidden="true"></i><b>{{ $canEdit }}</b><span>parts of a post it can edit</span></li>
                    <li style="--c: var(--read)"><i aria-hidden="true"></i><b>{{ $canRead }}</b><span>more it can only read</span></li>
                </ul>
                @if ($settingsUrl)
                    <a class="change" href="{{ $settingsUrl }}" target="_blank" rel="noopener">Change what {{ $appName }} can do</a>
                @endif
            </div>
        @endif

        @if (count($scopes) > 0)
            <div>
                <p class="well-title">What you're allowing</p>
                <ul class="scopes">
                    @foreach ($scopes as $scope)
                        <li>{{ $scope->description }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <dl class="facts">
            <div>
                <dt>Signed in as</dt>
                <dd>{{ $user->email }}</dd>
            </div>
            @if ($redirectUri)
                <div>
                    <dt>After you choose, you go back to</dt>
                    <dd><b>{{ $redirectHost }}</b><small>{{ $redirectUri }}</small></dd>
                </div>
            @endif
        </dl>
    </div>

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}" id="denyForm">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="button cancel">Cancel</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="button allow" id="authorizeButton">
                <span class="spinner" id="authorizeSpinner" hidden></span>
                <span id="authorizeText">Authorize</span>
            </button>
        </form>
    </div>

    <p class="foot">You can take this access away at any time by removing the connector in {{ $appName }}.</p>
</main>

<script>
(() => {
    // After a choice, the browser goes back to the app; this window can then close itself.
    const closeSoon = () => {
        const watch = setInterval(() => {
            if (!location.href.includes('/oauth/authorize') || location.search.includes('code=') || location.search.includes('error=')) {
                clearInterval(watch);
                window.close();
            }
        }, 100);
        setTimeout(() => { clearInterval(watch); window.close(); }, 5000);
    };

    document.getElementById('authorizeForm').addEventListener('submit', () => {
        document.getElementById('authorizeButton').disabled = true;
        document.getElementById('authorizeSpinner').hidden = false;
        document.getElementById('authorizeText').textContent = 'Connecting…';
        setTimeout(closeSoon, 200);
    });

    document.getElementById('denyForm').addEventListener('submit', () => setTimeout(() => window.close(), 200));
})();
</script>
</body>
</html>
