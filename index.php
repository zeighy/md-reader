<?php
declare(strict_types=1);
define('MDV', true);
require __DIR__ . '/lib.php';

security_headers();
require_get();
$nonce = base64_encode(random_bytes(18));
page_csp($nonce);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

$file  = query_param('file');                 // null = malformed input
$title = (string)$CFG['site_title'];
$defaultTheme = in_array($CFG['default_theme'], theme_ids(), true) ? $CFG['default_theme'] : 'auto';
[$status, $docHtml, $currentRel] = render_fragment($CFG, $ROOT, $file);
http_response_code($status);

// htmx hardening: no eval, never run <script> from swapped content, only talk to this site,
// and still swap our 4xx notice fragments into the page.
$htmxConfig = [
    'allowEval'              => false,
    'allowScriptTags'        => false,
    'selfRequestsOnly'       => true,
    'includeIndicatorStyles' => false,
    'refreshOnHistoryMiss'   => true,
    'historyCacheSize'       => 10,
    'responseHandling'       => [
        ['code' => '204', 'swap' => false],
        ['code' => '[23]..', 'swap' => true],
        ['code' => '4..', 'swap' => true, 'error' => true],
        ['code' => '...', 'swap' => false, 'error' => true],
    ],
];
$JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#fcfcfb" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#15171c" media="(prefers-color-scheme: dark)">
<meta name="referrer" content="same-origin">
<meta name="htmx-config" content="<?= h(json_encode($htmxConfig, $JSON)) ?>">
<title><?= h($title) ?></title>
<script nonce="<?= h($nonce) ?>">
  // Apply saved reading preferences before first paint so nothing flashes.
  (function () {
    var d = document.documentElement, get = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
    var t = get('mdv-theme');
    if (<?= json_encode(theme_ids(), $JSON) ?>.indexOf(t) < 0) t = <?= json_encode($defaultTheme, $JSON) ?>;
    if (t !== 'auto') d.setAttribute('data-theme', t);
    var fs = parseInt(get('mdv-font-size'), 10);
    if (fs >= 12 && fs <= 30) d.style.setProperty('--fs', fs + 'px');
    if (get('mdv-dyslexic') === '1') d.setAttribute('data-dyslexic', '');
  })();
</script>
<link rel="stylesheet" href="style.css">
<!-- Pinned versions with Subresource Integrity: a modified CDN file is refused by the browser. -->
<script src="https://unpkg.com/htmx.org@2.0.4/dist/htmx.min.js" integrity="sha384-HGfztofotfshcF7+8n44JQL2oJmowVChPTg48S+jvZoztPfvwD79OC/LTtG6dMp+" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@15.0.7/marked.min.js" integrity="sha384-H+hy9ULve6xfxRkWIh/YOtvDdpXgV2fmAGQkIDTxIgZwNoaoBal14Di2YTMR6MzR" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.2.4/dist/purify.min.js" integrity="sha384-eEu5CTj3qGvu9PdJuS+YlkNi7d2XxQROAFYOr59zgObtlcux1ae1Il3u7jvdCSWu" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body data-site-title="<?= h($title) ?>">

<header id="topbar">
  <button id="nav-toggle" aria-label="Open file list and settings" aria-controls="sidebar" aria-expanded="false">
    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
  </button>
  <span id="topbar-title"><?= h($title) ?></span>
</header>

<div id="scrim" hidden></div>

<aside id="sidebar">
  <header>
    <a class="brand" href="index.php"><?= h($title) ?></a>

    <div class="controls">
      <div class="control">
        <span class="label" id="size-label">Text size</span>
        <div class="stepper" role="group" aria-labelledby="size-label">
          <button type="button" id="font-down" aria-label="Smaller text">A−</button>
          <button type="button" id="font-reset" aria-label="Reset text size" title="Reset">100%</button>
          <button type="button" id="font-up" aria-label="Larger text">A+</button>
        </div>
      </div>

      <label class="control">
        <span class="label">Theme</span>
        <select id="theme"><?= theme_options() ?></select>
      </label>

      <label class="control switch">
        <span class="label">Dyslexia-friendly font</span>
        <input type="checkbox" id="dyslexic" role="switch">
        <span class="track" aria-hidden="true"></span>
      </label>
    </div>
  </header>

  <nav id="tree" aria-label="Documents" hx-target="#content" hx-swap="innerHTML show:window:top">
    <?= build_tree($ROOT, $currentRel) ?: '<p class="empty">No documents found.</p>' ?>
  </nav>
