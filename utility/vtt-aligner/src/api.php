<?php
/**
 * vtt-aligner HTTP API.
 *
 *   GET    /v1/health            service and worker status
 *   POST   /v1/jobs              submit audio + text (multipart/form-data) → 202
 *   GET    /v1/jobs/{id}         job status, progress and quality report
 *   GET    /v1/jobs/{id}/vtt     the finished WebVTT file
 *   DELETE /v1/jobs/{id}         remove a job and its files
 *
 * No authentication: this is an internal service, meant to sit behind a front
 * service on a private network. Restrict who can reach it at the network or
 * web-server level (see README, "Keep it internal").
 * The alignment itself is done by worker/worker.py, which watches the job folders.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
umask(0007); // job files are shared with the worker through the group

const ID_RE = '/^[a-f0-9]{32}$/';
const AUDIO_EXT = [
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/x-mpeg' => 'mp3',
    'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/vnd.wave' => 'wav',
    'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac',
    'video/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/opus' => 'opus',
    'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
];

// ---------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------

function send_json(int $status, array $body, array $headers = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $h) header($h);
    // Last, because PHP turns any response with a Location header into a 302.
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit;
}

function fail(int $status, string $code, string $message, array $headers = []): never
{
    send_json($status, ['error' => ['code' => $code, 'message' => $message]], $headers);
}

// ---------------------------------------------------------------------------
// Config and storage
// ---------------------------------------------------------------------------

function config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = getenv('VTT_ALIGNER_CONFIG') ?: dirname(__DIR__) . '/config.ini';
    $ini = @parse_ini_file($path, true, INI_SCANNER_TYPED);
    if (!is_array($ini) || empty($ini['service']['storage_dir'])) {
        error_log("vtt-aligner: cannot read config at $path");
        fail(500, 'not_configured', 'The service is not configured.');
    }
    $s = $ini['service'];
    $cfg = [
        'storage' => rtrim((string)$s['storage_dir'], '/'),
        'max_upload_mb' => (int)($s['max_upload_mb'] ?? 300),
        'max_text_kb' => (int)($s['max_text_kb'] ?? 2048),
        'max_audio_minutes' => (int)($s['max_audio_minutes'] ?? 240),
        'max_queue' => (int)($s['max_queue'] ?? 20),
        'retention_hours' => (float)($s['retention_hours'] ?? 72),
    ];
    return $cfg;
}

function jobs_dir(): string
{
    $d = config()['storage'] . '/jobs';
    if (!is_dir($d) && !@mkdir($d, 02770, true) && !is_dir($d)) {
        error_log("vtt-aligner: cannot create $d");
        fail(500, 'storage_unavailable', 'Job storage is not available.');
    }
    return $d;
}

function job_dir(string $id): string
{
    if (!preg_match(ID_RE, $id)) fail(404, 'not_found', 'No such job.');
    $d = jobs_dir() . '/' . $id;
    if (!is_dir($d) || !is_file("$d/status.json")) fail(404, 'not_found', 'No such job.');
    return $d;
}

function read_json_file(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $v = json_decode($raw, true);
    return is_array($v) ? $v : null;
}

function write_json_file(string $path, array $data): void
{
    $tmp = $path . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $path);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** @return array{queued:int,running:int,ids:list<string>} queued ids oldest first */
function queue_state(): array
{
    $q = []; $running = 0;
    foreach (scandir(jobs_dir()) ?: [] as $name) {
        if (!preg_match(ID_RE, $name)) continue;
        $st = read_json_file(jobs_dir() . "/$name/status.json");
        if (!$st) continue;
        if ($st['status'] === 'queued') $q[$name] = $st['created_at'] ?? '';
        elseif ($st['status'] === 'running') $running++;
    }
    asort($q);
    return ['queued' => count($q), 'running' => $running, 'ids' => array_keys($q)];
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

function route(): array
{
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
    if (str_starts_with($path, '/index.php')) $path = substr($path, 10);
    return [$_SERVER['REQUEST_METHOD'] ?? 'GET', '/' . trim($path, '/')];
}

function base_url(): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $base . '/v1';
}

function status_body(string $id, array $st): array
{
    $body = [
        'id' => $id,
        'status' => $st['status'],
        'name' => $st['name'] ?? null,
        'created_at' => $st['created_at'] ?? null,
        'started_at' => $st['started_at'] ?? null,
        'finished_at' => $st['finished_at'] ?? null,
        'progress' => $st['progress'] ?? null,
        'error' => $st['error'] ?? null,
        'links' => ['self' => base_url() . "/jobs/$id"],
    ];
    if ($st['status'] === 'queued') {
        $pos = array_search($id, queue_state()['ids'], true);
        $body['queue_position'] = $pos === false ? null : $pos + 1;
    }
    if ($st['status'] === 'done') {
        $body['links']['vtt'] = base_url() . "/jobs/$id/vtt";
        $body['report'] = $st['report'] ?? null;
    }
    return $body;
}

