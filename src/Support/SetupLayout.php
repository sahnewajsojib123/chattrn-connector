<?php

namespace Chattrn\Connector\Support;

// Shared chrome for every browser-facing setup screen (/chattrn/setup/register,
// /chattrn/setup/map) so they read as one flow instead of three unrelated pages - the same
// card-on-light-background, navy-heading, green/amber-status-circle look as Chattrn's own
// "Waiting for your store to finish connecting" screen, not a bare HTML form. Kept as plain
// inline CSS (no build step, no external request) since this is served straight from a
// package route with no asset pipeline of its own.
class SetupLayout
{
    public static function page(string $title, string $body, ?string $eyebrow = 'Chattrn setup'): string
    {
        $eyebrowHtml = $eyebrow ? '<p class="eyebrow">' . e($eyebrow) . '</p>' : '';

        return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} - Chattrn</title>
<style>
:root {
    --ink: #1a2133;
    --ink-soft: #5b6472;
    --line: #e6e8ec;
    --bg: #f4f5f7;
    --card: #ffffff;
    --accent: #3730a3;
    --accent-soft: #eef0fb;
    --ok-bg: #e6f4ea;
    --ok-ink: #1e7b34;
    --warn-bg: #fdf1dc;
    --warn-ink: #9a6400;
    --err-bg: #fce8e6;
    --err-ink: #b3261e;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    padding: 3rem 1rem;
}
.shell { max-width: 640px; margin: 0 auto; }
.eyebrow { color: var(--accent); font-size: .8rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; margin: 0 0 .5rem; }
h1 { font-size: 1.6rem; margin: 0 0 .35rem; letter-spacing: -.01em; }
.lede { color: var(--ink-soft); margin: 0 0 1.75rem; font-size: .95rem; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 1.5rem 1.75rem; margin-bottom: 1.25rem; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
.card h2 { font-size: 1rem; margin: 0 0 .25rem; }
.card .section-hint { color: var(--ink-soft); font-size: .85rem; margin: 0 0 1rem; }
.field { margin-bottom: 1.1rem; }
.field:last-child { margin-bottom: 0; }
.field label { display: block; font-size: .85rem; font-weight: 600; color: var(--ink); margin-bottom: .3rem; }
.field .hint { color: var(--ink-soft); font-weight: 400; font-size: .8rem; }
.field input[type=text], .field select {
    width: 100%; padding: .55rem .65rem; border: 1px solid var(--line); border-radius: 8px;
    font-size: .95rem; color: var(--ink); background: #fff;
}
.field input[type=text]:focus, .field select:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
.actions { display: flex; align-items: center; gap: 1rem; margin-top: 1.5rem; }
button, .btn {
    appearance: none; border: none; border-radius: 9px; padding: .65rem 1.3rem; font-size: .95rem;
    font-weight: 600; cursor: pointer; background: var(--accent); color: #fff;
}
button:hover, .btn:hover { background: #2d2585; }
.btn-secondary { background: transparent; color: var(--accent); text-decoration: none; padding: .65rem 0; }
.btn-secondary:hover { text-decoration: underline; background: transparent; }
.banner { border-radius: 10px; padding: .85rem 1rem; margin-bottom: 1.25rem; font-size: .9rem; }
.banner.ok { background: var(--ok-bg); color: var(--ok-ink); }
.banner.err { background: var(--err-bg); color: var(--err-ink); }
.banner.warn { background: var(--warn-bg); color: var(--warn-ink); }
.status-row { display: flex; gap: .85rem; padding: .9rem 0; border-top: 1px solid var(--line); }
.status-row:first-child { border-top: none; padding-top: 0; }
.status-dot { flex: none; width: 24px; height: 24px; border-radius: 999px; display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; margin-top: .1rem; }
.status-dot.ok { background: var(--ok-bg); color: var(--ok-ink); }
.status-dot.warn { background: var(--warn-bg); color: var(--warn-ink); }
.status-dot.neutral { background: #eef0f3; color: var(--ink-soft); }
.status-row strong { display: block; font-size: .92rem; }
.status-row span.desc { color: var(--ink-soft); font-size: .85rem; }
code, pre { font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
pre { background: #f6f7f9; border: 1px solid var(--line); border-radius: 10px; padding: 1rem; overflow: auto; white-space: pre-wrap; word-break: break-word; }
.token-box { background: #f6f7f9; border: 1px solid var(--line); border-radius: 8px; padding: .7rem .9rem; font-size: .85rem; word-break: break-all; }
footer { text-align: center; color: var(--ink-soft); font-size: .8rem; margin-top: 2rem; }
</style>
</head>
<body>
<div class="shell">
{$eyebrowHtml}
{$body}
</div>
</body>
</html>
HTML;
    }

    public static function banner(string $type, string $message): string
    {
        return '<div class="banner ' . e($type) . '">' . e($message) . '</div>';
    }
}
