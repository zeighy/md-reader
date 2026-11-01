<?php
/**
 * A small page for people: pick the narration audio and its text, wait, get
 * the .vtt. It talks to the same API as any other client (same origin).
 * Included from api.php for GET / and GET /upload.
 */
declare(strict_types=1);

$nonce = base64_encode(random_bytes(16));
$cfg = config();
$api = base_url();
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; "
     . "connect-src 'self'; img-src data:; form-action 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Read-along timings</title>
<style nonce="<?= $e($nonce) ?>">
  :root {
    --bg: #f6f6f4; --card: #ffffff; --text: #1f2328; --muted: #646b73; --border: #dcdcd8;
    --accent: #1d5fd6; --on-accent: #fff; --ok: #1a7f37; --bad: #c4314b; --track: #e6e6e2;
    color-scheme: light;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #15171c; --card: #1d2027; --text: #e2e4e8; --muted: #9aa1ab; --border: #30343c;
      --accent: #7ea6ff; --on-accent: #10131a; --ok: #4ac26b; --bad: #ff7b8a; --track: #2b2f37;
      color-scheme: dark;
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; background: var(--bg); color: var(--text);
    font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    display: flex; justify-content: center; padding: 2.5rem 1rem;
  }
  main { width: 100%; max-width: 38rem; }
  h1 { font-size: 1.45rem; margin: 0 0 0.25rem; }
  .lead { color: var(--muted); margin: 0 0 1.5rem; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1.25rem; }
  .field { margin-bottom: 1.1rem; }
  .field > label, .label { display: block; font-weight: 600; margin-bottom: 0.35rem; }
  .hint { color: var(--muted); font-size: 0.85rem; margin-top: 0.3rem; }
  .field > .drop {
    display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1rem;
    border: 1.5px dashed var(--border); border-radius: 10px; cursor: pointer; transition: border-color .15s, background .15s;
  }
  .drop:hover, .drop.over { border-color: var(--accent); }
  .drop.has { border-style: solid; }
  .drop input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .drop .icon { flex: none; width: 2.2rem; height: 2.2rem; border-radius: 8px; display: grid; place-items: center;
                background: var(--track); color: var(--accent); font-size: 1.1rem; }
  .drop .what { min-width: 0; }
  .drop .name { display: block; font-weight: 600; overflow-wrap: anywhere; }
  .drop .sub { display: block; color: var(--muted); font-size: 0.85rem; }
  .or { display: flex; align-items: center; gap: 0.6rem; color: var(--muted); font-size: 0.85rem; margin: 0.6rem 0; }
  .or::before, .or::after { content: ""; flex: 1; border-top: 1px solid var(--border); }
  textarea, input[type=text], select {
    width: 100%; font: inherit; color: var(--text); background: var(--bg);
    border: 1px solid var(--border); border-radius: 8px; padding: 0.55rem 0.7rem;
  }
  textarea { min-height: 7rem; resize: vertical; font-size: 0.9rem; }
  details { margin-bottom: 1.1rem; }
  summary { cursor: pointer; color: var(--muted); font-size: 0.9rem; }
  details .field { margin: 0.8rem 0 0; }
  .field > .check { display: flex; gap: 0.5rem; align-items: flex-start; font-weight: 400; }
  button {
    font: inherit; font-weight: 600; border: 0; border-radius: 8px; padding: 0.7rem 1.2rem; cursor: pointer;
    background: var(--accent); color: var(--on-accent);
  }
  button:disabled { opacity: 0.45; cursor: default; }
  button.secondary { background: var(--track); color: var(--text); }
  .actions { margin-top: 1rem; }
  .row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
  .progress { height: 8px; background: var(--track); border-radius: 99px; overflow: hidden; margin: 0.9rem 0 0.4rem; }
  .bar { height: 100%; width: 0; background: var(--accent); transition: width .3s; }
  .bar.indeterminate { width: 30%; animation: slide 1.2s ease-in-out infinite; }
  @keyframes slide { from { margin-left: -30%; } to { margin-left: 100%; } }
  .stage { color: var(--muted); font-size: 0.9rem; }
  .status-title { font-weight: 700; font-size: 1.1rem; margin: 0; }
  .ok { color: var(--ok); } .bad { color: var(--bad); }
  dl { display: grid; grid-template-columns: auto 1fr; gap: 0.3rem 1rem; margin: 1rem 0; font-size: 0.92rem; }
  dt { color: var(--muted); } dd { margin: 0; }
  .note { font-size: 0.88rem; border-left: 3px solid var(--border); padding: 0.1rem 0 0.1rem 0.8rem; margin: 0.8rem 0; color: var(--muted); }
  .note ul { margin: 0.3rem 0 0; padding-left: 1.1rem; }
  [hidden] { display: none !important; }
  :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