</aside>

<!-- hx-history-elt: Back/Forward restore only the document, so the audio player keeps playing. -->
<main id="content" hx-history-elt>
  <?= $docHtml ?>
</main>

<!-- Audio player: lives outside #content so it survives moving between pages. -->
<section id="player" aria-label="Audio player" hidden>
  <audio id="pl-audio" preload="metadata"></audio>
  <button type="button" id="pl-hide" class="pl-handle" aria-label="Hide player" title="Hide player">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
  </button>

  <div class="pl-main">
    <button type="button" id="pl-title" class="pl-title" title="Go to this page"></button>
    <div class="pl-seek">
      <span id="pl-cur" class="pl-time">0:00</span>
      <input type="range" id="pl-seekbar" min="0" max="0" step="0.1" value="0" aria-label="Playback position">
      <span id="pl-dur" class="pl-time">0:00</span>
    </div>
  </div>

  <div class="pl-transport">
    <button type="button" id="pl-back" class="pl-btn pl-skip" aria-label="Back 15 seconds" title="Back 15 seconds">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7M4 4v4h4"/></svg><span>15</span>
    </button>
    <button type="button" id="pl-play" class="pl-btn pl-play" aria-label="Play">
      <svg class="i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
      <svg class="i-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
    </button>
    <button type="button" id="pl-fwd" class="pl-btn pl-skip" aria-label="Forward 15 seconds" title="Forward 15 seconds">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.4-5.7M20 4v4h-4"/></svg><span>15</span>
    </button>
    <button type="button" id="pl-next" class="pl-btn" aria-label="Next page and its audio" title="Next page">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5.5v13l9-6.5zM17 5h2.5v14H17z"/></svg>
    </button>
  </div>

  <div class="pl-options">
    <select id="pl-rate" aria-label="Playback speed" title="Playback speed">
      <option value="0.75">0.75×</option>
      <option value="1" selected>1×</option>
      <option value="1.25">1.25×</option>
      <option value="1.5">1.5×</option>
      <option value="1.75">1.75×</option>
      <option value="2">2×</option>
    </select>
    <div class="pl-volume">
      <button type="button" id="pl-mute" class="pl-btn" aria-label="Mute" aria-pressed="false" title="Mute">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path class="spk" d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/>
          <path class="w1" d="M15.5 9.5a3.5 3.5 0 0 1 0 5"/>
          <path class="w2" d="M18 7a7 7 0 0 1 0 10"/>
          <path class="x" d="M16 9.5l5 5M21 9.5l-5 5"/>
        </svg>
      </button>
      <input type="range" id="pl-volume" min="0" max="1" step="0.05" value="1" aria-label="Volume" title="Volume">
    </div>
    <button type="button" id="pl-readalong" class="pl-btn pl-toggle" aria-pressed="true" title="Highlight the sentence being read">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 18h10"/><rect x="3" y="9.5" width="18" height="5" rx="1.5"/></svg>
      <span>Read-along</span>
    </button>
  </div>
</section>

<button type="button" id="player-tab" aria-label="Show audio player" title="Show audio player" hidden>
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="16.5" cy="16" r="2.5"/></svg>
  <span class="pl-live" aria-hidden="true"></span>
</button>

<div id="player-notices" aria-live="polite"></div>

<dialog id="lightbox" aria-label="Image viewer">
  <figure>
    <img alt="">
    <figcaption></figcaption>
  </figure>
  <span class="lb-count" aria-live="polite"></span>
  <button type="button" class="lb-btn lb-close" aria-label="Close">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <button type="button" class="lb-btn lb-prev" aria-label="Previous image">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>
  </button>
  <button type="button" class="lb-btn lb-next" aria-label="Next image">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
  </button>
</dialog>

<script src="app.js"></script>
<script src="player.js"></script>
</body>
</html>