function require_method(string $method, array $allowed): void
{
    if (!in_array($method, $allowed, true)) {
        fail(405, 'method_not_allowed', 'Method not allowed.', ['Allow: ' . implode(', ', $allowed)]);
    }
}

// ---------------------------------------------------------------------------
// Endpoints
// ---------------------------------------------------------------------------

function health(): never
{
    $hb = read_json_file(config()['storage'] . '/worker.json');
    $age = $hb ? max(0, time() - (int)$hb['seen']) : null;
    $alive = $age !== null && $age < 30 && ($hb['state'] ?? '') !== 'stopped';
    $q = queue_state();
    $c = config();
    send_json($alive ? 200 : 503, [
        'ok' => $alive,
        'worker' => ['alive' => $alive, 'last_seen_seconds_ago' => $age, 'state' => $hb['state'] ?? null, 'version' => $hb['version'] ?? null],
        'queue' => ['queued' => $q['queued'], 'running' => $q['running'], 'max' => $c['max_queue']],
        'limits' => ['max_upload_mb' => $c['max_upload_mb'], 'max_text_kb' => $c['max_text_kb'],
                     'max_audio_minutes' => $c['max_audio_minutes'], 'retention_hours' => $c['retention_hours']],
    ]);
}

function upload_error(int $code, string $field): never
{
    match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => fail(413, 'too_large', "The $field file is larger than the server allows."),
        UPLOAD_ERR_PARTIAL => fail(400, 'upload_incomplete', "The $field upload was interrupted."),
        UPLOAD_ERR_NO_FILE => fail(400, 'missing_field', "Send the $field file."),
        default => fail(500, 'upload_failed', "The $field upload could not be stored."),
    };
}

function create_job(): never
{
    $c = config();
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
        fail(415, 'unsupported_media_type', 'Send multipart/form-data with "audio" and "text".');
    }
    // A body bigger than post_max_size arrives as empty $_POST/$_FILES.
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > 0 && !$_POST && !$_FILES) fail(413, 'too_large', 'The request is larger than the server allows.');

    // ---- audio ----
    $f = $_FILES['audio'] ?? null;
    if (!$f || is_array($f['error'])) fail(400, 'missing_field', 'Send the narration as a file field named "audio".');
    if ($f['error'] !== UPLOAD_ERR_OK) upload_error($f['error'], 'audio');
    if ($f['size'] > $c['max_upload_mb'] * 1048576) fail(413, 'too_large', "Audio is limited to {$c['max_upload_mb']} MB.");
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $ext = AUDIO_EXT[$mime] ?? null;
    if ($ext === null) fail(415, 'unsupported_audio', "Unsupported audio type ($mime). Use MP3, WAV, M4A/AAC, OGG/Opus, WebM or FLAC.");

    // ---- text: a file field or a plain form field named "text" ----
    $text = null; $textName = '';
    if (isset($_FILES['text']) && !is_array($_FILES['text']['error'])) {
        $t = $_FILES['text'];
        if ($t['error'] !== UPLOAD_ERR_OK) upload_error($t['error'], 'text');
        if ($t['size'] > $c['max_text_kb'] * 1024) fail(413, 'too_large', "Text is limited to {$c['max_text_kb']} KB.");
        $text = (string)file_get_contents($t['tmp_name']);
        $textName = (string)$t['name'];
    } elseif (isset($_POST['text']) && is_string($_POST['text'])) {
        $text = $_POST['text'];
        if (strlen($text) > $c['max_text_kb'] * 1024) fail(413, 'too_large', "Text is limited to {$c['max_text_kb']} KB.");
    }
    if ($text === null || trim($text) === '') fail(400, 'missing_field', 'Send the narrated text as "text" (a file or a form field).');
    if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
    if (!mb_check_encoding($text, 'UTF-8')) fail(400, 'bad_text', 'The text must be UTF-8.');
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) fail(400, 'bad_text', 'The text contains control characters.');

    // ---- options ----
    $format = $_POST['format'] ?? (preg_match('/\.txt$/i', $textName) ? 'text' : 'markdown');
    if (!in_array($format, ['markdown', 'text'], true)) fail(400, 'bad_option', 'format must be "markdown" or "text".');
    $name = trim((string)($_POST['name'] ?? ''));
    if (mb_strlen($name) > 200 || preg_match('/[\x00-\x1F\x7F]/', $name)) fail(400, 'bad_option', 'name must be up to 200 printable characters.');
    $pron = (string)($_POST['pronunciations'] ?? '');
    if (strlen($pron) > 65536 || !mb_check_encoding($pron, 'UTF-8')) fail(400, 'bad_option', 'pronunciations must be UTF-8, up to 64 KB.');
    foreach (preg_split('/\R/', $pron) as $n => $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if ($line !== '' && !preg_match("/^[A-Za-z0-9']+(\s+[A-Za-z]{1,2}[0-2]?)+$/", $line)) {
            fail(400, 'bad_option', 'pronunciations line ' . ($n + 1) . ': expected "word PHONES" (CMU/ARPAbet, e.g. "torvin T AO R V IH N").');
        }
    }
    $dropUnread = !in_array(strtolower((string)($_POST['drop_unread'] ?? 'true')), ['0', 'false', 'no', 'off'], true);

    // ---- capacity ----
    $q = queue_state();
    if ($q['queued'] + $q['running'] >= $c['max_queue']) {
        fail(503, 'queue_full', 'Too many jobs are waiting. Try again shortly.', ['Retry-After: 60']);
    }
    $free = @disk_free_space(jobs_dir());
    if ($free !== false && $free < $f['size'] * 4 + 512 * 1048576) {
        fail(507, 'insufficient_storage', 'Not enough disk space for this job.');
    }

    // ---- write the job, then publish it with one rename ----
    $id = bin2hex(random_bytes(16));
    $tmp = jobs_dir() . "/$id.tmp";
    if (!@mkdir($tmp, 02770)) fail(500, 'storage_unavailable', 'Could not create the job.');
    if (!move_uploaded_file($f['tmp_name'], "$tmp/audio.$ext")) { rrmdir($tmp); fail(500, 'upload_failed', 'Could not store the audio.'); }
    file_put_contents("$tmp/text.txt", $text);
    write_json_file("$tmp/options.json", ['format' => $format, 'name' => $name, 'pronunciations' => $pron, 'drop_unread' => $dropUnread]);
    $st = [
        'id' => $id, 'status' => 'queued', 'name' => $name !== '' ? $name : null,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'started_at' => null, 'finished_at' => null,
        'progress' => ['stage' => 'queued'], 'error' => null,
        'input' => ['audio_type' => $mime, 'audio_bytes' => (int)$f['size'], 'text_bytes' => strlen($text), 'format' => $format],
    ];
    write_json_file("$tmp/status.json", $st);
    touch("$tmp/job.lock");
    if (!rename($tmp, jobs_dir() . "/$id")) { rrmdir($tmp); fail(500, 'storage_unavailable', 'Could not create the job.'); }

    send_json(202, status_body($id, $st), ['Location: ' . base_url() . "/jobs/$id"]);
}

