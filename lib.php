<?php
declare(strict_types=1);

// Only usable when included by index.php or api.php.
if (!defined('MDV')) { http_response_code(404); exit; }

// Never print PHP warnings (they leak server paths); log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$CFG  = require __DIR__ . '/config.php';
$ROOT = realpath((string)$CFG['docs_dir']);
if ($ROOT === false || !is_dir($ROOT) || $ROOT === DIRECTORY_SEPARATOR || preg_match('#^[A-Za-z]:\\\\?$#', $ROOT)) {
    http_response_code(500);
    exit('Viewer is not configured: check docs_dir in config.php');
}

const MD_EXT       = '/\.(md|markdown)$/i';
const MAX_PARAM    = 512;           // longest accepted ?file= value, in bytes
const MAX_DEPTH    = 16;            // deepest folder nesting served or listed
const MAX_MD_BYTES = 5 * 1024 * 1024;

const IMAGE_TYPES = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
    'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
];

// Narration that can sit beside a document (chapter.md + chapter.mp3).
// Listed in the order they're looked for.
const AUDIO_TYPES = [
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
    'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg',
    'webm' => 'audio/webm', 'flac' => 'audio/flac', 'wav' => 'audio/wav',
];

// Read-along timings (chapter.vtt).
const TIMING_TYPES = ['vtt' => 'text/vtt; charset=utf-8'];

// Everything the asset endpoint may serve.
function asset_types(): array
{
    return IMAGE_TYPES + AUDIO_TYPES + TIMING_TYPES;
}

// Theme menu: id => label, grouped. Each id (except auto) has a
// :root[data-theme="id"] block in style.css.
const THEME_GROUPS = [
    '' => ['auto' => 'Auto (system)'],
    'Light' => [
        'light' => 'Light',
        'sepia' => 'Sepia',
        'solarized-light' => 'Solarized Light',
        'gruvbox-light' => 'Gruvbox Light',
        'catppuccin-latte' => 'Catppuccin Latte',
    ],
    'Dark' => [
        'dark' => 'Dark',
        'solarized-dark' => 'Solarized Dark',
        'nord' => 'Nord',
        'dracula' => 'Dracula',
        'gruvbox-dark' => 'Gruvbox Dark',
        'one-dark' => 'One Dark',
        'catppuccin-mocha' => 'Catppuccin Mocha',
        'tokyo-night' => 'Tokyo Night',
    ],
];

/** @return list<string> every valid theme id */
function theme_ids(): array
{
    return array_keys(array_merge(...array_values(THEME_GROUPS)));
}

function theme_options(): string
{
    $out = '';
    foreach (THEME_GROUPS as $group => $themes) {
        $opts = '';
        foreach ($themes as $id => $label) $opts .= '<option value="' . h($id) . '">' . h($label) . '</option>';
        $out .= $group === '' ? $opts : '<optgroup label="' . h($group) . '">' . $opts . '</optgroup>';
    }
    return $out;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

// URL-encode a relative path but keep the slashes readable.
function enc_path(string $p): string
{
    return str_replace('%2F', '/', rawurlencode($p));
}

// ---- Request guards ------------------------------------------------------

// Only GET and HEAD are meaningful here; refuse everything else.
function require_get(): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m !== 'GET' && $m !== 'HEAD') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
}

// Read a query parameter as a plain string. Arrays (?x[]=), oversized values,
// broken UTF-8 and control characters are all rejected (returns null).
function query_param(string $name, int $max = MAX_PARAM): ?string
{
    if (!isset($_GET[$name])) return '';
    $v = $_GET[$name];
    if (!is_string($v) || strlen($v) > $max) return null;
    if (!mb_check_encoding($v, 'UTF-8')) return null;
    if (preg_match('/[\x00-\x1F\x7F]/', $v)) return null;
    return $v;
}

/**
 * Check a docs-relative path by its text alone, before touching the disk.
 * Rejects absolute paths, drive letters, stream wrappers (php://, file://),
 * backslashes, empty / "." / ".." segments, hidden names (.git, .env, .htaccess),
 * Windows device names and alternate data streams, and over-deep paths.
 */
function valid_rel_path(string $rel): bool
{
    if ($rel === '' || $rel[0] === '/') return false;
    if (strpbrk($rel, "\\:*?\"<>|") !== false) return false;
    $parts = explode('/', $rel);
    if (count($parts) > MAX_DEPTH) return false;
    foreach ($parts as $seg) {
        if ($seg === '' || $seg[0] === '.') return false;              // also covers "." and ".."
        if ($seg !== rtrim($seg, ' .')) return false;                   // Windows trims these silently
        if (preg_match('/^(con|prn|aux|nul|com\d|lpt\d)(\..*)?$/i', $seg)) return false;
    }
    return true;
}

