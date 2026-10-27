<?php
/**
 * The single HTML wrapper every screen in admin/ is built with, so the sign-in
 * page and the review desk cannot drift into looking like two different tools.
 *
 * Two shapes come out of kwetu_admin_page(): the desk, which is the sidebar
 * plus a page of cards, and the stage, which is the sign-in screen standing on
 * its own. Both are painted from the one stylesheet below, in the same colours
 * and the same Poppins weights the public pages use.
 *
 * Colours are the website's own: #B5122A, #8F0E21, #C6A56B, #F5F3EF, #151515,
 * #202020. Nothing new is introduced, and the gold is used for the thin marks
 * - an active edge, a rule, a border on a card - rather than for large areas.
 */

declare(strict_types=1);

function kwetu_admin_styles(): string
{
    return <<<'CSS'
:root {
    --red:#B5122A;
    --darkred:#8F0E21;
    --gold:#C6A56B;
    --paper:#F5F3EF;
    --ink:#151515;
    --soft:#202020;
    --white:#fff;
    --line:rgba(21,21,21,.12);
    --line-soft:rgba(21,21,21,.08);
    --muted:rgba(21,21,21,.62);
    --quiet:rgba(21,21,21,.45);
    --ok:#2E7D32;
}

* { box-sizing:border-box; }

body {
    margin:0;
    background:var(--paper);
    color:var(--ink);
    font:15px/1.6 "Poppins","Segoe UI",Arial,Helvetica,sans-serif;
    -webkit-text-size-adjust:100%;
}

h1, h2, h3 { font-weight:600; }
a { color:var(--darkred); }

/* Every clickable thing gets the same ring, in gold rather than the browser's
   blue, so keyboard focus is visible on both the light cards and the dark
   sidebar without leaving the palette. */
:focus-visible { outline:2px solid var(--gold); outline-offset:3px; border-radius:2px; }

.skip { position:absolute; left:-9999px; }

.skip:focus {
    left:14px; top:14px; z-index:60; padding:10px 16px;
    background:var(--ink); color:var(--paper); text-decoration:none; border-radius:2px;
}

/* =========================================
   THE DESK: sidebar, bar, page
========================================= */

.app { display:grid; grid-template-columns:268px minmax(0,1fr); min-height:100vh; }

.side {
    position:sticky; top:0; align-self:start; max-height:100vh; overflow-y:auto;
    display:flex; flex-direction:column; padding:22px 0 16px;
    background:var(--ink); color:var(--paper);
    border-right:1px solid rgba(198,165,107,.32);
}

.side-brand {
    display:flex; flex-direction:column; gap:3px; padding:0 24px 18px; margin-bottom:6px;
    border-bottom:1px solid rgba(245,243,239,.1);
}

/* Stacked above the wordmark: the 268px sidebar minus 48px of padding cannot
   hold a 40px badge beside a 17px/.13em lockup on one line. */
.side-logo { width:40px; height:40px; margin:0 0 9px; object-fit:contain; }

.wordmark { font-size:17px; font-weight:700; letter-spacing:.13em; line-height:1.2; color:var(--paper); }
.wordmark .w-dot { color:var(--gold); }
.wordmark .w-red { color:var(--red); font-weight:500; }

.side-kicker {
    font-size:10.5px; letter-spacing:.22em; text-transform:uppercase;
    color:rgba(245,243,239,.5);
}

.side-group {
    margin:16px 24px 4px; font-size:10.5px; letter-spacing:.2em; text-transform:uppercase;
    color:rgba(245,243,239,.42);
}

.side-link {
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:9px 24px; border-left:3px solid transparent;
    color:rgba(245,243,239,.76); text-decoration:none; font-size:14px;
}

.side-link:hover {
    background:rgba(245,243,239,.05); color:var(--paper);
    border-left-color:rgba(198,165,107,.5);
}

.side-link.is-on {
    background:rgba(198,165,107,.14); border-left-color:var(--gold);
    color:var(--paper); font-weight:600;
}

.badge {
    flex:0 0 auto; min-width:22px; padding:1px 7px; border-radius:11px;
    font-size:11.5px; font-weight:600; text-align:center;
    background:rgba(245,243,239,.12); color:rgba(245,243,239,.82);
}

.badge.is-hot { background:var(--red); color:var(--paper); }

.side-foot {
    margin:auto 24px 0; padding-top:18px; font-size:11.5px; line-height:1.6;
    color:rgba(245,243,239,.42);
}

.main { min-width:0; }

.bar {
    display:flex; flex-wrap:wrap; align-items:baseline; justify-content:space-between;
    gap:4px 20px; padding:26px clamp(18px,3.2vw,40px) 15px; margin-bottom:22px;
    border-bottom:1px solid var(--line);
}

.bar h1 { margin:0; font-size:20px; letter-spacing:.01em; }
.bar-sub { margin:0; font-size:11.5px; letter-spacing:.14em; text-transform:uppercase; color:var(--quiet); }

.page { padding:0 clamp(18px,3.2vw,40px) 60px; max-width:1180px; }

/* =========================================
   CARDS, LISTS, FEEDBACK
========================================= */

.card {
    background:var(--white); border:1px solid var(--line); border-top:2px solid var(--gold);
    border-radius:3px; padding:20px 22px; margin-bottom:16px;
    box-shadow:0 1px 1px rgba(21,21,21,.03), 0 14px 30px -26px rgba(21,21,21,.4);
}

.card.is-new, .card.is-pending { border-top-color:var(--red); }
.card.is-handled { border-top-color:var(--gold); }
.card.is-approved { border-top-color:var(--ok); }
.card.is-archived, .card.is-rejected { border-top-color:rgba(21,21,21,.25); opacity:.78; }

.meta {
    display:flex; flex-wrap:wrap; gap:6px 18px; align-items:center; margin-bottom:10px;
    font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em;
}

.who { font-size:16px; font-weight:600; }
.who small { font-weight:400; font-size:13px; color:var(--muted); }
.stars { color:var(--gold); letter-spacing:2px; font-size:14px; }

blockquote {
    margin:12px 0 0; padding:0; font-size:15px; line-height:1.7; white-space:pre-wrap;
    overflow-wrap:anywhere;
}

.actions {
    display:flex; flex-wrap:wrap; gap:8px; align-items:center;
    margin-top:18px; padding-top:16px; border-top:1px solid var(--line-soft);
}

button {
    font:inherit; font-size:13px; font-weight:500; padding:8px 16px; cursor:pointer;
    border-radius:2px; border:1px solid var(--red); background:var(--red); color:var(--paper);
    transition:background .16s ease, border-color .16s ease;
}

button:hover { background:var(--darkred); border-color:var(--darkred); }
button:disabled { opacity:.62; cursor:progress; }

button.ghost { background:transparent; color:var(--soft); border-color:rgba(21,21,21,.28); }

button.ghost:hover {
    background:rgba(21,21,21,.05); color:var(--ink); border-color:rgba(21,21,21,.45);
}

button.danger { background:transparent; color:var(--darkred); border-color:rgba(143,14,33,.4); }

button.danger:hover { background:rgba(181,18,42,.07); border-color:var(--darkred); }

button.copy-btn { background:transparent; color:var(--soft); border-color:rgba(198,165,107,.7); }

button.copy-btn:hover {
    background:rgba(198,165,107,.12); border-color:var(--gold); color:var(--ink);
}

.empty {
    padding:44px 22px; text-align:center; color:var(--muted); background:var(--white);
    border:1px dashed rgba(21,21,21,.22); border-radius:3px;
}

.empty strong { display:block; margin-bottom:6px; font-size:15px; font-weight:600; color:var(--soft); }

.notice {
    padding:14px 18px; margin-bottom:18px; font-size:14px;
    border-left:3px solid var(--gold); background:var(--white); border-radius:0 3px 3px 0;
    box-shadow:0 10px 24px -22px rgba(21,21,21,.5);
}

.notice.bad { border-left-color:var(--red); }
.notice.good { border-left-color:var(--ok); }
.count { margin:0 0 18px; font-size:13px; color:var(--muted); }
.hint { font-size:13px; line-height:1.65; color:var(--muted); }

.tag {
    display:inline-block; padding:2px 9px; border-radius:2px; font-size:11.5px;
    letter-spacing:.05em; text-transform:uppercase;
    background:rgba(21,21,21,.06); color:var(--muted);
}

.tag.is-unread { background:rgba(181,18,42,.1); color:var(--darkred); font-weight:600; }
.tag.is-read { background:rgba(198,165,107,.18); color:#6B5626; }

.filters { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 14px; }

.filters a {
    padding:6px 13px; border:1px solid var(--line); border-radius:2px; background:var(--white);
    color:var(--soft); text-decoration:none; font-size:13px;
}

.filters a:hover { border-color:var(--gold); }
.filters a[aria-current="page"] { background:var(--soft); border-color:var(--soft); color:var(--paper); }

.toolbar {
    display:flex; flex-wrap:wrap; gap:12px; align-items:center;
    justify-content:space-between; margin:0 0 16px;
}

.search { display:flex; flex:1 1 280px; gap:8px; min-width:0; }

.search input {
    flex:1 1 auto; min-width:0; padding:9px 12px; font:inherit; font-size:14px;
    border:1px solid rgba(21,21,21,.22); border-radius:2px; background:var(--white);
}

.search input:focus {
    outline:none; border-color:var(--red); box-shadow:0 0 0 3px rgba(198,165,107,.28);
}

.detail {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:8px 22px;
    margin:12px 0 0; font-size:14px;
}

.detail div { min-width:0; overflow-wrap:anywhere; }

.detail b {
    display:block; font-size:11px; text-transform:uppercase; letter-spacing:.06em;
    color:rgba(21,21,21,.55);
}

.detail a { color:var(--darkred); }

.files { display:flex; flex-wrap:wrap; gap:8px; margin:14px 0 0; padding:0; list-style:none; }
.files li { font-size:12.5px; }

.files a {
    display:inline-block; padding:5px 11px; border:1px solid var(--gold);
    border-radius:2px; color:var(--soft); text-decoration:none;
}

.files a:hover { background:rgba(198,165,107,.14); }

.thumbs { display:flex; flex-wrap:wrap; gap:12px; margin:14px 0 0; }

.thumb {
    margin:0; padding:0; width:180px; min-width:0; font:inherit; text-align:left; color:inherit;
    background:var(--white); border:1px solid rgba(21,21,21,.2); border-radius:2px;
    cursor:zoom-in; overflow:hidden; transition:border-color .16s ease, box-shadow .16s ease;
}

.thumb:hover { border-color:var(--gold); box-shadow:0 8px 18px -12px rgba(21,21,21,.55); }
.thumb img { display:block; width:100%; height:135px; object-fit:cover; }

.thumb-cap {
    display:block; padding:6px 8px 7px; font-size:11.5px; line-height:1.4;
    color:var(--muted); overflow-wrap:anywhere;
}

.viewer {
    position:fixed; inset:0; z-index:40; display:flex; flex-direction:column;
    align-items:center; justify-content:center; gap:14px; padding:22px;
    background:rgba(21,21,21,.89);
}

.viewer[hidden] { display:none; }
.viewer-frame { margin:0; display:flex; flex-direction:column; align-items:center; gap:12px; }

.viewer img {
    max-width:min(94vw,1120px); max-height:72vh; object-fit:contain; background:var(--white);
    border:1px solid rgba(198,165,107,.55); border-radius:2px;
    box-shadow:0 26px 64px -26px rgba(0,0,0,.85);
}

.viewer figcaption {
    margin:0; font-size:13px; color:var(--paper); text-align:center;
    max-width:min(94vw,1120px); overflow-wrap:anywhere;
}

.viewer-close {
    padding:11px 22px; background:var(--white); color:var(--ink); border:1px solid var(--white);
    letter-spacing:.11em; text-transform:uppercase; font-size:12.5px; font-weight:600;
}

.viewer-close:hover { background:var(--gold); border-color:var(--gold); color:var(--ink); }
.viewer-hint { font-size:12px; color:rgba(245,243,239,.66); }

.paste { position:relative; }
.paste textarea { min-height:300px; }
.paste-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:0 0 10px; }
.paste-row a { font-size:13px; color:var(--darkred); }
.copied { font-size:13px; color:var(--ok); }
.consent { font-size:12.5px; }
.consent.no { color:var(--darkred); font-weight:600; }
form.inline { display:inline; }

input.note {
    padding:8px 11px; font:inherit; font-size:13px; min-width:210px;
    border:1px solid rgba(21,21,21,.25); border-radius:2px; background:var(--white);
}

input.note:focus {
    outline:none; border-color:var(--red); box-shadow:0 0 0 3px rgba(198,165,107,.28);
}

textarea {
    width:100%; min-height:210px; padding:12px; font:13px/1.6 Consolas,monospace;
    border:1px solid rgba(21,21,21,.2); border-radius:2px; background:var(--white); color:var(--ink);
}

/* =========================================
   THE DASHBOARD
========================================= */

.stats {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr));
    gap:14px; margin:0 0 26px;
}

