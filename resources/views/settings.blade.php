@php
    /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array<string, mixed>>> $rows */
    $isFixed = fn (array $row): bool => $row['locks']['hide'] !== null && $row['locks']['change'] !== null && $row['access'] !== 'off';
    $fields = $rows->get('fields', collect());
    $fixed = $fields->filter($isFixed)->values();
    $editable = $fields->reject($isFixed)->values();
    $links = $rows->get('links', collect());
    $hidden = $rows->get('hidden', collect());
    $levels = ['off' => 'Hidden', 'read' => 'Can read', 'edit' => 'Can edit'];
    $kinds = [
        'string' => 'Short text', 'text' => 'Long text', 'integer' => 'Number', 'number' => 'Number',
        'boolean' => 'Yes or no', 'datetime' => 'Date and time', 'date' => 'Date', 'array' => 'List',
        'object' => 'Group of values', 'list of links' => 'Several links', 'link' => 'One link',
        'picture' => 'Picture', 'list' => 'List',
    ];
    $kind = fn (string $type): string => $kinds[$type] ?? \Illuminate\Support\Str::headline($type);
    $status = session('post-mcp.status');
    $Ai = ucfirst($ai);
    $index = 0;
    $lock = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
@include('post-mcp::partials.head')
<title>Laravel Post MCP · {{ $siteName }}</title>
<style>
    .shell { max-width: 1040px; margin: 0 auto; padding: 24px 24px 132px; }

    /* Top plate: the logo and the connector address, like a device's name plate and socket. */
    .top { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
    .brand { display: flex; align-items: center; gap: 12px; }
    .brand svg { width: 40px; height: 40px; flex: none; border-radius: 10px; box-shadow: 0 2px 6px rgb(0 0 0 / .2); }
    .brand b { display: block; font: 600 19px/1.1 var(--label); letter-spacing: .01em; }
    .brand span { font-size: 13px; color: var(--muted); }

    .top-right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; max-width: 100%; }

    /* Light, dark or follow the computer: a small three-position switch. */
    .theme { position: relative; display: grid; grid-template-columns: repeat(3, 36px); margin: 0; padding: 4px; border: 0; border-radius: 12px; background: var(--plate); box-shadow: var(--lift); isolation: isolate; }
    .theme[hidden] { display: none; }
    .theme legend { position: absolute; }
    .theme input { position: absolute; opacity: 0; pointer-events: none; }
    .theme label { position: relative; z-index: 1; display: grid; place-items: center; height: 32px; border-radius: 8px; cursor: pointer; color: var(--muted); transition: color .2s; }
    .theme label:hover { color: var(--ink); }
    .theme input:checked + label { color: var(--panel); }
    .theme input:focus-visible + label { outline: 2px solid var(--edit); outline-offset: 2px; }
    .theme .knob { position: absolute; z-index: 0; inset: 4px auto 4px 4px; width: 36px; border-radius: 8px; background: var(--ink); box-shadow: 0 1px 2px rgb(0 0 0 / .3); transition: translate .3s cubic-bezier(.3, 1.25, .45, 1); }
    .theme:has(#theme-light:checked) .knob { translate: 100% 0; }
    .theme:has(#theme-dark:checked) .knob { translate: 200% 0; }

    .socket { display: flex; align-items: center; gap: 10px; padding: 6px 6px 6px 14px; background: var(--plate); border-radius: 14px; box-shadow: var(--lift); max-width: 100%; }
    .socket .jack { width: 10px; height: 10px; border-radius: 50%; background: var(--edit); box-shadow: 0 0 0 3px color-mix(in oklab, var(--edit) 22%, transparent); flex: none; }
    .socket label { font: 500 13px var(--label); color: var(--muted); white-space: nowrap; }
    .socket input { min-width: 0; width: auto; field-sizing: content; max-width: 100%; font: 12.5px var(--code); color: var(--ink); background: var(--well); border: 0; border-radius: 9px; padding: 8px 10px; box-shadow: var(--inset); }

    /* Intro and the light strip: one light per field, in its level's colour. */
    .intro { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 24px 40px; align-items: end; margin: 56px 0 28px; }
    .intro h1 { margin: 0; font: 600 clamp(36px, 5.6vw, 60px)/.98 var(--label); font-stretch: 88%; letter-spacing: -.01em; max-width: 15ch; text-wrap: balance; }
    .intro p { margin: 14px 0 0; color: var(--muted); max-width: 48ch; }
    .intro .apps { margin-top: 10px; font-size: 14px; }
    .intro .apps b { color: var(--ink); font-weight: 600; }
    .readout { background: var(--plate); border-radius: 18px; box-shadow: var(--lift); padding: 16px 18px; min-width: 300px; }
    .strip { display: flex; gap: 5px; padding: 10px; background: var(--well); border-radius: 10px; box-shadow: var(--inset); min-height: 34px; flex-wrap: wrap; }
    .strip i { flex: 1; min-width: 10px; max-width: 22px; height: 18px; border-radius: 4px; background: var(--off); opacity: .5; }
    .strip.on i { transition: background-color .25s, box-shadow .25s, opacity .25s; }
    .strip i[data-l="read"] { background: var(--read); opacity: 1; box-shadow: 0 0 8px color-mix(in oklab, var(--read) 60%, transparent); }
    .strip i[data-l="edit"] { background: var(--edit); opacity: 1; box-shadow: 0 0 8px color-mix(in oklab, var(--edit) 60%, transparent); }
    .counts { display: grid; grid-template-columns: repeat(3, auto); justify-content: space-between; gap: 16px; margin: 12px 2px 0; }
    .counts div { display: flex; align-items: baseline; gap: 8px; }
    .counts dt { order: 2; font: 500 13px var(--label); color: var(--muted); }
    .counts dd { margin: 0; font: 600 26px/1 var(--label); font-variant-numeric: tabular-nums; color: var(--c); }

    .notices { display: grid; gap: 10px; margin-bottom: 24px; }
    .notice { display: flex; gap: 12px; align-items: flex-start; border-radius: 14px; padding: 13px 16px; background: var(--plate); box-shadow: var(--lift); }
    .notice::before { content: ""; width: 10px; height: 10px; margin-top: 6px; border-radius: 50%; flex: none; background: var(--c, var(--edit)); box-shadow: 0 0 8px var(--c, var(--edit)); }
    .notice.warn { --c: var(--read); } .notice.error { --c: var(--danger); }
    .notice p, .notice ul { margin: 0; padding: 0; list-style: none; }
    .notice code { font: 13px var(--code); background: var(--well); padding: 2px 6px; border-radius: 6px; }

    /* Panels: one per group, a plate with a header band. */
    .panel { background: var(--plate); border-radius: 22px; box-shadow: var(--lift); margin-top: 22px; overflow: clip; }
    .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; padding: 18px 22px 16px; border-bottom: 1px solid var(--seam); }
    .panel-head h2 { margin: 0; font: 600 21px/1.2 var(--label); }
    .panel-head p { margin: 2px 0 0; color: var(--muted); font-size: 14px; }
    .find input { width: 210px; font: inherit; font-size: 14px; color: var(--ink); background: var(--well); border: 0; border-radius: 10px; padding: 8px 12px; box-shadow: var(--inset); }

    .always { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 14px 22px; border-bottom: 1px solid var(--seam); font-size: 13.5px; color: var(--muted); }
    .always > b { font: 500 14px var(--label); color: var(--ink); margin-right: 4px; }
    .tag { display: inline-flex; align-items: center; gap: 7px; padding: 4px 11px 4px 9px; border-radius: 8px; background: var(--well); box-shadow: var(--inset); color: var(--ink); font-size: 13px; }
    .tag i { width: 7px; height: 7px; border-radius: 50%; background: var(--edit); box-shadow: 0 0 6px var(--edit); }
    .tag i.read { background: var(--read); box-shadow: 0 0 6px var(--read); }
    .always small { flex-basis: 100%; font-size: 12.5px; color: var(--faint); }

    /* A row: status light, the field, and its switch. */
    .rows { container-type: inline-size; }
    .row { display: grid; grid-template-columns: 14px minmax(0, 1fr) auto; gap: 6px 16px; align-items: center; padding: 18px 22px; border-top: 1px solid var(--seam); }
    .rows > .row:first-child { border-top: 0; }
    .row[hidden] { display: none; }
    .led { width: 10px; height: 10px; border-radius: 50%; background: var(--off); align-self: start; margin-top: 7px; transition: background-color .25s, box-shadow .25s; }
    .row:has(.switch input[value="read"]:checked) .led { background: var(--read); box-shadow: 0 0 10px var(--read); }
    .row:has(.switch input[value="edit"]:checked) .led { background: var(--edit); box-shadow: 0 0 10px var(--edit); }
    .who { min-width: 0; }
    .who .name { font: 600 17px/1.3 var(--label); }
    .who .says { margin: 2px 0 0; color: var(--muted); font-size: 13.5px; max-width: 62ch; }
    .who .meta { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 6px; font-size: 12.5px; color: var(--faint); }
    .who .meta span { display: inline-flex; align-items: center; gap: 5px; }

    /* The three-position switch, recessed into the plate with a raised knob. */
    .switch { position: relative; display: grid; grid-template-columns: repeat(3, 1fr); margin: 0; padding: 4px; border: 0; border-radius: 12px; background: var(--well); box-shadow: var(--inset); min-width: 312px; isolation: isolate; }
    .switch legend { position: absolute; }
    .switch input { position: absolute; opacity: 0; pointer-events: none; }
    .switch label { position: relative; z-index: 1; text-align: center; font: 500 14px/1 var(--label); padding: 11px 8px; border-radius: 9px; cursor: pointer; color: var(--muted); transition: color .2s; user-select: none; }
    .switch label:hover { color: var(--ink); }
    .switch input:disabled + label { cursor: not-allowed; color: var(--faint); opacity: .5; }
    .switch input:checked + label { color: #fff; opacity: 1; }
    .switch input[value="read"]:checked + label { color: light-dark(#2b2100, #2b2100); }
    .switch input:focus-visible + label { outline: 2px solid var(--edit); outline-offset: 2px; }
    .switch .knob { position: absolute; z-index: 0; inset: 4px auto 4px 4px; width: calc((100% - 8px) / 3); border-radius: 9px; background: var(--off); box-shadow: inset 0 1px 0 rgb(255 255 255 / .35), 0 1px 2px rgb(0 0 0 / .3), 0 4px 10px -3px rgb(0 0 0 / .35); transition: translate .32s cubic-bezier(.3, 1.25, .45, 1), background-color .2s; }
    .switch:has(input[value="read"]:checked) .knob { translate: 100% 0; background: var(--read); }
    .switch:has(input[value="edit"]:checked) .knob { translate: 200% 0; background: var(--edit-solid); }
    .switch:not(:has(input:checked)) .knob { opacity: 0; }

    .extra { grid-column: 2 / -1; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 26px; margin-top: 6px; }

    /* A two-position rocker for on and off choices. */
    .rocker { display: inline-flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; }
    .rocker input { appearance: none; margin: 0; flex: none; width: 40px; height: 22px; border-radius: 7px; background: var(--well); box-shadow: var(--inset); position: relative; cursor: pointer; transition: background-color .2s; }
    .rocker input::after { content: ""; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 5px; background: var(--raised); box-shadow: 0 1px 2px rgb(0 0 0 / .35); transition: translate .22s cubic-bezier(.3, 1.3, .5, 1), background-color .2s; }
    .rocker input:checked { background: color-mix(in oklab, var(--edit) 30%, var(--well)); }
    .rocker input:checked::after { translate: 18px 0; background: var(--edit); }
    .rocker input:disabled { opacity: .45; cursor: not-allowed; }

    details.words > summary { list-style: none; cursor: pointer; font-size: 13.5px; color: var(--muted); display: inline-flex; align-items: center; gap: 6px; }
    details.words > summary::-webkit-details-marker { display: none; }
    details.words > summary:hover { color: var(--ink); }
    details.words > summary svg { transition: rotate .2s; }
    details.words[open] { flex-basis: 100%; }
    details.words[open] > summary svg { rotate: 90deg; }
    .words-body { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); gap: 12px; margin-top: 12px; }
    .words-body label { display: grid; gap: 5px; align-content: start; font: 500 13px var(--label); color: var(--muted); }
    .words-body input, .words-body textarea { font: 14.5px var(--text); color: var(--ink); background: var(--well); border: 0; border-radius: 10px; padding: 9px 11px; width: 100%; box-shadow: var(--inset); }
    .words-body textarea { resize: vertical; min-height: 44px; }
    .words-body .stored { grid-column: 1 / -1; margin: 0; font-size: 12.5px; color: var(--faint); }
    .words-body .stored code { font: 12px var(--code); }
    .field-error { color: var(--danger); font: 13px var(--text); }

    /* The parts of a linked item, like a project's description or logo. */
    .parts { grid-column: 2 / -1; margin-top: 10px; border-radius: 14px; background: var(--well); box-shadow: var(--inset); padding: 6px 16px 12px; transition: opacity .25s; }
    .row:has(.create input[type="checkbox"]:not(:checked)) .parts { opacity: .45; }
    .parts h3 { margin: 10px 0 4px; font: 600 14.5px/1.4 var(--label); }
    .parts ul { margin: 0; padding: 0; list-style: none; }
    .parts li { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 9px 0; border-top: 1px dashed var(--seam); }
    .parts li:first-child { border-top: 0; }
    .parts li { flex-wrap: wrap; }
    .parts .grow { flex-basis: 100%; display: flex; justify-content: flex-end; margin-top: -2px; }
    .parts li:has(> .rocker input[type="checkbox"]:not(:checked)) .grow { opacity: .45; pointer-events: none; }
    .rocker.small { font-size: 13px; color: var(--muted); gap: 8px; }
    .rocker.small input { width: 32px; height: 18px; border-radius: 6px; }
    .rocker.small input::after { width: 12px; height: 12px; border-radius: 4px; }
    .rocker.small input:checked::after { translate: 14px 0; }
    .parts .part b { font-weight: 600; }
    .parts .part span { color: var(--faint); font-size: 12.5px; margin-left: 8px; }
    .parts li.locked .part b { font-weight: 450; color: var(--muted); }
    .parts li small { color: var(--muted); font-size: 12.5px; display: inline-flex; align-items: center; gap: 5px; text-align: right; }
    .parts .foot { margin: 10px 0 0; color: var(--muted); font-size: 12.5px; }

    @container (max-width: 640px) {
        .row { grid-template-columns: 14px minmax(0, 1fr); }
        .switch { grid-column: 2; min-width: 0; max-width: 380px; }
    }

    details.fold { margin-top: 22px; }
    details.fold > summary { cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: 10px; color: var(--muted); font-size: 14px; padding: 6px 2px; }
    details.fold > summary::-webkit-details-marker { display: none; }
    details.fold > summary svg { transition: rotate .2s; }
    details.fold[open] > summary svg { rotate: 90deg; }
    details.fold > summary b { color: var(--ink); font: 600 17px/1.3 var(--label); }
    .hidden-list { margin: 10px 0 0; padding: 6px 22px; list-style: none; background: var(--plate); border-radius: 18px; box-shadow: var(--lift); }
    .hidden-list li { display: flex; justify-content: space-between; gap: 16px; padding: 10px 0; border-top: 1px solid var(--seam); font-size: 14px; }
    .hidden-list li:first-child { border-top: 0; }
    .hidden-list span { color: var(--muted); text-align: right; }

    /* The dock: unsaved state and the save key. */
    .dock { position: fixed; inset: auto 0 18px; display: flex; justify-content: center; padding: 0 14px; pointer-events: none; }
    .dock-inner { pointer-events: auto; display: flex; align-items: center; gap: 16px; padding: 8px 8px 8px 18px; border-radius: 18px; background: var(--plate); box-shadow: var(--lift), 0 18px 40px -16px rgb(0 0 0 / .45); font-size: 14px; color: var(--muted); }
    .dock .lamp { width: 9px; height: 9px; border-radius: 50%; background: var(--off); display: inline-block; margin-right: 9px; transition: background-color .2s, box-shadow .2s; }
    .is-dirty .dock .lamp { background: var(--read); box-shadow: 0 0 10px var(--read); }
    .is-dirty .dock-inner { color: var(--ink); }

    .button { font: 600 15px var(--label); letter-spacing: .01em; border: 0; border-radius: 12px; padding: 12px 22px; cursor: pointer; background: var(--ink); color: var(--panel); box-shadow: inset 0 1px 0 rgb(255 255 255 / .18), 0 2px 0 light-dark(#000, #000), 0 4px 10px -4px rgb(0 0 0 / .5); transition: transform .08s, box-shadow .08s; }
    .button:active { transform: translateY(2px); box-shadow: inset 0 1px 0 rgb(255 255 255 / .18), 0 0 0 #000; }
    .button:disabled { opacity: .4; cursor: not-allowed; }
    .is-dirty .dock .button { background: var(--edit-solid); color: #fff; }
    .button.small { padding: 8px 12px; font-size: 13px; border-radius: 9px; background: var(--raised); color: var(--ink); box-shadow: var(--lift); }
    .button.danger { background: var(--danger); color: #fff; }

    .footer { margin-top: 48px; display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; color: var(--faint); font-size: 13px; }
    .footer details > summary { cursor: pointer; }
    .footer details p { margin: 8px 0 10px; max-width: 36ch; color: var(--muted); }

    @media (max-width: 760px) {
        .shell { padding: 18px 16px 120px; }
        .intro { grid-template-columns: 1fr; margin-top: 36px; }
        .readout { min-width: 0; }
        .top-right { width: 100%; }
        .socket { flex: 1; }
        .socket input { flex: 1; width: auto; }
        .words-body { grid-template-columns: 1fr; }
        .find, .find input { width: 100%; }
        .footer { flex-direction: column; }
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { transition: none !important; }
    }
</style>
</head>
<body>
<div class="shell">
    <header class="top">
        <div class="brand">
            @include('post-mcp::partials.logo')
            <div><b>Laravel Post MCP</b><span>for {{ $siteName }}</span></div>
        </div>
        <div class="top-right">
            <div class="socket">
                <span class="jack" aria-hidden="true"></span>
                <label for="connector-url" title="In your AI app, add a connector (also called a custom connector or MCP server) with this address.">Connector address</label>
                <input id="connector-url" value="{{ $connectorUrl }}" size="{{ strlen($connectorUrl) }}" readonly>
                <button type="button" class="button small" data-copy="connector-url" hidden>Copy</button>
            </div>
            <fieldset class="theme" data-theme-switch hidden>
                <legend class="sr">Colours</legend>
                <input type="radio" name="theme" id="theme-auto" value="auto" checked>
                <label for="theme-auto" title="Follow my computer"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 0 0 16Z" fill="currentColor"/></svg><span class="sr">Follow my computer</span></label>
                <input type="radio" name="theme" id="theme-light" value="light">
                <label for="theme-light" title="Light"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/></svg><span class="sr">Light</span></label>
                <input type="radio" name="theme" id="theme-dark" value="dark">
                <label for="theme-dark" title="Dark"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5Z"/></svg><span class="sr">Dark</span></label>
                <span class="knob" aria-hidden="true"></span>
            </fieldset>
        </div>
    </header>

    <section class="intro">
        <div>
            <h1>What {{ $ai }} can do with your posts</h1>
            <p>Pick a level for each part of your posts. Saved changes apply right away, with nothing to restart. To connect an AI app, add the address above as a connector in that app.</p>
            @if ($apps !== [])
                <p class="apps">Connected now: @foreach ($apps as $app)<b>{{ $app }}</b>@if (! $loop->last), @endif @endforeach</p>
            @endif
        </div>
        <div class="readout" aria-live="polite">
            <div class="strip" data-strip aria-hidden="true"></div>
            <dl class="counts">
                <div style="--c: var(--edit)"><dt>can edit</dt><dd data-count="edit">0</dd></div>
                <div style="--c: var(--read)"><dt>can read</dt><dd data-count="read">0</dd></div>
                <div style="--c: var(--muted)"><dt>hidden</dt><dd data-count="off">0</dd></div>
            </dl>
        </div>
    </section>

    @if ($status || $errors->any() || ! $ready)
        <div class="notices">
            @if ($status)
                <div class="notice" role="status"><p>{{ $status }}</p></div>
            @endif

            @if ($errors->any())
                <div class="notice error" role="alert">
                    <ul>
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @unless ($ready)
                <div class="notice warn" role="alert">
                    <p><strong>One setup step left.</strong> Ask your developer to run <code>php artisan migrate</code>, then reload this page. Until then, the settings file decides and this page can't save.</p>
                </div>
            @endunless
        </div>
    @endif

    <form method="post" action="{{ $formAction }}" id="settings-form">
        @csrf
        @method('PUT')

        @foreach (['fields' => $editable, 'links' => $links] as $group => $items)
            @continue($items->isEmpty() && ($group !== 'fields' || $fixed->isEmpty()))
            <section class="panel" aria-labelledby="h-{{ $group }}">
                <div class="panel-head">
                    <div>
                        <h2 id="h-{{ $group }}">{{ $group === 'fields' ? 'Post fields' : 'Links to other things' }}</h2>
                        <p>{{ $group === 'fields' ? 'The parts of a post, like its title and text.' : 'Things a post points to, like tags or projects.' }}</p>
                    </div>
                    @if ($group === 'fields')
                        <div class="find" hidden data-find>
                            <label class="sr" for="find-field">Find a field</label>
                            <input id="find-field" type="search" placeholder="Find a field" autocomplete="off">
                        </div>
                    @endif
                </div>

                @if ($group === 'fields' && $fixed->isNotEmpty())
                    <div class="always">
                        <b>Always shared</b>
                        @foreach ($fixed as $row)
                            <span class="tag" data-fixed="{{ $row['access'] }}" title="{{ $row['access'] === 'read' ? $Ai.' can read this one.' : $Ai.' can edit this one.' }}"><i @class(['read' => $row['access'] === 'read']) aria-hidden="true"></i>{{ $row['label'] }}</span>
                        @endforeach
                        <small>Needed to write posts. Blue ones {{ $ai }} can edit, amber ones only read.</small>
                    </div>
                @endif

                <div class="rows">
                    @foreach ($items as $row)
                        @php
                            $id = 'f'.(++$index);
                            $name = $row['name'];
                            $access = old("fields.{$name}.access", $row['access']);
                            $locks = $row['locks'];
                            $lockReason = $locks['hide'] ?? $locks['change'];
                            $customLabel = $row['label'] !== $row['default_label'] ? $row['label'] : '';
                            $customNote = $row['description'] !== $row['default_description'] ? $row['description'] : '';
                            $hasWordErrors = $errors->has("fields.{$name}.label") || $errors->has("fields.{$name}.description");
                            $createLocked = ! $ready || $locks['create'] !== null;
                        @endphp
                        <div class="row" data-row data-search="{{ strtolower($name.' '.$row['label']) }}">
                            <span class="led" aria-hidden="true"></span>
                            <div class="who">
                                <div class="name" id="{{ $id }}-name">{{ $row['label'] }}</div>
                                @if ($row['description'] !== '')
                                    <p class="says">{{ $row['description'] }}</p>
                                @endif
                                <div class="meta">
                                    <span>{{ $kind($row['type']) }}</span>
                                    @if ($lockReason)
                                        <span>{!! $lock !!}{{ $lockReason }}</span>
                                    @endif
                                </div>
                            </div>

                            <fieldset class="switch" aria-labelledby="{{ $id }}-name">
                                <legend class="sr">What {{ $ai }} can do with {{ $row['label'] }}</legend>
                                @foreach ($levels as $value => $text)
                                    @php
                                        $disabled = ! $ready
                                            || ($value === 'off' && $locks['hide'] !== null && $row['access'] !== 'off')
                                            || ($value === 'read' && $locks['change'] !== null && $row['access'] !== 'read')
                                            || ($value === 'edit' && ($locks['change'] !== null || $locks['hide'] !== null) && $row['access'] !== 'edit');
                                    @endphp
                                    <input type="radio" id="{{ $id }}-{{ $value }}" name="fields[{{ $name }}][access]" value="{{ $value }}" @checked($access === $value) @disabled($disabled)>
                                    <label for="{{ $id }}-{{ $value }}">{{ $text }}</label>
                                @endforeach
                                <span class="knob" aria-hidden="true"></span>
                            </fieldset>

                            <div class="extra">
                                @if ($row['is_relation'])
                                    <label class="rocker create">
                                        <input type="hidden" name="fields[{{ $name }}][creatable]" value="0" @disabled($createLocked)>
                                        <input type="checkbox" name="fields[{{ $name }}][creatable]" value="1" @checked(old("fields.{$name}.creatable", $row['creatable'])) @disabled($createLocked)>
                                        {{ $Ai }} can add new ones
                                    </label>
                                @endif

                                <details class="words" @if ($hasWordErrors) open @endif>
                                    <summary>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                                        {{ $customLabel !== '' || $customNote !== '' ? 'Edit the name and note' : 'Rename or add a note' }}
                                    </summary>
                                    <div class="words-body">
                                        <label>
                                            Name shown to {{ $ai }}
                                            <input type="text" name="fields[{{ $name }}][label]" value="{{ old("fields.{$name}.label", $customLabel) }}" placeholder="{{ $row['default_label'] }}" maxlength="100" @disabled(! $ready)>
                                            @error("fields.{$name}.label")<span class="field-error">{{ $message }}</span>@enderror
                                        </label>
                                        <label>
                                            Note for {{ $ai }}
                                            <textarea name="fields[{{ $name }}][description]" rows="2" maxlength="500" placeholder="{{ $row['default_description'] ?: 'For example: one short sentence shown under the title.' }}" @disabled(! $ready)>{{ old("fields.{$name}.description", $customNote) }}</textarea>
                                            @error("fields.{$name}.description")<span class="field-error">{{ $message }}</span>@enderror
                                        </label>
                                        <p class="stored">Saved on your site as <code>{{ $name }}</code>.</p>
                                    </div>
                                </details>
                            </div>

                            @if ($row['parts'] !== [])
                                <div class="parts">
                                    <h3>What {{ $ai }} can fill in on a new {{ $row['one'] }}</h3>
                                    <ul>
                                        @foreach ($row['parts'] as $part)
                                            <li @class(['locked' => $part['lock'] !== null && ! $part['on']])>
                                                <span class="part"><b>{{ $part['label'] }}</b><span>{{ $kind($part['type']) }}</span></span>
                                                @if ($part['lock'] !== null)
                                                    <small>{!! $lock !!}{{ $part['lock'] }}</small>
                                                @else
                                                    <label class="rocker">
                                                        <span class="sr">{{ $Ai }} can fill in {{ $part['label'] }}</span>
                                                        <input type="hidden" name="fields[{{ $name }}][details][{{ $part['name'] }}]" value="0" @disabled(! $ready)>
                                                        <input type="checkbox" name="fields[{{ $name }}][details][{{ $part['name'] }}]" value="1" @checked(old("fields.{$name}.details.{$part['name']}", $part['on'])) @disabled(! $ready)>
                                                    </label>
                                                @endif
                                                @if ($part['list'] && $part['lock'] === null)
                                                    <div class="grow">
                                                        @if ($part['grow_lock'] === 'Your developer keeps this list as it is.')
                                                            <small>{!! $lock !!}{{ $part['grow_lock'] }}</small>
                                                        @else
                                                            <label class="rocker small">
                                                                <input type="hidden" name="fields[{{ $name }}][grow][{{ $part['name'] }}]" value="0" @disabled(! $ready || $part['grow_lock'] !== null)>
                                                                <input type="checkbox" name="fields[{{ $name }}][grow][{{ $part['name'] }}]" value="1" @checked(old("fields.{$name}.grow.{$part['name']}", $part['grow'])) @disabled(! $ready || $part['grow_lock'] !== null)>
                                                                Can add more to existing ones
                                                            </label>
                                                        @endif
                                                    </div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    <p class="foot">On a {{ $row['one'] }} that already exists, empty parts get filled in, and lists switched to "add more" get new entries. Nothing already there is changed or removed.</p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($hidden->isNotEmpty())
            <details class="fold">
                <summary>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                    <b>Always hidden</b> {{ $hidden->count() }} {{ \Illuminate\Support\Str::plural('field', $hidden->count()) }} never shared
                </summary>
                <ul class="hidden-list">
                    @foreach ($hidden as $row)
                        <li><b style="font-weight: 500">{{ $row['label'] }}</b><span>{{ $row['locks']['hide'] }}</span></li>
                    @endforeach
                </ul>
            </details>
        @endif

        <div class="dock">
            <div class="dock-inner">
                <span data-save-state><span class="lamp" aria-hidden="true"></span><span>No unsaved changes</span></span>
                <button type="submit" class="button" @disabled(! $ready)>Save changes</button>
            </div>
        </div>
    </form>

    <footer class="footer">
        <p>Your developer's settings file sets the limits. This page works inside them.</p>
        @if ($ready && $hasChoices)
            <details>
                <summary>Reset to defaults</summary>
                <p>This removes every choice made on this page. The settings file decides again.</p>
                <form method="post" action="{{ $formAction }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button danger">Reset everything</button>
                </form>
            </details>
        @endif
    </footer>
</div>

<script>
(() => {
    const form = document.getElementById('settings-form');
    const rows = [...document.querySelectorAll('[data-row]')];
    const fixed = [...document.querySelectorAll('[data-fixed]')];
    const strip = document.querySelector('[data-strip]');

    const lights = [...fixed, ...rows].map(() => strip.appendChild(document.createElement('i')));

    const levelOf = (item) => item.dataset.fixed ?? (item.querySelector('.switch input:checked')?.value || 'off');

    const tally = () => {
        const count = { edit: 0, read: 0, off: 0 };
        [...fixed, ...rows].forEach((item, i) => {
            const level = levelOf(item);
            count[level]++;
            lights[i].dataset.l = level;
        });
        Object.entries(count).forEach(([level, n]) => {
            document.querySelector(`[data-count="${level}"]`).textContent = n;
        });
    };

    const snapshot = () => new URLSearchParams(new FormData(form)).toString();
    const saved = snapshot();
    const state = document.querySelector('[data-save-state]');

    form.addEventListener('input', () => {
        const dirty = snapshot() !== saved;
        document.body.classList.toggle('is-dirty', dirty);
        state.lastElementChild.textContent = dirty ? 'You have unsaved changes' : 'No unsaved changes';
        tally();
    });

    form.addEventListener('submit', () => { window.onbeforeunload = null; });
    window.onbeforeunload = () => (document.body.classList.contains('is-dirty') ? true : undefined);

    const find = document.querySelector('[data-find]');
    if (find) {
        find.hidden = false;
        find.querySelector('input').addEventListener('input', (event) => {
            const term = event.target.value.trim().toLowerCase();
            rows.forEach((row) => { row.hidden = term !== '' && !row.dataset.search.includes(term); });
        });
    }

    document.querySelectorAll('[data-copy]').forEach((button) => {
        if (!navigator.clipboard) return;
        button.hidden = false;
        button.addEventListener('click', async () => {
            await navigator.clipboard.writeText(document.getElementById(button.dataset.copy).value);
            button.textContent = 'Copied';
            setTimeout(() => { button.textContent = 'Copy'; }, 1600);
        });
    });

    const themeSwitch = document.querySelector('[data-theme-switch]');
    themeSwitch.hidden = false;
    themeSwitch.querySelector(`input[value="${document.documentElement.dataset.theme || 'auto'}"]`).checked = true;
    themeSwitch.addEventListener('change', (event) => {
        const theme = event.target.value;
        if (theme === 'auto') {
            delete document.documentElement.dataset.theme;
        } else {
            document.documentElement.dataset.theme = theme;
        }
        try {
            theme === 'auto' ? localStorage.removeItem('post-mcp-theme') : localStorage.setItem('post-mcp-theme', theme);
        } catch (e) {}
    });

    tally();
    requestAnimationFrame(() => strip.classList.add('on'));
})();
</script>
</body>
</html>