/**
 * Resolve a docs-relative path to a real file inside the docs folder.
 * Text checks first, then realpath() (which also follows symlinks) must land
 * strictly inside the docs root, the target must be a regular readable file,
 * and its real extension must match $extRegex.
 */
function safe_path(string $root, string $rel, string $extRegex): ?string
{
    if (!valid_rel_path($rel) || !preg_match($extRegex, $rel)) return null;
    $abs = realpath($root . DIRECTORY_SEPARATOR . $rel);
    if ($abs === false) return null;
    if (strncmp($abs, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) return null;
    if (!is_file($abs) || !is_readable($abs)) return null;
    // A symlink called notes.md must not point at secret.txt.
    if (!preg_match($extRegex, basename($abs))) return null;
    // Nothing hidden anywhere along the real path either.
    if (preg_match('#(^|[/\\\\])\.#', substr($abs, strlen($root)))) return null;
    return $abs;
}

function ext_regex(array $types): string
{
    return '/\.(' . implode('|', array_map('preg_quote', array_keys($types))) . ')$/i';
}

/**
 * Find a file next to $rel with the same name and one of $types' extensions,
 * ignoring letter case (Chapter.MP3 matches chapter.md). Returns its
 * docs-relative path, or null.
 */
function sidecar(string $root, string $rel, array $types): ?string
{
    $dir  = dirname($rel) === '.' ? '' : dirname($rel) . '/';
    $base = strtolower((string)preg_replace(MD_EXT, '', basename($rel)));
    $abs  = $dir === '' ? $root : $root . DIRECTORY_SEPARATOR . $dir;
    $found = [];
    foreach (scandir($abs) ?: [] as $name) {
        $dot = strrpos($name, '.');
        if ($dot === false || strtolower(substr($name, 0, $dot)) !== $base) continue;
        $ext = strtolower(substr($name, $dot + 1));
        if (isset($types[$ext])) $found[$ext] = $name;
    }
    foreach (array_keys($types) as $ext) {
        if (isset($found[$ext]) && safe_path($root, $dir . $found[$ext], ext_regex($types))) return $dir . $found[$ext];
    }
    return null;
}

/** Audio and timing files for a document: ['audio' => ?rel, 'vtt' => ?rel] */
function doc_media(string $root, string $rel): array
{
    $audio = sidecar($root, $rel, AUDIO_TYPES);
    return ['audio' => $audio, 'vtt' => $audio ? sidecar($root, $rel, TIMING_TYPES) : null];
}

function rel_path(string $root, string $abs): string
{
    return str_replace('\\', '/', substr($abs, strlen($root) + 1));
}

// ---- Response headers ----------------------------------------------------

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header_remove('X-Powered-By');
}