</style>
</head>
<body>
<main>
  <h1>Read-along timings</h1>
  <p class="lead">Upload a narration and the text it reads. You’ll get a <code>.vtt</code> file with one timed cue per sentence.</p>

  <form id="form" class="card" novalidate>
    <div class="field">
      <span class="label" id="audio-label">Narration audio</span>
      <label class="drop" id="audio-drop">
        <input type="file" id="audio" accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.webm" aria-labelledby="audio-label">
        <span class="icon" aria-hidden="true">♪</span>
        <span class="what"><span class="name">Choose an MP3 (or drop it here)</span>
        <span class="sub">MP3, M4A, WAV, OGG/Opus, FLAC · up to <?= $e($cfg['max_upload_mb']) ?> MB</span></span>
      </label>
    </div>

    <div class="field">
      <span class="label" id="text-label">Text that’s narrated</span>
      <label class="drop" id="text-drop">
        <input type="file" id="textfile" accept=".md,.markdown,.txt,text/markdown,text/plain" aria-labelledby="text-label">
        <span class="icon" aria-hidden="true">¶</span>
        <span class="what"><span class="name">Choose the chapter’s .md or .txt</span>
        <span class="sub">The same file the viewer shows</span></span>
      </label>
      <div class="or">or paste it</div>
      <textarea id="textpaste" placeholder="Paste the text here…" aria-label="Paste the narrated text"></textarea>
      <div class="hint">Titles, captions and intro lines that aren’t read aloud are left out automatically.</div>
    </div>

    <details>
      <summary>Options</summary>
      <div class="field">
        <label for="name">File name for the result</label>
        <input type="text" id="name" maxlength="200" placeholder="Defaults to the audio’s name">
        <div class="hint">Name the .vtt like the .md it belongs to, e.g. “01 - Four Degrees”.</div>
      </div>
      <div class="field">
        <label for="format">Text format</label>
        <select id="format"><option value="">Automatic</option><option value="markdown">Markdown</option><option value="text">Plain text</option></select>
      </div>
      <div class="field">
        <label class="check"><input type="checkbox" id="keepall"> Keep every sentence, even headings or quotes that aren’t narrated</label>
      </div>
      <div class="field">
        <label for="pron">Pronunciations (rarely needed)</label>
        <textarea id="pron" placeholder="torvin T AO R V IH N" spellcheck="false"></textarea>
        <div class="hint">One per line, CMU/ARPAbet phones. Unknown names get pronunciations automatically.</div>
      </div>
    </details>

    <div class="row">
      <button type="submit" id="go" disabled>Create .vtt</button>
      <span class="stage" id="form-msg" role="status"></span>
    </div>
  </form>

  <section id="job" class="card" hidden aria-live="polite">
    <p class="status-title" id="job-title">Uploading…</p>
    <div class="progress"><div class="bar" id="bar"></div></div>
    <div class="stage" id="job-stage"></div>

    <div id="result" hidden>
      <dl id="facts"></dl>
      <div class="note" id="dropped" hidden></div>
      <div class="note" id="flagged" hidden></div>
    </div>

    <div class="row actions">
      <button type="button" id="download" hidden>Download .vtt</button>
      <button type="button" class="secondary" id="again" hidden>Make another</button>
    </div>
  </section>
</main>