.stat {
    display:block; padding:18px 20px; background:var(--white); border:1px solid var(--line);
    border-left:3px solid var(--gold); border-radius:3px; text-decoration:none; color:inherit;
    box-shadow:0 12px 26px -24px rgba(21,21,21,.45);
    transition:border-color .16s ease, box-shadow .16s ease;
}

.stat:hover { border-left-color:var(--red); box-shadow:0 14px 30px -22px rgba(21,21,21,.5); }
.stat-num { display:block; font-size:30px; font-weight:700; line-height:1.1; letter-spacing:-.01em; }
.stat.is-alert .stat-num { color:var(--red); }

.stat-label {
    display:block; margin-top:4px; font-size:11.5px; text-transform:uppercase;
    letter-spacing:.09em; color:var(--muted);
}

.stat-note { display:block; margin-top:8px; font-size:12.5px; line-height:1.5; color:var(--quiet); }

.panels { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; }

.panel { background:var(--white); border:1px solid var(--line); border-radius:3px; }

.panel h2 {
    margin:0; padding:14px 20px; font-size:12px; text-transform:uppercase; letter-spacing:.11em;
    color:var(--muted); border-bottom:1px solid var(--line-soft); font-weight:600;
}

.panel ul { list-style:none; margin:0; padding:0; }