// Content Security Policy for the main page. Only our own files, the three
// pinned CDN scripts and the one inline script carrying this request's nonce may run.
function page_csp(string $nonce): void
{
    header("Content-Security-Policy: default-src 'none'; "
        . "script-src 'self' 'nonce-$nonce' https://unpkg.com https://cdn.jsdelivr.net; "
        . "style-src 'self'; "
        . "img-src 'self' data: https:; "
        . "media-src 'self'; "
        . "font-src https://cdn.jsdelivr.net; "
        . "connect-src 'self'; "
        . "manifest-src 'self'; "
        . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'");
}

// ---- Content -------------------------------------------------------------

// header.md and footer.md at the top of the docs folder are page furniture,
// not documents: they appear on every page but never in the sidebar,
// Previous/Next, or as a page of their own.
const PARTIALS = ['header' => 'header.md', 'footer' => 'footer.md'];

function is_partial(string $rel): bool
{
    return (bool)preg_match('/^(header|footer)\.md$/i', $rel);
}

function default_file(array $cfg, string $root): ?string
{
    foreach ((array)$cfg['default_files'] as $f) {
        if (is_string($f) && !is_partial($f) && safe_path($root, $f, MD_EXT)) return $f;
    }
    foreach (scandir($root) ?: [] as $f) {
        if (!is_partial($f) && safe_path($root, $f, MD_EXT)) return $f;
    }
    return null;
}

// Find header.md / footer.md at the top level (any letter case) and wrap it
// for app.js to render, or return '' when there isn't one.
function partial_html(string $root, string $which): string
{
    $want = PARTIALS[$which];
    foreach (scandir($root) ?: [] as $name) {
        if (strcasecmp($name, $want) !== 0) continue;
        $abs = safe_path($root, $name, MD_EXT);
        if ($abs === null || filesize($abs) > MAX_MD_BYTES) return '';
        $md = file_get_contents($abs);
        if ($md === false || trim($md) === '') return '';
        if (!mb_check_encoding($md, 'UTF-8')) $md = mb_convert_encoding($md, 'UTF-8', 'Windows-1252');
        $tag = $which === 'header' ? 'header' : 'footer';
        return '<' . $tag . ' class="doc-partial doc-' . $which . '" data-md data-file="' . h($name) . '">'
             . '<textarea class="md-src" hidden>' . h($md) . '</textarea>'
             . '<div class="md-body"></div></' . $tag . '>';
    }
    return '';
}

// Header above everything else, footer below the Previous/Next buttons.
function with_partials(string $root, string $html): string
{
    return partial_html($root, 'header') . $html . partial_html($root, 'footer');
}

/**
 * Walk the docs folder once and return its markdown files in display order:
 * in each folder, subfolders first, then files, each sorted naturally
 * ("2" before "10"), case-insensitive. Folders with no markdown are dropped.
 * The sidebar and the Previous/Next links both use this, so they always agree.
 *
 * @return list<array{type:string, rel:string, children?:array}>
 */
function scan_docs(string $root, string $dir = '', int $depth = 0): array
{
    if ($depth >= MAX_DEPTH) return [];
    $abs = $dir === '' ? $root : $root . DIRECTORY_SEPARATOR . $dir;
    $dirs = $files = [];
    foreach (scandir($abs) ?: [] as $name) {
        if ($name[0] === '.') continue;
        $rel = $dir === '' ? $name : "$dir/$name";
        if (!valid_rel_path($rel) || is_partial($rel)) continue;
        if (is_dir("$abs/$name")) {
            // Follow folder symlinks only if they stay inside the docs root.
            $real = realpath("$abs/$name");
            if ($real !== false && strncmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0) $dirs[] = $rel;
        } elseif (safe_path($root, $rel, MD_EXT)) {
            $files[] = $rel;
        }
    }
    natcasesort($dirs);
    natcasesort($files);

    $out = [];
    foreach ($dirs as $rel) {
        $children = scan_docs($root, $rel, $depth + 1);
        if ($children) $out[] = ['type' => 'dir', 'rel' => $rel, 'children' => $children];
    }
    foreach ($files as $rel) {
        $out[] = ['type' => 'file', 'rel' => $rel];
    }
    return $out;
}

// One scan per request, however many times it's asked for.
function doc_entries(string $root): array
{
    static $cache = [];
    return $cache[$root] ??= scan_docs($root);
}

// Every document in reading order: top to bottom, exactly as the sidebar lists them.
function reading_order(array $entries): array
{
    $flat = [];
    foreach ($entries as $e) {
        if ($e['type'] === 'dir') array_push($flat, ...reading_order($e['children']));
        else $flat[] = $e['rel'];
    }
    return $flat;
}

function doc_label(string $rel): string
{
    return (string)preg_replace(MD_EXT, '', basename($rel));
}

// href + htmx attributes that open a document in the viewer.
function doc_link_attrs(string $rel): string
{
    $e = h(enc_path($rel));
    return 'href="?file=' . $e . '" data-file="' . h($rel) . '"'
         . ' hx-get="api.php?action=render&amp;file=' . $e . '"'
         . ' hx-push-url="?file=' . $e . '"';
}

// Sidebar tree. Only the folders that lead to $current start open; app.js
// keeps that true as you move between documents.
function build_tree(string $root, string $current = ''): string
{
    return render_tree(doc_entries($root), $current);
}

function render_tree(array $entries, string $current): string
{
    $out = '';
    foreach ($entries as $e) {
        if ($e['type'] === 'dir') {
            $open = str_starts_with($current, $e['rel'] . '/') ? ' open' : '';
            $out .= '<li><details data-dir="' . h($e['rel']) . '"' . $open . '><summary>' . h(basename($e['rel'])) . '</summary>'
                  . render_tree($e['children'], $current) . '</details></li>';
        } else {
            $out .= '<li><a ' . doc_link_attrs($e['rel']) . '>' . h(doc_label($e['rel'])) . '</a></li>';
        }
    }
    return $out === '' ? '' : "<ul>$out</ul>";
}

/**
 * Previous / Back to top / Next bar for the bottom of a document.
 * Crossing folders is automatic because reading order is one flat list:
 * after a folder's last file comes the next folder's first file, and back again.
 * The very first document gets no Previous link and the very last no Next link.
 */
/** @return array{0:?string,1:?string} the documents before and after $rel in reading order */
function neighbours(string $root, string $rel): array
{
    $order = reading_order(doc_entries($root));
    $i = array_search($rel, $order, true);
    return [
        ($i !== false && $i > 0) ? $order[$i - 1] : null,
        ($i !== false && $i < count($order) - 1) ? $order[$i + 1] : null,
    ];
}

function pager(string $root, string $rel): string
{
    [$prev, $next] = neighbours($root, $rel);
    $here = dirname($rel);

    $link = function (?string $to, string $dir) use ($here): string {
        if ($to === null) return '<span class="pager-' . $dir . ' pager-empty" aria-hidden="true"></span>';
        $folder = dirname($to);
        // Show the folder name when the link leaves the current folder.
        $where = ($folder !== $here && $folder !== '.')
            ? '<span class="pager-folder">' . h(str_replace('/', ' / ', $folder)) . '</span>' : '';
        return '<a class="pager-link pager-' . $dir . '" rel="' . $dir . '" ' . doc_link_attrs($to)
             . ' hx-target="#content" hx-swap="innerHTML show:window:top">'
             . '<span class="pager-label">' . ($dir === 'prev' ? '← Previous' : 'Next →') . '</span>'
             . '<span class="pager-title">' . h(doc_label($to)) . '</span>' . $where . '</a>';
    };

    return '<nav class="pager" aria-label="Previous and next documents">'
         . $link($prev, 'prev')
         . '<button type="button" class="pager-top">↑ Back to top</button>'
         . $link($next, 'next')
         . '</nav>';
}

function notice(string $title, string $html): string
{
    return '<article class="doc notice"><h1>' . h($title) . '</h1><p>' . $html . '</p></article>';
}

/**
 * Everything that goes inside #content for one request.
 * @return array{0:int,1:string,2:string} HTTP status, fragment HTML, and the
 *         docs-relative path of the document shown ('' for notices)
 */
function render_fragment(array $cfg, string $root, ?string $file): array
{
    [$status, $html, $rel] = render_document($cfg, $root, $file);
    return [$status, with_partials($root, $html), $rel];
}

function render_document(array $cfg, string $root, ?string $file): array
{
    if ($file === null) {
        return [400, notice('Not found', 'That document address isn’t valid.'), ''];
    }
    if ($file === '') {
        $file = default_file($cfg, $root);
        if ($file === null) {
            return [404, notice('No documents yet', 'Add some <code>.md</code> files to the docs folder set in <code>config.php</code>.'), ''];
        }
    }
    // header.md / footer.md aren't pages of their own.
    $abs = is_partial($file) ? null : safe_path($root, $file, MD_EXT);
    if ($abs === null) {
        // Same answer whether the file is missing or forbidden, so nothing can be probed.
        return [404, notice('Not found', 'There is no document at that address.'), ''];
    }
    if (filesize($abs) > MAX_MD_BYTES) {
        return [413, notice('Too large', 'This document is too large to display.'), ''];
    }
    $md = file_get_contents($abs);
    if ($md === false) return [404, notice('Not found', 'There is no document at that address.'), ''];
    if (!mb_check_encoding($md, 'UTF-8')) $md = mb_convert_encoding($md, 'UTF-8', 'Windows-1252');

    $rel = rel_path($root, $abs);
    $crumbs = implode(' <span>/</span> ', array_map('h', explode('/', $rel)));

    // Narration for this page, and for the next one so the player's Next
    // button can start it straight away (phones only allow audio to start
    // directly from a tap).
    $attrs = ' data-label="' . h(doc_label($rel)) . '"';
    $media = doc_media($root, $rel);
    if ($media['audio']) $attrs .= ' data-audio="' . h($media['audio']) . '"';
    if ($media['vtt'])   $attrs .= ' data-vtt="' . h($media['vtt']) . '"';
    [, $next] = neighbours($root, $rel);
    if ($next !== null) {
        $nm = doc_media($root, $next);
        $attrs .= ' data-next="' . h($next) . '" data-next-label="' . h(doc_label($next)) . '"';
        if ($nm['audio']) $attrs .= ' data-next-audio="' . h($nm['audio']) . '"';
        if ($nm['vtt'])   $attrs .= ' data-next-vtt="' . h($nm['vtt']) . '"';
    }

    // Raw markdown travels escaped in a hidden textarea; app.js renders and sanitises it.
    return [200, '<article class="doc" data-md data-file="' . h($rel) . '"' . $attrs . '>'
         . '<div class="crumbs">' . $crumbs . '</div>'
         . '<textarea class="md-src" hidden>' . h($md) . '</textarea>'
         . '<div class="md-body"></div>'
         . pager($root, $rel)
         . '</article>', $rel];
}