<script nonce="<?= $e($nonce) ?>">
(function () {
  'use strict';
  var API = <?= json_encode($api, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  var MAX_MB = <?= (int)$cfg['max_upload_mb'] ?>;
  var $ = function (id) { return document.getElementById(id); };
  var form = $('form'), go = $('go'), msg = $('form-msg');
  var audio = $('audio'), textfile = $('textfile'), paste = $('textpaste');

  // ---- picking files (click or drop) ----------------------------------
  function bindDrop(drop, input, emptyText) {
    function show() {
      var f = input.files[0];
      drop.classList.toggle('has', !!f);
      drop.querySelector('.name').textContent = f ? f.name : emptyText;
      if (f) drop.querySelector('.sub').textContent = size(f.size);
      ready();
    }
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (t) {
      drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      drop.addEventListener(t, function () { drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); }
    });
  }
  bindDrop($('audio-drop'), audio, 'Choose an MP3 (or drop it here)');
  bindDrop($('text-drop'), textfile, 'Choose the chapter’s .md or .txt');
  paste.addEventListener('input', ready);

  function ready() {
    var a = audio.files[0], hasText = textfile.files[0] || paste.value.trim();
    var problem = '';
    if (a && a.size > MAX_MB * 1048576) problem = 'That audio is over ' + MAX_MB + ' MB.';
    msg.textContent = problem;
    msg.className = 'stage' + (problem ? ' bad' : '');
    go.disabled = !a || !hasText || !!problem;
  }

  // ---- submitting -------------------------------------------------------
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (go.disabled) return;
    var a = audio.files[0];
    var fd = new FormData();
    fd.append('audio', a);
    if (textfile.files[0]) fd.append('text', textfile.files[0]);
    else fd.append('text', paste.value);
    var name = $('name').value.trim() || a.name.replace(/\.[^.]+$/, '');
    fd.append('name', name.slice(0, 200));
    if ($('format').value) fd.append('format', $('format').value);
    else if (!textfile.files[0]) fd.append('format', /^\s*(#|>|!\[|\* \* \*)/m.test(paste.value) ? 'markdown' : 'text');
    if ($('keepall').checked) fd.append('drop_unread', 'false');
    if ($('pron').value.trim()) fd.append('pronunciations', $('pron').value);

    showJob();
    setStage('Uploading…', 'Sending ' + size(a.size), 0);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API + '/jobs');
    xhr.upload.onprogress = function (ev) {
      if (ev.lengthComputable) setStage('Uploading…', Math.round(100 * ev.loaded / ev.total) + '% sent', 100 * ev.loaded / ev.total);
    };
    xhr.onload = function () {
      var d = parse(xhr.responseText);
      if (xhr.status === 202 && d && d.id) { remember(d.id); poll(d.id); }
      else failed(errorText(d, xhr.status));
    };
    xhr.onerror = function () { failed('The upload didn’t reach the server.'); };
    xhr.send(fd);
  });

  // ---- following the job ------------------------------------------------
  var STAGES = {
    'queued': 'Waiting in line', 'starting': 'Starting', 'preparing text': 'Reading the text',
    'decoding audio': 'Decoding the audio', 'building vocabulary': 'Learning the names and words',
    'transcribing': 'Listening to the narration', 'aligning': 'Matching sentences to the audio'
  };

  function poll(id) {
    fetch(API + '/jobs/' + id, { cache: 'no-store' }).then(function (r) {
      return r.json().then(function (d) { return { ok: r.ok, status: r.status, d: d }; });
    }).then(function (r) {
      if (!r.ok) { forget(); return failed(errorText(r.d, r.status)); }
      var d = r.d, p = d.progress || {};
      if (d.status === 'queued') {
        setStage('Queued', d.queue_position > 1 ? 'Position ' + d.queue_position + ' in line' : 'Starting shortly', null);
      } else if (d.status === 'running') {
        var label = STAGES[(p.stage || '').replace(/ \(.*\)$/, '')] || p.stage || 'Working';
        setStage('Working…', label + (p.percent != null ? ' · ' + p.percent + '%' : ''),
                 p.stage === 'transcribing' ? 10 + 0.85 * p.percent : (p.stage === 'aligning' ? 97 : null));
      } else if (d.status === 'done') {
        forget(); return done(d);
      } else {
        forget(); return failed(d.error || 'The job failed.');
      }
      setTimeout(function () { poll(id); }, 2000);
    }).catch(function () {
      setStage('Reconnecting…', 'Lost contact with the server; retrying', null);
      setTimeout(function () { poll(id); }, 4000);
    });
  }

  function done(d) {
    var r = d.report || {}, q = r.quality || {};
    $('job-title').textContent = 'Done';
    $('job-title').className = 'status-title ok';
    setBar(100);
    $('job-stage').textContent = (d.name || 'Your file') + '.vtt is ready.';
    var facts = [
      ['Sentences timed', r.cues],
      ['Audio length', fmt(r.audio_seconds)],
      ['Processing time', fmt(r.processing_seconds)],
      ['Words recognised', r.words_matched_pct != null ? r.words_matched_pct + '%' : '–'],
      ['Match quality', q.median_match != null ? q.median_match + (q.median_match >= 0.85 ? ' (good)' : ' (check a few spots)') : '–']
    ];
    $('facts').innerHTML = '';
    facts.forEach(function (f) {
      var dt = document.createElement('dt'), dd = document.createElement('dd');
      dt.textContent = f[0]; dd.textContent = f[1] == null ? '–' : f[1];
      $('facts').append(dt, dd);
    });
    list($('dropped'), 'Left out because they weren’t read aloud:', r.dropped_unread || []);
    list($('flagged'), 'Worth a listen (audio sounded like the neighbouring sentence):',
         (q.flagged || []).map(function (f) { return fmt(f.start) + ' – ' + f.text; }));
    $('result').hidden = false;
    var dl = $('download');
    dl.hidden = false;
    dl.onclick = function () { download(d.links.vtt); };
    $('again').hidden = false;
    download(d.links.vtt);   // start the download straight away
  }

  function download(url) {
    var a = document.createElement('a');
    a.href = url;
    a.download = '';
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  function failed(text) {
    showJob();
    $('job-title').textContent = 'Couldn’t make the timings';
    $('job-title').className = 'status-title bad';
    setBar(0);
    $('job-stage').textContent = text;
    $('again').hidden = false;
    $('again').textContent = 'Try again';
  }

  $('again').addEventListener('click', function () {
    $('job').hidden = true;
    form.hidden = false;
    go.focus();
  });

  // ---- little helpers ---------------------------------------------------
  function showJob() {
    form.hidden = true;
    $('job').hidden = false;
    $('result').hidden = true;
    $('download').hidden = true;
    $('again').hidden = true;
    $('again').textContent = 'Make another';
    $('job-title').className = 'status-title';
  }
  function setStage(title, detail, pct) {
    $('job-title').textContent = title;
    $('job-stage').textContent = detail;
    setBar(pct);
  }
  function setBar(pct) {
    var bar = $('bar');
    bar.classList.toggle('indeterminate', pct == null);
    bar.style.width = pct == null ? '' : Math.max(2, Math.min(100, pct)) + '%';
  }
  function list(el, title, items) {
    el.hidden = !items.length;
    el.textContent = '';
    if (!items.length) return;
    el.append(title);
    var ul = document.createElement('ul');
    items.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.append(li); });
    el.append(ul);
  }
  function fmt(s) {
    if (s == null || isNaN(s)) return '–';
    s = Math.round(s);
    var m = Math.floor(s / 60), sec = s % 60;
    return m ? m + ' min ' + (sec < 10 ? '0' : '') + sec + ' s' : sec + ' s';
  }
  function size(b) { return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1) + ' MB'; }
  function parse(t) { try { return JSON.parse(t); } catch (e) { return null; } }
  function errorText(d, status) {
    if (d && d.error && d.error.message) return d.error.message;
    if (status === 413) return 'The file is larger than the server accepts.';
    return 'The server answered with an error (' + status + ').';
  }
  // If the page is reloaded mid-job, pick the job back up.
  function remember(id) { try { sessionStorage.setItem('vtt-job', id); } catch (e) {} }
  function forget() { try { sessionStorage.removeItem('vtt-job'); } catch (e) {} }
  var pending = null;
  try { pending = sessionStorage.getItem('vtt-job'); } catch (e) {}
  if (pending && /^[a-f0-9]{32}$/.test(pending)) { showJob(); setStage('Checking…', '', null); poll(pending); }
})();
</script>
</body>
</html>