.row {
    display:flex; justify-content:space-between; gap:14px; align-items:baseline;
    padding:12px 20px; border-bottom:1px solid var(--line-soft);
}

.panel li:last-child .row { border-bottom:0; }
.row a { color:inherit; text-decoration:none; font-weight:500; overflow-wrap:anywhere; }
.row a:hover { color:var(--darkred); text-decoration:underline; }
.row small { display:block; font-size:12px; color:var(--quiet); }
.row .when { flex:0 0 auto; font-size:12px; color:var(--quiet); }
.panel .empty { margin:0; border:0; border-radius:0; }

/* =========================================
   THE SIGN-IN STAGE
========================================= */

.stage {
    min-height:100vh; display:flex; flex-direction:column; align-items:center;
    justify-content:center; gap:22px; padding:40px 20px; background:var(--ink);
}

.login-card {
    width:100%; max-width:900px; display:grid; grid-template-columns:1.02fr .98fr;
    background:var(--white); border-top:3px solid var(--gold); border-radius:4px;
    overflow:hidden; box-shadow:0 40px 80px -50px rgba(0,0,0,.9);
}

.login-brand {
    display:flex; flex-direction:column; gap:14px; padding:38px 40px 34px;
    background:var(--soft); color:var(--paper);
}

.login-mark { display:flex; align-items:center; gap:10px; font-size:19px; font-weight:700; letter-spacing:.13em; line-height:1.2; color:var(--paper); }
/* The mark has its white ground keyed out, so it sits directly on the panel's
   #202020 exactly as it does on the site navbar. */
