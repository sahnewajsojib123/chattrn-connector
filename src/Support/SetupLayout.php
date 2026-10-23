<?php

namespace Chattrn\Connector\Support;

// Shared chrome for every browser-facing setup screen (/chattrn/setup/register,
// /chattrn/setup/map) so they read as one flow instead of three unrelated pages - the same
// card-on-white-panel, purple-accent look as Chattrn's own CDS screens (Connect / Map your
// tables / Verify), not a bare HTML form. Kept as plain inline CSS (no build step, no
// external request beyond the Inter/Fira Code webfont) since this is served straight from a
// package route with no asset pipeline of its own.
class SetupLayout
{
    public const STEPS = ['Connect', 'Map your tables', 'Verify'];

    public static function page(string $title, string $body, ?string $eyebrow = 'Store setup'): string
    {
        $crumb = $eyebrow
            ? '<div class="crumb"><span class="crumb-mark"></span><span class="crumb-brand">Chattrn</span><span class="crumb-sep">&nbsp;/&nbsp;' . e($eyebrow) . '</span></div>'
            : '';

        return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} - Chattrn</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code:wght@500&display=swap">
<style>
:root {
    --ink: #232f40;
    --ink-soft: #6c7584;
    --ink-faint: #7b8594;
    --line: #e7ebee;
    --bg: #f1f4f5;
    --card: #ffffff;
    --accent: #a946ba;
    --accent-hover: #8c3a9e;
    --accent-soft: #f6ecf8;
    --ok-bg: #dcf9dc;
    --ok-ink: #107062;
    --warn-bg: #fdf1dc;
    --warn-ink: #9a6400;
    --err-bg: #fce8e6;
    --err-ink: #b3261e;
    --neutral-bg: #eef0f3;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font: 15px/1.5 Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    padding: 3rem 1rem;
}
.shell { max-width: 680px; margin: 0 auto; }

.crumb { display: flex; align-items: center; gap: 8px; margin-bottom: 1.5rem; }
.crumb-mark { width: 20px; height: 20px; border-radius: 6px; background: var(--accent); display: inline-block; position: relative; flex: none; }
.crumb-mark::after { content: ""; position: absolute; width: 7px; height: 7px; border-radius: 2px; background: #fff; top: 50%; left: 50%; transform: translate(-50%, -50%); }
.crumb-brand { font: 600 13px/18px Inter, sans-serif; color: var(--ink-soft); }
.crumb-sep { font: 400 13px/18px Inter, sans-serif; color: var(--ink-faint); }

.panel { background: var(--card); border-radius: 10px; box-shadow: 0px 9px 23.5px rgba(0,0,0,0.06); overflow: hidden; }
.panel-head { padding: 1.5rem 1.5rem 0.5rem; }
.panel-body { padding: 0 1.5rem 1.5rem; display: flex; flex-direction: column; gap: 1.25rem; }

.eyebrow { color: var(--accent); font-size: .8rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; margin: 0 0 .5rem; }
h1 { font: 600 22px/34px Inter, sans-serif; color: var(--ink); margin: 0 0 .35rem; letter-spacing: -.01em; }
.lede { color: var(--ink-soft); margin: 0 0 1.25rem; font-size: .9rem; }

.stepper { display: flex; gap: 12px; margin-bottom: 1.25rem; }
.step { flex: 1 0 0; display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
.step.active { border: 1.5px solid var(--accent); }
.step.done { border: 1px solid var(--line); }
.step .num { width: 26px; height: 26px; border-radius: 13px; display: flex; align-items: center; justify-content: center; flex: none; background: #fff; border: 1.5px solid #e7ebee; font: 600 13px/20px Inter, sans-serif; color: var(--ink-soft); }
.step.active .num { background: var(--accent); border-color: var(--accent); color: #fff; }
.step.done .num { background: var(--ok-bg); border-color: var(--ok-bg); color: var(--ok-ink); }
.step .label p { margin: 0; }
.step .label strong { display: block; font: 600 14px/20px Inter, sans-serif; color: var(--ink); }
.step .label span { font: 400 12px/18px Inter, sans-serif; color: var(--ink-soft); }

.card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }
.card h2 { font: 600 16px/24px Inter, sans-serif; color: var(--ink); margin: 0; }
.card .section-hint { color: var(--ink-soft); font: 400 13px/20px Inter, sans-serif; margin: 2px 0 0; }
.card-head { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; }
.card-head .titles { display: flex; flex-direction: column; gap: 4px; }
.card-title { display: flex; flex-direction: column; gap: 2px; }

/* Row-list mapping pattern (Models / columns cards): label+hint left, editable field middle,
   guess status right — same shape as the Figma "Your endpoints" / mapping screens. */
.cat { margin: 4px 0 0; font: 600 11px/16px Inter, sans-serif; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.02em; }
.row { display: flex; gap: 16px; align-items: center; padding: 10px 0; border-top: 1px solid var(--line); }
.row-label { width: 190px; flex: none; }
.row-label p.t { margin: 0; font: 600 13px/20px Inter, sans-serif; color: var(--ink); }
.row-label p.h { margin: 0; font: 400 12px/18px Inter, sans-serif; color: var(--ink-soft); }
.row-field { flex: 1 0 0; min-width: 0; }
.row-field input, .row-field select { width: 100%; box-sizing: border-box; height: 36px; border: 1px solid #dae0e4; border-radius: 8px; padding: 0 12px; font: 400 13px/20px Inter, sans-serif; color: var(--ink); background: #fff; }
.row-field input:focus, .row-field select:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
.row-status { width: 190px; flex: none; }
.row-status p.s { margin: 0; font: 600 12px/18px Inter, sans-serif; }
.row-status p.d { margin: 0; font: 400 12px/18px Inter, sans-serif; color: var(--ink-soft); }
.row-status .s.ok { color: var(--ok-ink); }
.row-status .s.warn { color: #e98b00; }
.row-status .s.muted { color: var(--ink-soft); }

.badge { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 10px 4px 8px; font: 600 12px/18px Inter, sans-serif; flex: none; white-space: nowrap; }
.badge::before { content: ""; width: 8px; height: 8px; border-radius: 4px; background: currentColor; }
.badge-ok { background: var(--ok-bg); color: var(--ok-ink); }
.badge-warn { background: var(--warn-bg); color: var(--warn-ink); }
.badge-err { background: var(--err-bg); color: var(--err-ink); }
.badge-neutral { background: var(--neutral-bg); color: var(--ink-soft); }

.tf-row { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
.tf { flex: 1 0 220px; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.tf label { font: 500 13px/20px Inter, sans-serif; color: var(--ink); }
.tf .field { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 6px 12px; display: flex; align-items: center; gap: 8px; }
.tf .field code, .tf .field span.val { flex: 1 0 0; font: 400 13px/20px Inter, sans-serif; color: var(--ink-soft); min-width: 0; word-break: break-all; }
.tf .field code { font-family: "Fira Code", ui-monospace, monospace; }
.tf .field button { appearance: none; border: none; cursor: pointer; background: var(--neutral-bg); color: var(--ink); border-radius: 8px; height: 26px; padding: 0 10px; font: 500 12px/16px Inter, sans-serif; flex: none; }

.note { background: #f8f9fa; border-radius: 8px; padding: 10px 12px; display: flex; align-items: flex-start; gap: 10px; }
.note p { margin: 0; font: 400 12px/18px Inter, sans-serif; color: var(--ink-soft); }
.note svg { flex: none; margin-top: 1px; }

.row-list .status-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-top: 1px solid var(--bg); }
.row-list .status-row:first-of-type { border-top: none; padding-top: 0; }
.row-list .status-row strong { display: block; font: 500 14px/20px Inter, sans-serif; color: var(--ink); }
.row-list .status-row .desc { font: 400 12px/18px Inter, sans-serif; color: var(--ink-faint); }
.row-list .status-row .state { font: 600 12px/18px Inter, sans-serif; color: var(--ink-soft); flex: none; }
.row-list .status-row .state.ok { background: var(--ok-bg); color: var(--ok-ink); border-radius: 999px; padding: 2px 10px; }

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
    appearance: none; border: none; border-radius: 10px; padding: .4rem 1rem; font-size: .9rem;
    font-weight: 600; cursor: pointer; background: var(--accent); color: #fff; text-decoration: none; display: inline-flex; align-items: center;
}
button:hover, .btn:hover { background: var(--accent-hover); }
.btn-secondary { background: var(--neutral-bg); color: var(--ink); }
.btn-secondary:hover { background: #e2e6ea; }
.btn-disabled, .btn-disabled:hover { background: var(--neutral-bg); color: var(--ink-faint); cursor: not-allowed; }

.footer-bar { border-top: 1px solid var(--line); display: flex; justify-content: center; padding: 16px 24px; gap: 12px; }

.banner { border-radius: 10px; padding: .85rem 1rem; margin-bottom: 1.25rem; font-size: .9rem; }
.banner.ok { background: var(--ok-bg); color: var(--ok-ink); }
.banner.err { background: var(--err-bg); color: var(--err-ink); }
.banner.warn { background: var(--warn-bg); color: var(--warn-ink); }
.status-row { display: flex; gap: .85rem; padding: .9rem 0; border-top: 1px solid var(--line); }
.status-row:first-child { border-top: none; padding-top: 0; }
.status-dot { flex: none; width: 24px; height: 24px; border-radius: 999px; display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; margin-top: .1rem; }
.status-dot.ok { background: var(--ok-bg); color: var(--ok-ink); }
.status-dot.warn { background: var(--warn-bg); color: var(--warn-ink); }
.status-dot.neutral { background: var(--neutral-bg); color: var(--ink-soft); }
.status-row strong { display: block; font-size: .92rem; }
.status-row span.desc { color: var(--ink-soft); font-size: .85rem; }
code, pre { font: 13px/1.5 "Fira Code", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
pre { background: #f6f7f9; border: 1px solid var(--line); border-radius: 10px; padding: 1rem; overflow: auto; white-space: pre-wrap; word-break: break-word; }
.token-box { background: #f6f7f9; border: 1px solid var(--line); border-radius: 8px; padding: .7rem .9rem; font-size: .85rem; word-break: break-all; }
footer { text-align: center; color: var(--ink-faint); font-size: .8rem; margin-top: 2rem; }
</style>
</head>
<body>
<div class="shell">
{$crumb}
<div class="panel">
{$body}
</div>
</div>
</body>
</html>
HTML;
    }

    public static function banner(string $type, string $message): string
    {
        return '<div class="banner ' . e($type) . '">' . e($message) . '</div>';
    }

    public static function badge(string $type, string $text): string
    {
        return '<span class="badge badge-' . e($type) . '">' . e($text) . '</span>';
    }

    // The 3-step Connect / Map your tables / Verify stepper shown at the top of every setup
    // screen. $active is 1-based; steps before it render as "done" (green check-style
    // number), the active one is purple, later ones stay neutral.
    public static function stepper(int $active): string
    {
        $html = '<div class="stepper">';
        foreach (self::STEPS as $i => $label) {
            $n = $i + 1;
            $state = $n === $active ? 'active' : ($n < $active ? 'done' : '');
            $caption = $n === $active ? 'You are here' : ($n < $active ? 'Done' : ($n === $active + 1 ? 'Next' : 'Check it\'s live'));
            $num = $n < $active ? '&#10003;' : (string) $n;
            $html .= '<div class="step ' . $state . '"><div class="num">' . $num . '</div>'
                . '<div class="label"><p><strong>' . e($label) . '</strong></p><p><span>' . e($caption) . '</span></p></div></div>';
        }
        $html .= '</div>';

        return $html;
    }
}
