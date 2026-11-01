(function () {
  'use strict';

  var root = document.documentElement;
  var $ = function (id) { return document.getElementById(id); };

  function store(key, val) {
    try {
      if (val === null) localStorage.removeItem(key);
      else localStorage.setItem(key, val);
    } catch (e) { /* private mode etc. */ }
  }

  // ---- Theme -------------------------------------------------------------
  var picker = $('theme');
  picker.value = root.getAttribute('data-theme') || 'auto';
  picker.addEventListener('change', function () {
    var t = picker.value;
    if (t === 'auto') root.removeAttribute('data-theme');
    else root.setAttribute('data-theme', t);
    store('mdv-theme', t);
  });

  // ---- Text size ---------------------------------------------------------
  var SIZES = [13, 14, 15, 16, 17, 18, 20, 22, 24, 27, 30];
  var BASE = 16;
  var resetBtn = $('font-reset');

  function currentSize() {
    var v = parseInt(getComputedStyle(root).getPropertyValue('--fs'), 10);
    return SIZES.indexOf(v) >= 0 ? v : BASE;
  }
  function setSize(px) {
    if (px === BASE) { root.style.removeProperty('--fs'); store('mdv-font-size', null); }
    else { root.style.setProperty('--fs', px + 'px'); store('mdv-font-size', String(px)); }
    resetBtn.textContent = Math.round(px / BASE * 100) + '%';
    $('font-down').disabled = px === SIZES[0];
    $('font-up').disabled = px === SIZES[SIZES.length - 1];
  }
  function stepSize(dir) {
    var i = SIZES.indexOf(currentSize()) + dir;
    setSize(SIZES[Math.max(0, Math.min(SIZES.length - 1, i))]);
  }
  $('font-down').addEventListener('click', function () { stepSize(-1); });
  $('font-up').addEventListener('click', function () { stepSize(1); });
  resetBtn.addEventListener('click', function () { setSize(BASE); });
  setSize(currentSize());

  // ---- Dyslexia-friendly font -------------------------------------------
  var dys = $('dyslexic');
  dys.checked = root.hasAttribute('data-dyslexic');
  dys.addEventListener('change', function () {
    root.toggleAttribute('data-dyslexic', dys.checked);
    store('mdv-dyslexic', dys.checked ? '1' : null);
  });

  // ---- Mobile drawer -----------------------------------------------------
  var toggle = $('nav-toggle');
  var scrim = $('scrim');
  function setNav(open) {
    document.body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    scrim.hidden = !open;
  }
  toggle.addEventListener('click', function () {
    setNav(!document.body.classList.contains('nav-open'));
  });
  scrim.addEventListener('click', function () { setNav(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.body.classList.contains('nav-open')) setNav(false);
  });

  // ---- Markdown rendering ------------------------------------------------
  // (htmx's own hardening lives in the htmx-config meta tag in index.php.)
  marked.use({ gfm: true, breaks: false });

  // What raw HTML inside a markdown file is allowed to contain.
  var PURIFY = {
    FORBID_TAGS: ['style', 'form', 'button', 'select', 'textarea', 'option', 'iframe', 'frame',
                  'frameset', 'object', 'embed', 'base', 'link', 'meta', 'dialog', 'template'],
    FORBID_ATTR: ['style', 'srcset', 'formaction', 'autofocus', 'tabindex'],
    ALLOW_DATA_ATTR: false,   // blocks data-hx-* so markdown can't drive htmx
    ALLOW_UNKNOWN_PROTOCOLS: false
  };
  // The only <input> markdown legitimately produces is a disabled task-list checkbox.
  DOMPurify.addHook('afterSanitizeAttributes', function (node) {
    if (node.nodeName === 'INPUT') {
      if (node.getAttribute('type') !== 'checkbox') { node.remove(); return; }
      node.setAttribute('disabled', '');
      node.removeAttribute('name');
    }
  });

  // IDs the app itself relies on; document content must never reuse them.
  var APP_IDS = {};
  document.querySelectorAll('[id]').forEach(function (el) {
    if (!el.closest('#content') || el.id === 'content') APP_IDS[el.id] = true;
  });
  function uniqueId(id, taken) {
    var n = 0, out = id;
    while (APP_IDS[out] || taken[out]) out = id + '-' + (++n);
    taken[out] = true;
    return out;
  }

  var EXTERNAL = /^([a-z][a-z0-9+.-]*:|\/\/)/i; // http:, mailto:, data:, //cdn...
  var MD_FILE = /\.(md|markdown)$/i;

  function encPath(p) { return encodeURIComponent(p).replace(/%2F/g, '/'); }

  // Resolve a link written inside a markdown file against that file's folder.
  // A leading "/" means the root of the docs folder.
  function resolve(baseDir, ref) {
    var u = new URL(ref, 'http://docs/' + (baseDir ? baseDir + '/' : ''));
    return { path: decodeURIComponent(u.pathname.slice(1)), hash: u.hash };
  }

  function slugify(text) {
    return text.toLowerCase().trim()
      .replace(/[^\p{L}\p{N}\s-]/gu, '')
      .replace(/\s+/g, '-');
  }

  // Renders the main document and the header.md / footer.md blocks alike.
  function renderDoc(article) {
    var src = article.querySelector('textarea.md-src');
    if (!src) return; // already rendered (e.g. restored from history)
    var isMain = article.matches('article.doc');
    var file = article.getAttribute('data-file') || '';
    var baseDir = file.indexOf('/') >= 0 ? file.slice(0, file.lastIndexOf('/')) : '';
    var body = article.querySelector('.md-body');

    body.innerHTML = DOMPurify.sanitize(marked.parse(src.value), PURIFY);
    src.remove();

    // Keep IDs unique and clear of the app's own (prevents DOM clobbering),
    // and give headings anchors so #section links work.
    var taken = {};
    body.querySelectorAll('[id]').forEach(function (el) { el.id = uniqueId(el.id, taken); });
    body.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(function (h) {
      if (!h.id) h.id = uniqueId(slugify(h.textContent) || 'section', taken);
    });

    // Images: relative paths are served through the PHP asset endpoint.
    body.querySelectorAll('img[src]').forEach(function (img) {
      var ref = img.getAttribute('src');
      img.loading = 'lazy';
      img.decoding = 'async';
      if (!EXTERNAL.test(ref)) {
        img.src = 'api.php?action=asset&file=' + encPath(resolve(baseDir, ref).path);
      }
      // Images that aren't links open in the lightbox.
      if (!img.closest('a')) {
        img.classList.add('zoomable');
        img.tabIndex = 0;
        img.setAttribute('role', 'button');
        img.setAttribute('aria-label', 'View image' + (img.alt ? ': ' + img.alt : ''));
      }
    });

    // Links: other .md files open in the viewer; external links open in a new tab.
    body.querySelectorAll('a[href]').forEach(function (a) {
      var ref = a.getAttribute('href');
      if (ref.charAt(0) === '#') return;
      if (EXTERNAL.test(ref)) {
        if (/^https?:|^\/\//i.test(ref)) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
        return;
      }
      var r = resolve(baseDir, ref);
      if (MD_FILE.test(r.path)) {
        var page = '?file=' + encPath(r.path) + r.hash;
        a.setAttribute('href', page);
        a.setAttribute('hx-get', 'api.php?action=render&file=' + encPath(r.path));
        a.setAttribute('hx-target', '#content');
        a.setAttribute('hx-swap', 'innerHTML show:window:top');
        a.setAttribute('hx-push-url', page);
      } else if (/\.[a-z0-9]+$/i.test(r.path)) {
        // Linked images open straight from the asset endpoint.
        a.setAttribute('href', 'api.php?action=asset&file=' + encPath(r.path));
      }
    });
    htmx.process(body);

    if (!isMain) return; // header/footer don't affect the title, sidebar or scroll
    pageShown(article);
  }

  // Everything that follows from a document being on screen: title, sidebar,
  // #section scroll, and telling the audio player which page this is.
  // Runs after a fresh render and after Back/Forward restores a page.
  function pageShown(article) {
    var file = article.getAttribute('data-file') || '';
    var body = article.querySelector('.md-body');

    // Page title from the first heading.
    var h1 = body && body.querySelector('h1');
    var site = document.body.getAttribute('data-site-title');
    document.title = h1 ? h1.textContent + ' · ' + site : site;
    $('topbar-title').textContent = h1 ? h1.textContent : site;

    // Highlight the open file in the sidebar.
    document.querySelectorAll('#tree a[data-file]').forEach(function (a) {
      var on = a.getAttribute('data-file') === file;
      a.classList.toggle('active', on);
      if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });

    // Open only the folders that lead to this document; collapse the rest.
    document.querySelectorAll('#tree details[data-dir]').forEach(function (d) {
      d.open = file.indexOf(d.getAttribute('data-dir') + '/') === 0;
    });

    // Honour #section in the URL (after htmx finishes its own scroll-to-top).
    setTimeout(scrollToHash, 30);

    document.dispatchEvent(new CustomEvent('mdv:page', { detail: { article: article } }));
  }

  // Back/Forward put back an already-rendered document (only #content is
  // restored, so the audio player carries on undisturbed).
  document.body.addEventListener('htmx:historyRestore', function () {
    var article = document.querySelector('#content article.doc');
    if (article) pageShown(article);
    else document.dispatchEvent(new CustomEvent('mdv:page', { detail: { article: null } }));
  });

  function scrollToHash() {
    if (!location.hash) return;
    var target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (target) target.scrollIntoView();
  }
  // htmx files each page's Back/Forward snapshot under the URL it pushed, but
  // looks snapshots up by the full path. Our links push "?file=…", so make it
  // the full path (and keep any #section out of the key) or every Back/Forward
  // would miss and reload the whole page, stopping any audio.
  var pendingHash = '';
  document.body.addEventListener('htmx:beforeHistoryUpdate', function (e) {
    var h = e.detail && e.detail.history;
    if (!h || !h.path) return;
    var u = new URL(h.path, location.href);
    h.path = u.pathname + u.search;
    pendingHash = u.hash;
  });
  document.body.addEventListener('htmx:pushedIntoHistory', function () {
    if (pendingHash) {
      history.replaceState(history.state, '', location.pathname + location.search + pendingHash);
      pendingHash = '';
    }
    scrollToHash();
  });

  htmx.onLoad(function (elt) {
    var docs = elt.matches && elt.matches('[data-md]') ? [elt] : elt.querySelectorAll('[data-md]');
    Array.prototype.forEach.call(docs, renderDoc);
    // "Not found" and similar notices are pages too (with no audio).
    if (elt.querySelector && elt.querySelector('article.notice')) {
      document.dispatchEvent(new CustomEvent('mdv:page', { detail: { article: null } }));
    }
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    if (e.detail.target.id === 'content') setNav(false);
  });

  // ---- Back to top -------------------------------------------------------
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  $('content').addEventListener('click', function (e) {
    if (!e.target.closest('.pager-top')) return;
    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    // Keyboard and screen-reader users land at the top too, not just the view.
    var h1 = document.querySelector('#content article.doc .md-body h1');
    if (h1) { h1.tabIndex = -1; h1.focus({ preventScroll: true }); }
  });

  // ---- Lightbox ----------------------------------------------------------
  var lb = $('lightbox');
  var lbImg = lb.querySelector('img');
  var lbCap = lb.querySelector('figcaption');
  var lbCount = lb.querySelector('.lb-count');
  var gallery = [];
  var index = 0;

  function showImage(i) {
    index = (i + gallery.length) % gallery.length;
    var g = gallery[index];
    lbImg.src = g.currentSrc || g.src;
    lbImg.alt = g.alt || '';
    lbCap.textContent = g.alt || '';
    lbCap.hidden = !g.alt;
    lbCount.textContent = gallery.length > 1 ? (index + 1) + ' / ' + gallery.length : '';
    // Warm up the neighbours so swiping feels instant.
    [index - 1, index + 1].forEach(function (n) {
      var nb = gallery[(n + gallery.length) % gallery.length];
      if (nb) new Image().src = nb.currentSrc || nb.src;
    });
  }

  function openLightbox(img) {
    var article = img.closest('.md-body');
    gallery = Array.prototype.slice.call(article.querySelectorAll('img.zoomable'));
    lb.classList.toggle('single', gallery.length < 2);
    showImage(gallery.indexOf(img));
    root.classList.add('lb-open'); // stops the page behind from scrolling
    lb.showModal();
  }

  lb.addEventListener('close', function () {
    root.classList.remove('lb-open');
    lbImg.removeAttribute('src');
  });

  var content = $('content');
  content.addEventListener('click', function (e) {
    var img = e.target.closest('img.zoomable');
    if (img) openLightbox(img);
  });
  content.addEventListener('keydown', function (e) {
    var img = e.target.closest && e.target.closest('img.zoomable');
    if (img && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openLightbox(img); }
  });

  lb.querySelector('.lb-close').addEventListener('click', function () { lb.close(); });
  lb.querySelector('.lb-prev').addEventListener('click', function () { showImage(index - 1); });
  lb.querySelector('.lb-next').addEventListener('click', function () { showImage(index + 1); });

  // Tap the dark area around the image to close.
  lb.addEventListener('click', function (e) {
    if (e.target === lb || e.target.tagName === 'FIGURE') lb.close();
  });

  lb.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') { e.preventDefault(); showImage(index - 1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); showImage(index + 1); }
  });

  // Swipe left/right to move between images, swipe down to close.
  var touchX = null, touchY = null;
  lb.addEventListener('touchstart', function (e) {
    if (e.touches.length !== 1) { touchX = null; return; } // leave pinch-zoom alone
    touchX = e.touches[0].clientX;
    touchY = e.touches[0].clientY;
  }, { passive: true });
  lb.addEventListener('touchend', function (e) {
    if (touchX === null || (window.visualViewport && window.visualViewport.scale > 1.05)) return;
    var dx = e.changedTouches[0].clientX - touchX;
    var dy = e.changedTouches[0].clientY - touchY;
    touchX = null;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) {
      if (gallery.length > 1) showImage(index + (dx < 0 ? 1 : -1));
    } else if (dy > 90 && dy > Math.abs(dx) * 1.5) {
      lb.close();
    }
  }, { passive: true });
})();