.login-logo { width:40px; height:40px; flex:0 0 auto; object-fit:contain; }
.login-mark .w-dot { color:var(--gold); }
.login-mark .w-red { color:var(--red); font-weight:500; }
.login-rule { width:44px; height:1px; background:var(--gold); }
.login-title { margin:0; font-size:23px; font-weight:600; color:var(--paper); }
.login-lede { margin:0; font-size:14px; line-height:1.65; color:rgba(245,243,239,.72); }

.login-points {
    margin:auto 0 0; padding:0; list-style:none; font-size:12.5px; line-height:1.7;
    color:rgba(245,243,239,.55);
}

.login-form { padding:38px 40px 30px; background:var(--white); }
.login-form h2 { margin:0 0 4px; font-size:17px; }
.login-sub { margin:0 0 22px; font-size:13px; color:var(--muted); }
.login-help { margin:20px 0 0; padding-top:16px; border-top:1px solid var(--line-soft);
              font-size:12.5px; line-height:1.65; color:var(--muted); }

label {
    display:block; margin:0 0 8px; font-size:11.5px; font-weight:600;
    text-transform:uppercase; letter-spacing:.11em; color:rgba(21,21,21,.66);
}

.field { position:relative; margin-bottom:16px; }

.field input {
    width:100%; padding:13px 46px 13px 14px; font:inherit; font-size:16px;
    letter-spacing:.04em; color:var(--ink); background:var(--white);
    border:1px solid rgba(21,21,21,.24); border-radius:2px;
    transition:border-color .16s ease, box-shadow .16s ease;
}