function get_job(string $id): never
{
    $st = read_json_file(job_dir($id) . '/status.json');
    if (!$st) fail(404, 'not_found', 'No such job.');
    send_json(200, status_body($id, $st), ['Cache-Control: no-store']);
}

function get_vtt(string $id): never
{
    $d = job_dir($id);
    $st = read_json_file("$d/status.json") ?? [];
    if (($st['status'] ?? '') !== 'done' || !is_file("$d/result.vtt")) {
        fail(409, 'not_ready', 'The job has not finished' . (($st['status'] ?? '') === 'failed' ? ' (it failed: ' . ($st['error'] ?? '') . ')' : '') . '.');
    }
    $fname = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string)($st['name'] ?? '')) ?: $id;
    http_response_code(200);
    header('Content-Type: text/vtt; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . trim($fname) . '.vtt"');
    header('Content-Length: ' . filesize("$d/result.vtt"));
    readfile("$d/result.vtt");
    exit;
}

function delete_job(string $id): never
{
    $d = job_dir($id);
    $lk = @fopen("$d/job.lock", 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) fail(409, 'busy', 'The job is being processed; delete it once it has finished.');
    // Move it aside first so the worker never sees a half-deleted job.
    $gone = jobs_dir() . "/$id.del-" . bin2hex(random_bytes(4));
    rename($d, $gone);
    flock($lk, LOCK_UN);
    fclose($lk);
    rrmdir($gone);
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header_remove('X-Powered-By');

[$method, $path] = route();

// Upload page for people: GET / (or /upload)
if ($path === '/' || $path === '/upload') {
    require_method($method, ['GET', 'HEAD']);
    require __DIR__ . '/upload-page.php';
    exit;
}

if ($path === '/v1/health') { require_method($method, ['GET', 'HEAD']); health(); }
if ($path === '/v1/jobs') { require_method($method, ['POST']); create_job(); }
if (preg_match('#^/v1/jobs/([^/]+)$#', $path, $m)) {
    require_method($method, ['GET', 'HEAD', 'DELETE']);
    $method === 'DELETE' ? delete_job($m[1]) : get_job($m[1]);
}
if (preg_match('#^/v1/jobs/([^/]+)/vtt$#', $path, $m)) { require_method($method, ['GET', 'HEAD']); get_vtt($m[1]); }
fail(404, 'not_found', 'Unknown endpoint. See /v1/health, /v1/jobs.');
