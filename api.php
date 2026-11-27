<?php
declare(strict_types=1);
define('MDV', true);
require __DIR__ . '/lib.php';

security_headers();
require_get();

// The API only returns fragments and images, never pages: nothing here may run scripts or be framed.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'; sandbox");

$action = query_param('action', 16);
$file   = query_param('file');

switch ($action) {
    case 'render':
        // Someone opened the fragment URL directly: send them to the full page instead.
        if (($_SERVER['HTTP_HX_REQUEST'] ?? '') !== 'true') {
            $ok = $file !== null && $file !== '' && valid_rel_path($file);
            header('Location: index.php' . ($ok ? '?file=' . enc_path($file) : ''), true, 303);
            exit;
        }
        [$status, $html] = render_fragment($CFG, $ROOT, $file);
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Vary: HX-Request');
        echo $html;
        break;

    case 'asset':
        // Images, narration audio and read-along timing files.
        $types = asset_types();
        $abs = $file ? safe_path($ROOT, $file, ext_regex($types)) : null;
        if ($abs === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Not found');
        }
        $ext   = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $size  = (int)filesize($abs);
        $mtime = (int)filemtime($abs);
        $etag  = '"' . hash('sha256', $abs . '|' . $mtime . '|' . $size) . '"';
        $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Cache-Control: public, max-age=3600');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Accept-Ranges: bytes');
        if ($range === '') {
            $inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
            if ($inm === '*' || ($inm !== '' && in_array($etag, array_map(
                static fn(string $t): string => preg_replace('/^W\//', '', trim($t)),
                explode(',', $inm)
            ), true))) {
                http_response_code(304);
                exit;
            }
        }

        // Byte ranges let players seek, and Safari won't play audio without them.
        // One range per request (enough for media); anything else gets the whole file.
        $start = 0;
        $end   = $size - 1;
        $isPartial = false;
        $ifRange = trim((string)($_SERVER['HTTP_IF_RANGE'] ?? ''));
        if ($range !== '' && ($ifRange === '' || $ifRange === $etag)
            && preg_match('/^bytes=(\d{0,19})-(\d{0,19})$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {                       // "bytes=-500": the last 500 bytes
                $start = max(0, $size - (int)$m[2]);
                if ((int)$m[2] === 0) $start = $size;
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
            }
            if ($start >= $size || $start > $end) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $isPartial = true;
        }

        $length = $size === 0 ? 0 : $end - $start + 1;
        $isHead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
        $fh = null;
        if (!$isHead && $length > 0) {
            $fh = @fopen($abs, 'rb');
            if ($fh === false) {
                // Don't let caches keep this miss under the file's validators.
                header_remove('ETag');
                header_remove('Last-Modified');
                header_remove('Accept-Ranges');
                header('Cache-Control: no-store');
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                exit('Not found');
            }
        }
        if ($isPartial) {
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . $length);
        header('Content-Disposition: inline');
        if ($fh === null) exit;

        // Stream in chunks so long recordings never sit in memory.
        @set_time_limit(0);
        while (ob_get_level() > 0) ob_end_clean();
        fseek($fh, $start);
        $left = $length;
        while ($left > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, (int)min(65536, $left));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            flush();
            $left -= strlen($chunk);
        }
        fclose($fh);
        break;

    default:
        // Unknown, missing or malformed action.
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request';
}