.field input:hover { border-color:rgba(21,21,21,.4); }

.field input:focus {
    outline:none; border-color:var(--red); box-shadow:0 0 0 3px rgba(198,165,107,.3);
}

.field input::placeholder { color:rgba(21,21,21,.32); }

.reveal {
    position:absolute; top:0; right:0; width:44px; height:100%; padding:0;
    display:inline-flex; align-items:center; justify-content:center;
    background:transparent; border:0; border-radius:0; color:rgba(21,21,21,.5); cursor:pointer;
}

.reveal:hover { color:var(--red); }
.reveal:focus-visible { outline:2px solid var(--red); outline-offset:-4px; color:var(--red); }
.reveal .eye-off { display:none; }
.reveal[aria-pressed="true"] .eye-on { display:none; }
.reveal[aria-pressed="true"] .eye-off { display:inline; }

.signin {
    width:100%; padding:13px; margin-top:2px; font-size:12.5px; font-weight:600;
    letter-spacing:.13em; text-transform:uppercase;
}

/* The only moving thing in admin/, and it appears while the server is
   deciding. Everything stops moving for anyone who asked for that. */
.spin {
    display:none; width:14px; height:14px; margin-right:9px; vertical-align:-2px;
    border:2px solid rgba(245,243,239,.4); border-top-color:var(--paper); border-radius:50%;
    animation:kwetu-turn .7s linear infinite;
}

.signin[aria-busy="true"] .spin { display:inline-block; }

@keyframes kwetu-turn { to { transform:rotate(360deg); } }

/* The settings panel. Same inputs as the sign-in card, on the paper background
   the rest of the desk uses, so a password box looks like every other box. */
.settings { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:20px; align-items:start; }
.settings-form { padding:26px 28px 30px; }
.settings-form h2 { margin:0 0 6px; font-size:17px; }
.settings-form .field input { background:var(--paper); }
.settings-side { padding:22px 24px 24px; border-top:2px solid var(--line); }
.settings-side h2 { margin:0 0 8px; font-size:13px; letter-spacing:.06em; text-transform:uppercase; color:var(--soft); }
.settings-side p { font-size:13px; line-height:1.65; color:var(--muted); }
.settings-side pre { margin:10px 0 0; font:13px Consolas,monospace; color:var(--soft); white-space:pre-wrap; overflow-wrap:anywhere; }
.revealed-hint { font-size:12.5px; color:var(--quiet); margin:-8px 0 18px; }

.login-sign {
    margin:0; font-size:11.5px; letter-spacing:.18em; text-transform:uppercase;
    text-align:center; color:rgba(245,243,239,.42);
}

.setup { padding:34px 36px; }
.setup pre { margin:10px 0 0; font:13px Consolas,monospace; color:var(--soft); white-space:pre-wrap; }

/* The screen that says no password exists yet has nothing to sign in to, so it
   drops the brand panel and stands on one column. */
