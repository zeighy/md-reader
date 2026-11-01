/*
 * Audio narration player with sentence read-along.
 *
 * A document gets a player when an audio file with the same name sits next to
 * it (chapter.md + chapter.mp3). An optional chapter.vtt (WebVTT, one cue per
 * sentence) turns on read-along highlighting.
 *
 * Behaviour:
 *  - The player lives outside #content, so it keeps playing while you browse.
 *  - Open another page that has audio while something is playing: a prompt
 *    offers to switch. Nothing changes unless you accept.
 *  - Open a page with no audio while something is playing: playback carries on
 *    and a short note says the page has no audio.
 *  - Nothing playing: a page with audio loads it ready to play; a page without
 *    audio keeps the player out of sight.
 *  - The player's Next button goes to the page after the one being listened to
 *    and starts its audio. The Next link at the bottom of a page only changes
 *    the page.
 *  - When audio ends it stops and waits.
 */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var root = document.documentElement;
  var bar = $('player'), audio = $('pl-audio'), tab = $('player-tab'), notices = $('player-notices');
  var playBtn = $('pl-play'), titleBtn = $('pl-title'), seekBar = $('pl-seekbar');
  var curEl = $('pl-cur'), durEl = $('pl-dur'), rateSel = $('pl-rate');
  var raBtn = $('pl-readalong'), nextBtn = $('pl-next');
  if (!bar || !audio) return;

  var SITE = document.body.getAttribute('data-site-title') || '';
  var NO_AUDIO = 'This page has no accompanying audio file';
  var reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  // CSS Custom Highlight API: highlights text without touching the page's HTML.
  var HL = (window.CSS && CSS.highlights && window.Highlight) ? new Highlight() : null;
  if (HL) CSS.highlights.set('mdv-readalong', HL);

  function getPref(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function setPref(k, v) {
    try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* private mode */ }
  }
  function encPath(p) { return encodeURIComponent(p).replace(/%2F/g, '/'); }
  function assetUrl(rel) { return 'api.php?action=asset&file=' + encPath(rel); }

  // ---- State ---------------------------------------------------------------
  var page = { file: '' };   // the document on screen
  var track = null;          // the audio loaded in the player (and the document it belongs to)
  var cues = [];             // [{start, end, text}] from the track's .vtt
  var ranges = [];           // a DOM Range per cue, or null where the sentence wasn't found
  var rangesFor = null;      // which document `ranges` point into
  var activeCue = -1;
  var wanted = false;        // should the player be on screen at all
  var following = true;      // read-along keeps the current sentence in view
  var lastUserScroll = 0;
  var dragging = false;
  var pendingNoAudioNote = false;
  var navLink = null;
  var vttCache = {};

  var userHidden = getPref('mdv-player-hidden') === '1';
  var readAlong = getPref('mdv-readalong') !== '0';
  var rate = parseFloat(getPref('mdv-rate'));
  rateSel.value = String(rate);
  if (!rate || rateSel.value !== String(rate)) { rate = 1; rateSel.value = '1'; }

  // What the server told us about a document (data-* on <article class="doc">).
  function docInfo(article) {
    if (!article) return { file: '' };
    var g = function (a) { return article.getAttribute(a) || ''; };
    var h1 = article.querySelector('.md-body h1');
    return {
      file: g('data-file'), label: h1 ? h1.textContent.trim() : g('data-label'),
      audio: g('data-audio'), vtt: g('data-vtt'),
      next: g('data-next'), nextLabel: g('data-next-label'),
      nextAudio: g('data-next-audio'), nextVtt: g('data-next-vtt'),
      article: article
    };
  }

  function isPlaying() { return !!track && !audio.paused && !audio.ended; }

  // ---- Loading and playing -------------------------------------------------
  function loadTrack(t, autoplay) {
    track = {
      file: t.file, label: t.label || t.file, audio: t.audio, vtt: t.vtt,
      next: t.next || '', nextLabel: t.nextLabel || '', nextAudio: t.nextAudio || '', nextVtt: t.nextVtt || ''
    };
    cues = []; ranges = []; rangesFor = null; activeCue = -1; clearHighlight();
    audio.src = assetUrl(t.audio);
    audio.defaultPlaybackRate = rate;
    audio.playbackRate = rate;
    seekBar.value = 0; seekBar.max = 0;
    curEl.textContent = durEl.textContent = '0:00';
    following = true;
    wanted = true;
    if (autoplay) play();          // called straight from a tap, so phones allow it
    if (t.vtt) loadCues(t.vtt, track);
    setMediaSession();
    updateUI();
  }

  function play() {
    var p = audio.play();
    if (p && p.catch) p.catch(function () { updateUI(); });
  }

  function unload() {
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
    track = null; cues = []; ranges = []; rangesFor = null; activeCue = -1;
    clearHighlight();
    setMediaSession();
  }

  // ---- WebVTT --------------------------------------------------------------
  function parseTime(s) {
    var m = String(s).trim().match(/^(?:(\d+):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?/);
    if (!m) return NaN;
    return (+(m[1] || 0)) * 3600 + (+m[2]) * 60 + (+m[3]) + (m[4] ? +('0.' + m[4]) : 0);
  }

  function parseVTT(text) {
    var out = [];
    text.replace(/\r\n?/g, '\n').split(/\n{2,}/).forEach(function (block) {
      var lines = block.split('\n');
      var i = -1;
      for (var k = 0; k < lines.length; k++) if (lines[k].indexOf('-->') >= 0) { i = k; break; }
      if (i < 0 || /^NOTE\b/.test(lines[0])) return;
      var parts = lines[i].split('-->');
      var s = parseTime(parts[0]), e = parseTime(parts[1]);
      if (isNaN(s) || isNaN(e)) return;
      var txt = lines.slice(i + 1).join(' ')
        .replace(/<[^>]*>/g, '')
        .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&')
        .trim();
      if (txt) out.push({ start: s, end: Math.max(e, s), text: txt });
    });
    return out.sort(function (a, b) { return a.start - b.start; });
  }

  function loadCues(vtt, forTrack) {
    var url = assetUrl(vtt);
    var p = vttCache[url] || (vttCache[url] = fetch(url, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(parseVTT)
      .catch(function () { delete vttCache[url]; return []; }));
    p.then(function (list) {
      if (track !== forTrack) return; // the user moved on while it loaded
      cues = list;
      buildRanges();
      updateUI();
      syncCue(true);
    });
  }

  // ---- Matching sentences to the page --------------------------------------
  // Text is compared as lower-case letters and digits only, so punctuation,
  // curly vs straight quotes, accents, spacing and line breaks don't matter.
  var NON_WORD = /[^\p{L}\p{N}]/gu, MARKS = /\p{M}/gu;
  function norm(s) { return s.normalize('NFKD').replace(MARKS, '').toLowerCase().replace(NON_WORD, ''); }

  // Flatten the document's visible text, remembering where each character came from.
  // Code blocks are skipped: narration rarely reads them.
  function indexText(container) {
    var chars = [], nodes = [], offs = [], lens = [];
    var walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        return n.parentElement && n.parentElement.closest('pre, script, style')
          ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
      }
    });
    var n;
    while ((n = walker.nextNode())) {
      var t = n.nodeValue;
      for (var i = 0; i < t.length;) {
        var ch = String.fromCodePoint(t.codePointAt(i));
        var k = norm(ch);
        for (var j = 0; j < k.length; j++) { chars.push(k[j]); nodes.push(n); offs.push(i); lens.push(ch.length); }
        i += ch.length;
      }
    }
    return { str: chars.join(''), nodes: nodes, offs: offs, lens: lens };
  }

  function findNear(S, needle, from, within) {
    var i = S.indexOf(needle, from);
    return (i >= 0 && i - from <= within) ? i : -1;
  }

  function makeRange(idx, s, e) {
    var sn = idx.nodes[s], so = idx.offs[s];
    var en = idx.nodes[e - 1], eo = idx.offs[e - 1] + idx.lens[e - 1];
    // Take in an opening quote or bracket, and the closing punctuation.
    var st = sn.nodeValue, et = en.nodeValue;
    while (so > 0 && /["'“‘(\[«¿¡]/.test(st[so - 1])) so--;
    while (eo < et.length && /[^\s\p{L}\p{N}]/u.test(et[eo])) eo++;
    var r = document.createRange();
    r.setStart(sn, so);
    r.setEnd(en, eo);
    return r;
  }

  // Walk the cues in order and find each one in the text after the previous
  // match. Exact match first; failing that, match by the start and end of the
  // sentence, which copes with small wording differences. Sentences that can't
  // be found (e.g. narration that isn't on the page) just aren't highlighted.
  function buildRanges() {
    ranges = []; rangesFor = null; activeCue = -1; clearHighlight();
    if (!track || page.file !== track.file || !cues.length || !page.article) return;
    var body = page.article.querySelector('.md-body');
    if (!body) return;
    var idx = indexText(body), S = idx.str, pos = 0, WINDOW = 6000;
    cues.forEach(function (cue) {
      var n = norm(cue.text), start = -1, end = -1;
      if (n) {
        var at = findNear(S, n, pos, WINDOW);
        if (at >= 0) { start = at; end = at + n.length; }
        else {
          var k = Math.min(24, n.length);
          var head = findNear(S, n.slice(0, k), pos, WINDOW);
          if (head >= 0) {
            var tail = findNear(S, n.slice(-k), head, n.length * 2 + 40);
            start = head;
            end = tail >= 0 ? tail + k : Math.min(S.length, head + n.length);
          } else {
            var t2 = findNear(S, n.slice(-k), pos, WINDOW);
            if (t2 >= 0) { end = t2 + k; start = Math.max(pos, end - n.length); }
          }
        }
      }
      if (start >= 0 && end > start) { ranges.push(makeRange(idx, start, end)); pos = end; }
      else ranges.push(null);
    });
    rangesFor = track.file;
  }

  // ---- Read-along highlighting ---------------------------------------------
  function clearHighlight() { if (HL) HL.clear(); }

  function readAlongActive() {
    return !!(readAlong && HL && track && cues.length && rangesFor === track.file && page.file === track.file);
  }

  // The cue being spoken: the last one that has started, unless we're in a
  // long pause after it.
  function cueAt(t) {
    var lo = 0, hi = cues.length - 1, found = -1;
    while (lo <= hi) {
      var mid = (lo + hi) >> 1;
      if (cues[mid].start <= t + 0.05) { found = mid; lo = mid + 1; } else hi = mid - 1;
    }
    return (found >= 0 && t > cues[found].end + 1) ? -1 : found;
  }

  function syncCue(force) {
    if (!readAlongActive()) {
      activeCue = -1;
      clearHighlight();
      hideFollow();
      return;
    }
    var i = cueAt(audio.currentTime);
    if (i === activeCue && !force) return;
    activeCue = i;
    var r = i >= 0 ? ranges[i] : null;
    HL.clear();
    if (r) HL.add(r);
    if (!r) return;
    if (following && Date.now() - lastUserScroll > 1500) reveal(r);
    else updateFollow();
  }

  // The part of the screen not covered by the phone top bar or the player.
  function viewBounds() {
    var top = 0, tb = $('topbar');
    if (tb && tb.offsetHeight) top = Math.max(0, tb.getBoundingClientRect().bottom);
    var bottom = window.innerHeight;
    if (!bar.hidden) bottom = Math.min(bottom, bar.getBoundingClientRect().top);
    return { top: top + 12, bottom: bottom - 12 };
  }

  function inView(r) {
    var b = r.getBoundingClientRect(), v = viewBounds();
    return b.top >= v.top && b.bottom <= v.bottom;
  }

  function reveal(r) {
    if (!inView(r)) {
      var b = r.getBoundingClientRect(), v = viewBounds();
      var y = window.scrollY + b.top - v.top - (v.bottom - v.top) * 0.25;
      window.scrollTo({ top: Math.max(0, y), behavior: reduceMotion ? 'auto' : 'smooth' });
    }
    hideFollow();
  }

  // Scrolling by hand pauses follow mode until the sentence is back in view
  // (or "Back to reading position" is pressed).
  function userScrolled() {
    lastUserScroll = Date.now();
    if (readAlongActive() && activeCue >= 0) { following = false; requestAnimationFrame(updateFollow); }
  }
  window.addEventListener('wheel', userScrolled, { passive: true });
  window.addEventListener('touchmove', userScrolled, { passive: true });
  window.addEventListener('keydown', function (e) {
    if (['PageUp', 'PageDown', 'ArrowUp', 'ArrowDown', 'Home', 'End', ' '].indexOf(e.key) < 0) return;
    if (e.target.closest && e.target.closest('input, select, textarea, button, dialog')) return;
    userScrolled();
  });
  var scrollQueued = false;
  window.addEventListener('scroll', function () {
    if (following || scrollQueued) return;
    scrollQueued = true;
    requestAnimationFrame(function () { scrollQueued = false; updateFollow(); });
  }, { passive: true });

  function updateFollow() {
    var r = activeCue >= 0 ? ranges[activeCue] : null;
    if (following || !r || !readAlongActive()) { hideFollow(); return; }
    if (inView(r)) { following = true; hideFollow(); return; }
    showFollow(r.getBoundingClientRect().top < viewBounds().top ? '↑' : '↓');
  }

  // Tap a sentence to jump the audio there.
  $('content').addEventListener('click', function (e) {
    if (!readAlongActive()) return;
    var t = e.target;
    if (!t.closest('article.doc .md-body')) return;
    if (t.closest('a, button, img, input, select, textarea, summary, label')) return;
    var sel = window.getSelection && getSelection();
    if (sel && !sel.isCollapsed) return; // they're selecting text, not jumping
    var pos = caretAt(e.clientX, e.clientY);
    if (!pos) return;
    for (var i = 0; i < ranges.length; i++) {
      try {
        if (ranges[i] && ranges[i].isPointInRange(pos.node, pos.offset)) {
          audio.currentTime = cues[i].start + 0.01;
          following = true;
          syncCue(true);
          return;
        }
      } catch (x) { /* point in another document */ }
    }
  });

  function caretAt(x, y) {
    if (document.caretPositionFromPoint) {
      var p = document.caretPositionFromPoint(x, y);
      return p && { node: p.offsetNode, offset: p.offset };
    }
    if (document.caretRangeFromPoint) {
      var r = document.caretRangeFromPoint(x, y);
      return r && { node: r.startContainer, offset: r.startOffset };
    }
    return null;
  }

  // ---- Notices: switch prompt, no-audio note, back to reading position -----
  var promptEl = null, toastEl = null, toastTimer = 0, followEl = null;

  function notice(cls, text, buttons) {
    var el = document.createElement('div');
    el.className = 'pl-notice ' + cls;
    if (text) {
      var span = document.createElement('span');
      span.textContent = text;
      el.appendChild(span);
    }
    (buttons || []).forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = b.text;
      if (b.cls) btn.className = b.cls;
      if (b.label) btn.setAttribute('aria-label', b.label);
      btn.addEventListener('click', b.onClick);
      el.appendChild(btn);
    });
    notices.appendChild(el);
    return el;
  }

  function showToast(text) {
    hideToast();
    toastEl = notice('pl-toast', text);
    toastTimer = setTimeout(hideToast, 4000);
  }
  function hideToast() {
    clearTimeout(toastTimer);
    if (toastEl) toastEl.remove();
    toastEl = null;
  }

  function showPrompt() {
    hidePrompt();
    var here = page;
    promptEl = notice('pl-prompt', 'This page has its own audio.', [
      { text: 'Play it', cls: 'pl-primary', onClick: function () { hidePrompt(); loadTrack(here, true); } },
      { text: '✕', label: 'Keep listening to the current audio', onClick: hidePrompt }
    ]);
  }
  function hidePrompt() {
    if (promptEl) promptEl.remove();
    promptEl = null;
  }

  function showFollow(arrow) {
    if (!followEl) {
      followEl = notice('pl-follow', '', [{
        text: '', cls: 'pl-primary',
        onClick: function () {
          following = true;
          var r = activeCue >= 0 ? ranges[activeCue] : null;
          if (r) reveal(r);
          hideFollow();
        }
      }]);
    }
    followEl.querySelector('button').textContent = arrow + ' Back to reading position';
  }
  function hideFollow() {
    if (followEl) followEl.remove();
    followEl = null;
  }

  // ---- Page changes --------------------------------------------------------
  function onPage(p) {
    page = p;
    hidePrompt();
    hideFollow();
    var noteDue = pendingNoAudioNote;
    pendingNoAudioNote = false;

    if (track && p.file && p.file === track.file) {
      // On the page being listened to (arrived here, or came back).
      track.label = p.label || track.label;
      track.next = p.next; track.nextLabel = p.nextLabel;
      track.nextAudio = p.nextAudio; track.nextVtt = p.nextVtt;
      wanted = true;
      setMediaSession();
    } else if (isPlaying()) {
      // Something else is playing: keep it going.
      wanted = true;
      if (p.audio) showPrompt();
      else showToast(NO_AUDIO);
    } else if (p.audio) {
      // Nothing playing: get this page's audio ready, but wait for the user.
      loadTrack(p, false);
    } else {
      // Nothing playing and nothing to play: keep out of the way.
      wanted = false;
      if (noteDue) showToast(NO_AUDIO);
    }

    following = true;
    buildRanges();
    updateUI();
    syncCue(true);
  }

  document.addEventListener('mdv:page', function (e) { onPage(docInfo(e.detail && e.detail.article)); });

  // Open a page the same way the site's own links do (htmx swap + history entry).
  function navigate(rel) {
    if (navLink) navLink.remove();
    var e = encPath(rel);
    navLink = document.createElement('a');
    navLink.hidden = true;
    navLink.href = '?file=' + e;
    navLink.setAttribute('hx-get', 'api.php?action=render&file=' + e);
    navLink.setAttribute('hx-target', '#content');
    navLink.setAttribute('hx-swap', 'innerHTML show:window:top');
    navLink.setAttribute('hx-push-url', '?file=' + e);
    bar.appendChild(navLink);
    htmx.process(navLink);
    navLink.click();
  }

  // ---- Controls ------------------------------------------------------------
  playBtn.addEventListener('click', function () {
    if (!track) { if (page.audio) loadTrack(page, true); return; }
    if (audio.paused || audio.ended) { following = true; play(); }
    else audio.pause();
  });

  $('pl-back').addEventListener('click', function () { skip(-15); });
  $('pl-fwd').addEventListener('click', function () { skip(15); });
  function skip(s) {
    if (!track) return;
    var d = isFinite(audio.duration) ? audio.duration : Infinity;
    audio.currentTime = Math.max(0, Math.min(d, audio.currentTime + s));
  }

  // Player Next: the page after the one being listened to, with its audio.
  nextBtn.addEventListener('click', function () {
    if (!track || !track.next) return;
    var t = track;
    hidePrompt();
    if (t.nextAudio) {
      loadTrack({ file: t.next, label: t.nextLabel, audio: t.nextAudio, vtt: t.nextVtt }, true);
    } else {
      unload();
      pendingNoAudioNote = true;
    }
    navigate(t.next);
  });

  titleBtn.addEventListener('click', function () {
    if (!track) return;
    if (page.file !== track.file) { navigate(track.file); return; }
    var r = activeCue >= 0 ? ranges[activeCue] : null;
    if (r) { following = true; reveal(r); }
  });

  seekBar.addEventListener('pointerdown', function () { dragging = true; });
  seekBar.addEventListener('input', function () {
    if (!track) return;
    audio.currentTime = parseFloat(seekBar.value) || 0;
    curEl.textContent = fmt(audio.currentTime);
  });
  seekBar.addEventListener('change', function () { dragging = false; following = true; });
  window.addEventListener('pointerup', function () { dragging = false; });

  rateSel.addEventListener('change', function () {
    rate = parseFloat(rateSel.value) || 1;
    audio.defaultPlaybackRate = rate;
    audio.playbackRate = rate;
    setPref('mdv-rate', rate === 1 ? null : String(rate));
  });

  // ---- Volume ----------------------------------------------------------------
  // iPhones and iPads ignore audio.volume (only the hardware buttons change it),
  // so the slider is hidden there; mute works everywhere.
  var volSlider = $('pl-volume'), muteBtn = $('pl-mute');
  var volumeWorks = (function () {
    try { var t = new Audio(); t.volume = 0.5; return Math.abs(t.volume - 0.5) < 0.01; } catch (e) { return false; }
  })();
  bar.classList.toggle('no-volume', !volumeWorks);

  var savedVol = parseFloat(getPref('mdv-volume'));
  audio.volume = volumeWorks && savedVol >= 0 && savedVol <= 1 ? savedVol : 1;
  audio.muted = getPref('mdv-muted') === '1';

  function showVolume() {
    var v = audio.muted ? 0 : audio.volume;
    volSlider.value = audio.volume;
    volSlider.setAttribute('aria-valuetext', audio.muted ? 'muted' : Math.round(audio.volume * 100) + '%');
    volSlider.title = 'Volume: ' + (audio.muted ? 'muted' : Math.round(audio.volume * 100) + '%');
    volSlider.classList.toggle('is-muted', audio.muted);
    muteBtn.setAttribute('aria-pressed', String(audio.muted));
    muteBtn.setAttribute('aria-label', audio.muted ? 'Unmute' : 'Mute');
    muteBtn.title = audio.muted ? 'Unmute' : 'Mute';
    muteBtn.dataset.level = v === 0 ? 'off' : v < 0.5 ? 'low' : 'high';
  }

  volSlider.addEventListener('input', function () {
    audio.volume = parseFloat(volSlider.value);
    audio.muted = audio.volume === 0;   // dragging to zero mutes; dragging up unmutes
  });
  volSlider.addEventListener('change', function () {
    setPref('mdv-volume', audio.volume === 1 ? null : String(audio.volume));
  });
  muteBtn.addEventListener('click', function () {
    if (audio.muted && audio.volume === 0) audio.volume = 0.5;  // unmuting from zero: come back audible
    audio.muted = !audio.muted;
    setPref('mdv-volume', audio.volume === 1 ? null : String(audio.volume));
  });
  audio.addEventListener('volumechange', function () {
    setPref('mdv-muted', audio.muted ? '1' : null);
    showVolume();
  });
  showVolume();

  raBtn.addEventListener('click', function () {
    readAlong = !readAlong;
    setPref('mdv-readalong', readAlong ? null : '0');
    following = true;
    activeCue = -1;
    syncCue(true);
    updateUI();
  });

  $('pl-hide').addEventListener('click', function () {
    userHidden = true;
    setPref('mdv-player-hidden', '1');
    updateUI();
    tab.focus();
  });
  tab.addEventListener('click', function () {
    userHidden = false;
    setPref('mdv-player-hidden', null);
    updateUI();
    playBtn.focus();
  });

  // ---- Audio events --------------------------------------------------------
  audio.addEventListener('play', function () { wanted = true; updateUI(); updatePosition(); });
  audio.addEventListener('pause', function () { updateUI(); updatePosition(); });
  audio.addEventListener('ended', updateUI);  // stop and wait for the user
  audio.addEventListener('loadedmetadata', function () {
    audio.playbackRate = rate;
    seekBar.max = isFinite(audio.duration) ? audio.duration : 0;
    durEl.textContent = fmt(audio.duration);
    updatePosition();
  });
  audio.addEventListener('timeupdate', function () {
    curEl.textContent = fmt(audio.currentTime);
    if (!dragging) seekBar.value = audio.currentTime;
    syncCue(false);
  });
  audio.addEventListener('seeked', function () { syncCue(true); updatePosition(); });
  audio.addEventListener('ratechange', updatePosition);
  audio.addEventListener('error', function () {
    if (!audio.getAttribute('src')) return;
    showToast('This audio file couldn’t be played.');
    updateUI();
  });

  function fmt(s) {
    if (!isFinite(s) || s < 0) s = 0;
    s = Math.floor(s);
    var h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, sec = s % 60;
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
  }

  // ---- Lock screen / notification controls ---------------------------------
  var MS = 'mediaSession' in navigator ? navigator.mediaSession : null;
  function msHandler(action, fn) { try { MS.setActionHandler(action, fn); } catch (e) { /* unsupported action */ } }
  if (MS) {
    msHandler('play', function () { play(); });
    msHandler('pause', function () { audio.pause(); });
    msHandler('seekbackward', function (d) { skip(-((d && d.seekOffset) || 15)); });
    msHandler('seekforward', function (d) { skip((d && d.seekOffset) || 15); });
    msHandler('seekto', function (d) { if (d && isFinite(d.seekTime)) audio.currentTime = d.seekTime; });
  }
  function setMediaSession() {
    if (!MS) return;
    try { MS.metadata = track ? new MediaMetadata({ title: track.label, artist: SITE }) : null; } catch (e) { /* old browser */ }
    msHandler('nexttrack', track && track.next ? function () { nextBtn.click(); } : null);
  }
  function updatePosition() {
    if (!MS || !MS.setPositionState || !track || !isFinite(audio.duration)) return;
    try {
      MS.setPositionState({ duration: audio.duration, playbackRate: audio.playbackRate, position: Math.min(audio.currentTime, audio.duration) });
    } catch (e) { /* ignore */ }
  }

  // ---- Drawing the player --------------------------------------------------
  function updateUI() {
    var show = wanted && !!track;
    bar.hidden = !show || userHidden;
    tab.hidden = !show || !userHidden;
    root.classList.toggle('pl-on', !bar.hidden);

    var playing = isPlaying();
    bar.classList.toggle('is-playing', playing);
    tab.classList.toggle('is-playing', playing);
    playBtn.setAttribute('aria-label', playing ? 'Pause' : 'Play');
    tab.setAttribute('aria-label', 'Show audio player' + (playing ? ' (playing)' : ''));

    var away = !!track && page.file !== track.file;
    titleBtn.textContent = track ? track.label : '';
    titleBtn.classList.toggle('is-away', away);
    titleBtn.title = away ? 'Go to the page being read' : '';
    nextBtn.disabled = !track || !track.next;
    nextBtn.title = track && track.next ? 'Next: ' + track.nextLabel : 'No next page';

    var canRA = !!(HL && track && track.vtt);
    raBtn.disabled = !canRA;
    raBtn.setAttribute('aria-pressed', String(readAlong && canRA));
    raBtn.title = !HL ? 'This browser can’t highlight text for read-along'
      : !(track && track.vtt) ? 'No timing file for this audio, so read-along isn’t available'
      : 'Highlight the sentence being read';
    updateSpace();
  }

  // Leave room at the bottom of the page (and for notices) for the player or its tab.
  function updateSpace() {
    var el = !bar.hidden ? bar : (!tab.hidden ? tab : null);
    var space = el ? Math.max(0, window.innerHeight - el.getBoundingClientRect().top) : 0;
    root.style.setProperty('--pl-space', Math.round(space) + 'px');
  }
  if (window.ResizeObserver) new ResizeObserver(updateSpace).observe(bar);
  window.addEventListener('resize', updateSpace);

  // In case the first page rendered before this script was ready.
  var first = document.querySelector('#content article.doc');
  if (first && !first.querySelector('textarea.md-src')) onPage(docInfo(first));
  updateUI();
})();
