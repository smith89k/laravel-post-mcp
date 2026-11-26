{{-- Shared by the package's pages: meta tags, icon, the saved light or dark choice, and the colour tokens. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="icon" href="data:image/svg+xml,{{ rawurlencode(str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', trim(view('post-mcp::partials.logo')->render()))) }}">
<script>
    try {
        const theme = localStorage.getItem('post-mcp-theme');
        if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme;
    } catch (e) {}
</script>
<style>
    :root[data-theme="light"] { color-scheme: light; }
    :root[data-theme="dark"] { color-scheme: dark; }
    :root {
        color-scheme: light dark;
        /* A matte control panel: the plate, recessed wells, and raised parts. */
        --panel: light-dark(#e9e8e3, #141517);
        --plate: light-dark(#f6f5f1, #1c1d20);
        --well: light-dark(#dcdbd4, #0e0f11);
        --raised: light-dark(#ffffff, #2a2b2f);
        --ink: light-dark(#1c1d20, #ecebe6);
        --muted: light-dark(#66675f, #9a9b95);
        --faint: light-dark(#94958d, #63645f);
        --seam: light-dark(#d3d2ca, #2a2b2f);
        --edit: light-dark(#2b63ff, #6f93ff);
        --edit-solid: light-dark(#2b63ff, #3460f0);
        --read: light-dark(#d99800, #ffc23d);
        --off: light-dark(#a3a49c, #55575b);
        --danger: light-dark(#c4321f, #ff7a66);
        --label: Bahnschrift, "DIN Alternate", "DIN Next", "Roboto Condensed", "Arial Narrow", system-ui, sans-serif;
        --text: "Segoe UI Variable Text", "Segoe UI", system-ui, -apple-system, Roboto, sans-serif;
        --code: "Cascadia Mono", ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        --inset: inset 0 1px 2px light-dark(rgb(0 0 0 / .12), rgb(0 0 0 / .6)), inset 0 0 0 1px light-dark(rgb(0 0 0 / .04), rgb(255 255 255 / .02));
        --lift: 0 1px 0 light-dark(rgb(255 255 255 / .9), rgb(255 255 255 / .06)) inset, 0 1px 2px rgb(0 0 0 / .18), 0 3px 8px -2px rgb(0 0 0 / .18);
    }

    * { box-sizing: border-box; }
    body { margin: 0; background: var(--panel); color: var(--ink); font: 15px/1.55 var(--text); -webkit-font-smoothing: antialiased; }
    a { color: inherit; }
    :focus-visible { outline: 2px solid var(--edit); outline-offset: 3px; border-radius: 6px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