.login-card.is-single { grid-template-columns:1fr; max-width:560px; }

.sr-only {
    position:absolute; width:1px; height:1px; padding:0; margin:-1px;
    overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0;
}

/* =========================================
   NARROWER SCREENS
========================================= */

/* The sidebar becomes a strip along the top whose items scroll sideways. That
   keeps every destination reachable with no script and no hidden menu. */
@media (max-width:1040px) {
    .app { grid-template-columns:1fr; }

    .side {
        position:static; max-height:none; flex-direction:row; align-items:center; gap:0;
        padding:0 14px; overflow-x:auto; border-right:0;
        border-bottom:1px solid rgba(198,165,107,.35);
    }

    .side-brand {
        flex-direction:row; align-items:center; gap:0; flex:0 0 auto;
        margin:0; padding:0 16px 0 4px; border-bottom:0;
    }

    .side-logo { width:28px; height:28px; margin:0 9px 0 0; }

    .side-kicker, .side-group, .side-foot { display:none; }

    .side-nav { display:flex; gap:2px; }

    .side-link {
        padding:14px 12px; border-left:0; border-bottom:2px solid transparent;
        font-size:13px; white-space:nowrap;
    }

    .side-link:hover { background:transparent; border-left-color:transparent;
                       border-bottom-color:rgba(198,165,107,.6); }

    .side-link.is-on { background:transparent; border-left-color:transparent;
                       border-bottom-color:var(--gold); }
}

@media (max-width:780px) {
    .login-card { grid-template-columns:1fr; max-width:460px; }
    .login-brand { padding:26px 26px 22px; }
    .login-points { display:none; }
    .login-form { padding:26px; }
    .stage { padding:26px 16px; }
    .bar { padding-top:20px; }
    .settings { grid-template-columns:minmax(0,1fr); }
}

@media (max-width:520px) {
    .card { padding:16px; }
    .actions { gap:8px; }
    .actions form.inline { display:block; width:100%; }
    .actions input.note { width:100%; min-width:0; margin-bottom:8px; }
    .actions button { width:100%; }
    .stats { grid-template-columns:1fr; }
    .page { padding-bottom:44px; }
}

@media (prefers-reduced-motion:reduce) {
    * { animation-duration:.01s !important; transition-duration:.01s !important; }
}
CSS;
}

/**
 * A submission stamp, written the way a person reads a date rather than the
 * way a database stores one.
 */
function kwetu_admin_when(?string $stamp): string
{
    $stamp = trim((string) $stamp);

    if ($stamp === '') {
        return 'no date recorded';
    }

    $time = strtotime($stamp);

    return $time === false ? $stamp : date('j M Y, H:i', $time);
}

/**
 * How long ago something happened, from its own clock reading.
 *
 * A distance rather than a time of day, on purpose: this machine's PHP is set
 * to Europe/Berlin while MySQL and the desktop run on Kampala time, so an
 * epoch formatted with date() lands an hour before the owner's own wall clock.
 * Submitted text is stored as a datetime by MySQL and printed straight back, so
 * it is unaffected — but a file's timestamp is an epoch, and counting the
 * seconds between two epochs is right in every timezone.
 */
function kwetu_admin_ago(?int $epoch): string
{
    if ($epoch === null || $epoch <= 0) {
        return 'no date recorded';
    }

    $seconds = max(0, time() - $epoch);

    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$size, $word]) {
        if ($seconds >= $size) {
            $count = intdiv($seconds, $size);

            return $count . ' ' . $word . ($count === 1 ? '' : 's') . ' ago';
        }
    }

    return 'a minute ago';
}

/**
 * The first line or two of a client's message, for a list that has to stay
 * scannable. The whole thing is always on the screen behind it.
 */
function kwetu_admin_excerpt(string $text, int $limit = 96): string
{
    $collapsed = trim((string) preg_replace('/\s+/u', ' ', $text));

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($collapsed, 0, $limit, '...');
    }

    return strlen($collapsed) > $limit ? substr($collapsed, 0, $limit) . '...' : $collapsed;
}

/**
 * The sidebar. Every label is a screen that exists, and every number beside
 * one is counted from the tables on the way in, so nothing here is a promise
 * the application cannot keep.
 */
function kwetu_admin_nav(string $current): string
{
    $counts = kwetu_admin_counts();

    $badge = static function ($number, bool $hot = false): string {
        // A badge is a summons: "there is something here for you". Nothing
        // waits when the count is zero, and the dashboard shows that zero
        // plainly, so an empty sidebar number would only be decoration.
        if ($number === null || (int) $number === 0) {
            return '';
        }

        return '<span class="badge' . ($hot && $number > 0 ? ' is-hot' : '') . '">'
            . (int) $number . '</span>';
    };

    $groups = [
        'Overview' => [
            ['dashboard', 'dashboard.php', 'Dashboard', null, false],
        ],
        'Messages' => [
            ['inbox', 'inbox.php', 'Contact enquiries', $counts['contact']['new'], true],
            ['quotes', 'inbox.php?kind=quote&status=all', 'Quote requests', $counts['quote']['new'], true],
        ],
        'Testimonials' => [
            ['review', 'review.php', 'Awaiting approval', $counts['testimonial']['pending'], true],
            ['approved', 'review.php?status=approved', 'Approved', $counts['testimonial']['approved'], false],
            ['rejected', 'review.php?status=rejected', 'Rejected', $counts['testimonial']['rejected'], false],
        ],
        'Publishing' => [
            ['export', 'export-approved.php', 'Approved text', null, false],
        ],
        'Account' => [
            ['settings', 'password.php', 'Change password', null, false],
        ],
        'Session' => [
            ['logout', 'logout.php', 'Sign out', null, false],
        ],
    ];

    $nav = '';

    foreach ($groups as $label => $items) {
        $nav .= '<p class="side-group">' . kwetu_e($label) . '</p>';

        foreach ($items as $item) {
            [$key, $href, $text, $number, $hot] = $item;
            $on = $key === $current;

            $nav .= '<a class="side-link' . ($on ? ' is-on' : '') . '" href="' . kwetu_e($href) . '"'
                . ($on ? ' aria-current="page"' : '') . '>'
                . '<span>' . kwetu_e($text) . '</span>' . $badge($number, $hot) . '</a>';
        }
    }

    return $nav;
}

/**
 * Wrap one screen in the shared desk, or in the sign-in stage when it is the
 * sign-in page and has no navigation to carry.
 */
function kwetu_admin_page(
    string $title,
    string $body,
    string $current = '',
    bool $withNav = true,
    string $subtitle = ''
): string {
    $pageTitle = kwetu_e($title);
    $barSub = $subtitle === '' ? 'Kwetu Creations review desk' : kwetu_e($subtitle);
    $style = kwetu_admin_styles();

    if ($withNav) {
        $nav = kwetu_admin_nav($current);

        $shell = <<<HTML
<a class="skip" href="#content">Skip to the page contents</a>
<div class="app">
    <aside class="side">
        <div class="side-brand">
            <img class="side-logo" src="../images/logo/mark.webp?v=3" alt="" width="120" height="120" decoding="async">
            <span class="wordmark">KWETU<span class="w-dot">.</span> <span class="w-red">CREATIONS</span></span>
            <span class="side-kicker">Review desk</span>
        </div>
        <nav class="side-nav" aria-label="Review sections">
{$nav}
        </nav>
        <p class="side-foot">Private screens. Nothing a client submitted is visible to a visitor until you approve it.</p>
    </aside>
    <div class="main">
        <header class="bar">
            <h1>{$pageTitle}</h1>
            <p class="bar-sub">{$barSub}</p>
        </header>
        <main class="page" id="content">
{$body}
        </main>
    </div>
</div>
HTML;
    } else {
        $shell = <<<HTML
<main class="stage" id="content">
{$body}
</main>
HTML;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title>{$pageTitle} | Kwetu Creations review</title>
<link rel="icon" type="image/png" sizes="32x32" href="../images/logo/favicon-32.png?v=4">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;display=swap">
<style>
{$style}
</style>
</head>
<body>
{$shell}
</body>
</html>
HTML;
}
