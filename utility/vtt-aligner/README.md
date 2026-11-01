# vtt-aligner

*A utility for [md-reader](../../README.md): use and deployment guide.*

A small self-hosted service that turns **narration audio + the text it narrates** into a **WebVTT file with one cue per sentence**: the timing file that powers md-reader's read-along highlighting. Upload an MP3 and its chapter on a simple web page and download the `.vtt`, or call it from another system over HTTP.

- **PHP API** (no framework, no database): submit a job, poll its status, download the `.vtt`. No authentication: it's an internal service for a front service to call (see [Keep it internal](#keep-it-internal)).
- **Python worker**: does the alignment in the background, one job at a time.
- **Upload page** at the service's root address (`/`): pick the audio and its text, wait, and the `.vtt` downloads.

It runs entirely on your server. Nothing is sent to outside services.

**Contents**
- Using it: [Upload page](#upload-page) · [API](#api) · [Calling it from PHP](#calling-it-from-the-front-service-php) · [Command line](#command-line-no-api)
- Deploying it: [Setup](#setup-ubuntu--debian) · [Keep it internal](#keep-it-internal) · [Operations](#operations) · [Troubleshooting](#troubleshooting)

```
other system ──POST audio+text──▶ PHP API ──job folder──▶ worker (Python)
             ◀──202 + job id────            ◀──status/result──
             ──GET /jobs/{id}/vtt──▶ result.vtt
```

## What it does with a job

1. Splits the text (Markdown or plain) into the sentences a reader sees. Code blocks, tables and image captions are left out.
2. Gives the speech recogniser pronunciations for any unusual words (character names, place names), generated automatically with espeak-ng.
3. Builds a language model from this text alone, so the recogniser expects exactly these sentences.
4. Transcribes the audio with word timestamps (CMU PocketSphinx), using all CPU cores.
5. Aligns the page's words with the words heard and moves each sentence boundary into the natural pause before it.
6. Drops headings and quotes that weren't actually read aloud (e.g. a chapter title or an italic intro the narrator skips).
7. Scores every cue against what was heard in it, and fails the job if the audio clearly doesn't narrate this text.

Typical speed is about 1 minute of processing per 15–20 minutes of audio on 2 CPU cores.

**Limitations**
- English only.
- One worker processes jobs one at a time; others wait in the queue.
- Text must be what's narrated, in order. Small differences (typos, "harbour" vs "harbor", a skipped word) are fine.

---

## Setup (Ubuntu / Debian)

Tested on Ubuntu 24.04; any recent Ubuntu or Debian works the same way. The steps below assume these locations. You can change them, but keep the code and the storage folder outside the web root.

| What | Where |
|---|---|
| Code | `/opt/vtt-aligner` (only `public/` is served by the web server) |
| Config | `/opt/vtt-aligner/config.ini` |
| Job storage | `/var/lib/vtt-aligner` |
| Worker user | `vtt-aligner`, in the `www-data` group |

### 1. Install packages

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-cli ffmpeg espeak-ng python3-venv python3-dev build-essential
php -v   # note the version (e.g. 8.3) for the nginx config below
```

PHP 8.1 or newer is required. The `fileinfo` and `mbstring` extensions are needed; they're enabled by default on Ubuntu (check with `php -m | grep -E 'fileinfo|mbstring'`; if missing, `sudo apt install php-mbstring`).

### 2. Put the code in place

Copy just this folder out of the md-reader repository. It runs on its own, separate from the reader's website:

```bash
git clone https://github.com/zeighy/md-reader.git
sudo cp -r md-reader/utility/vtt-aligner /opt/vtt-aligner
ls /opt/vtt-aligner/public/index.php /opt/vtt-aligner/src/api.php /opt/vtt-aligner/worker/worker.py
```

### 3. Create the worker user and storage

```bash
sudo useradd --system --gid www-data --home-dir /var/lib/vtt-aligner --shell /usr/sbin/nologin vtt-aligner
sudo install -d -o vtt-aligner -g www-data -m 2770 /var/lib/vtt-aligner
```

The storage folder is shared by the web server (which creates jobs) and the worker (which completes them) through the `www-data` group. The `2` in `2770` keeps new files in that group.

### 4. Python environment for the worker

```bash
sudo python3 -m venv /opt/vtt-aligner/venv
sudo /opt/vtt-aligner/venv/bin/pip install -r /opt/vtt-aligner/worker/requirements.txt
sudo /opt/vtt-aligner/venv/bin/python -c "import numpy, pocketsphinx, markdown_it; print('ok')"
```

On a Python version too new for a ready-made pocketsphinx package (e.g. 3.14), pip compiles it from source: "Building wheel for pocketsphinx" takes a few minutes. That's why `python3-dev` and `build-essential` are in step 1. If the build fails with `Could NOT find Python (missing: … Development.Module)`, the headers are missing: `sudo apt install python3-dev` (or `python3.14-dev` to match your version) and re-run the `pip install`. Warnings about BISON and FLEX are harmless.

### 5. Configure

```bash
sudo cp /opt/vtt-aligner/config.ini.example /opt/vtt-aligner/config.ini
sudo nano /opt/vtt-aligner/config.ini        # review the limits; defaults are fine to start
```

Then set its ownership. **The web server must be able to read it**, or every request fails with `not_configured`:

```bash
sudo chown root:www-data /opt/vtt-aligner/config.ini
sudo chmod 640 /opt/vtt-aligner/config.ini
```

> If you edit the file later with a tool that replaces it (or restore a backup), re-run those two commands.

### 6. Upload size limits

Narration files are large. Three limits must all be at least `max_upload_mb`:

| Limit | Where | Default here |
|---|---|---|
| `upload_max_filesize`, `post_max_size` | `public/.user.ini` (read by PHP-FPM) | 300M / 310M |
| `client_max_body_size` | nginx site config | 310m |
| `max_upload_mb` | `config.ini` | 300 |

To change them, edit all three. (With Apache + mod_php, set `php_value upload_max_filesize` and `php_value post_max_size` in `public/.htaccess` instead of `.user.ini`.)

### 7. Web server

**nginx** (recommended):

```bash
sudo cp /opt/vtt-aligner/deploy/nginx-vtt-aligner.conf /etc/nginx/sites-available/vtt-aligner
sudo nano /etc/nginx/sites-available/vtt-aligner   # server_name, php-fpm socket (ls /run/php/), who may connect
sudo ln -s /etc/nginx/sites-available/vtt-aligner /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

The config serves only `public/index.php`; the code, config and job files can't be reached over HTTP. Set who may connect before enabling it (next section).

**Apache** (alternative): point a virtual host's `DocumentRoot` at `/opt/vtt-aligner/public` with `AllowOverride All`, and enable `mod_rewrite` (`sudo a2enmod rewrite`). The included `public/.htaccess` routes requests. Set `LimitRequestBody` to at least your upload limit, and restrict access with `Require ip <front-service-address>` in the virtual host.

#### Keep it internal

The API doesn't check who's calling, so make sure only the front service can reach it. Any one of these is enough; combining two is better:

- **Listen on an internal address only.** In the nginx config, replace the two `listen` lines with the server's private address, e.g. `listen 10.0.0.12:80;`. If the front service runs on the same machine, use `listen 127.0.0.1:8090;`.
- **Allow only the front service.** Uncomment the `allow … / deny all;` lines in the nginx config and put in the front service's address(es). Everything else gets `403`.
- **Firewall.** e.g. `sudo ufw allow from 10.0.0.5 to any port 80 proto tcp`, with no other rule opening that port.

Check from a machine that *shouldn't* have access: the request should be refused or time out.

Plain HTTP is fine on a trusted private network. If traffic crosses networks you don't control, put TLS in front (e.g. `certbot --nginx`).

### 8. Start the worker

```bash
sudo cp /opt/vtt-aligner/deploy/vtt-aligner-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now vtt-aligner-worker
systemctl status vtt-aligner-worker
journalctl -u vtt-aligner-worker -f      # live log: one line per finished job
```

### 9. Check it works

```bash
curl -s http://vtt.example.internal/v1/health
```

You should see `"ok": true` and `"alive": true` for the worker. Then open `http://vtt.example.internal/` in a browser and try a real file on the upload page, or use the API (next section).

---

## Upload page

Open the service's root address (`/` or `/upload`) in a browser:

1. Choose (or drop) the narration audio: MP3, M4A, WAV, OGG/Opus or FLAC.
2. Choose the chapter's `.md` / `.txt`, or paste the text.
3. Press **Create .vtt**. The page shows upload and processing progress, then downloads the file automatically and shows a short summary: sentences timed, match quality, lines left out because they weren't read aloud, and anything worth a listen.

The result is named after the audio (`01 The Harbour.mp3` → `01 The Harbour.vtt`), so it lands next to the matching `.md`. Change it under **Options**, where you can also force plain-text/Markdown, keep unread headings, or add pronunciations. If the page is reloaded mid-job it picks the job back up.

The page uses the same API and limits as any other client, and is reachable by whoever can reach the service (see [Keep it internal](#keep-it-internal)).

---

## API

No authentication headers are needed. Responses are JSON (except the `.vtt` download). Errors look like:

```json
{ "error": { "code": "unsupported_audio", "message": "Unsupported audio type (text/plain). Use MP3, WAV, M4A/AAC, OGG/Opus, WebM or FLAC." } }
```

### `POST /v1/jobs` — submit

`multipart/form-data` fields:

| Field | Required | Notes |
|---|---|---|
| `audio` | yes | File: MP3, WAV, M4A/AAC, OGG/Opus, WebM or FLAC. Up to `max_upload_mb`. |
| `text` | yes | The narrated text, as a file **or** a plain form field. UTF-8, up to `max_text_kb`. |
| `format` | no | `markdown` (default) or `text`. A `.txt` file upload defaults to `text`. |
| `name` | no | Your label (≤200 chars), e.g. `01 The Harbour`. Used as the download file name. |
| `pronunciations` | no | Extra or corrected pronunciations, one per line: `word PHONES` in CMU/ARPAbet, e.g. `kaelen K EY L AH N`. Usually unnecessary: unknown words get pronunciations automatically. |
| `drop_unread` | no | `true` (default): leave out headings and quotes that aren't narrated. `false`: keep every sentence. |

```bash
curl -s \
  -F "audio=@01 The Harbour.mp3" \
  -F "text=@01 The Harbour.md" \
  -F "name=01 The Harbour" \
  http://vtt.example.internal/v1/jobs
```

`202 Accepted`, with a `Location` header:

```json
{
  "id": "7aa5a5879112c75417de0941d3070e8b",
  "status": "queued",
  "name": "01 The Harbour",
  "queue_position": 1,
  "links": { "self": "/v1/jobs/7aa5a5879112c75417de0941d3070e8b" }
}
```

### `GET /v1/jobs/{id}` — status

Poll every few seconds. `status` is `queued` → `running` → `done` or `failed`.

```json
{
  "id": "7aa5a5879112c75417de0941d3070e8b",
  "status": "running",
  "progress": { "stage": "transcribing", "percent": 55 }
}
```

When `done`, the response includes `links.vtt` and a `report`:

```json
{
  "status": "done",
  "links": { "self": "/v1/jobs/…", "vtt": "/v1/jobs/…/vtt" },
  "report": {
    "audio_seconds": 1085.93, "processing_seconds": 54.1,
    "sentences": 284, "cues": 279,
    "dropped_unread": ["The Harbour", "Narrator: Mara Quill.", "…"],
    "words_matched_pct": 89.2, "cues_anchored_at_start": 267,
    "boundaries": { "start": 1, "pause": 276, "pause*": 1, "word": 1 },
    "quality": { "median_match": 0.95, "low_match_cues": 13, "flagged": [] },
    "pronunciations_generated": ["kaelen", "quill", "veyra", "…"]
  }
}
```

Reading the report:
- `words_matched_pct` and `quality.median_match`: real narrations score about 80%+ and 0.85+. Jobs below 40% / 0.4 fail as "doesn't match this text".
- `dropped_unread`: sentences left out because they weren't heard (check this is what you expect).
- `quality.flagged`: cues whose audio sounded more like a neighbouring sentence. Usually empty; worth a listen if not.
- `low_match_cues`: usually short lines or invented names the recogniser struggles to *hear*. Their timing comes from the surrounding words and pauses, so this is not a problem in itself.

When `failed`, `error` explains why (e.g. the audio couldn't be decoded, or doesn't narrate this text).

### `GET /v1/jobs/{id}/vtt` — download

Returns `text/vtt` once the job is `done`; `409 not_ready` before that.

### `DELETE /v1/jobs/{id}` — remove

`204` on success; `409 busy` while the job is running. Finished jobs are also deleted automatically after `retention_hours`.

### `GET /v1/health`

`200` with `"ok": true` when the worker is alive; `503` when it isn't (e.g. stopped). Includes queue counts and limits.

### Status codes

| Code | Meaning |
|---|---|
| 400 | Missing field or bad option (`missing_field`, `bad_text`, `bad_option`) |
| 404 | Unknown job or endpoint |
| 405 | Wrong HTTP method |
| 409 | `.vtt` not ready yet, or deleting a running job |
| 413 | Upload too large |
| 415 | Not multipart, or not a supported audio type |
| 503 | Queue full (`Retry-After: 60`), or worker down (health) |
| 507 | Not enough disk space |

---

## Calling it from the front service (PHP)

```php
<?php
$api = 'http://vtt.example.internal/v1';   // internal address

function vtt_call(string $method, string $url, ?array $fields = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 600,
    ]);
    if ($fields !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
}

// 1. Submit
[$code, $body] = vtt_call('POST', "$api/jobs", [
    'audio' => new CURLFile('/path/01 The Harbour.mp3'),
    'text'  => new CURLFile('/path/01 The Harbour.md'),
    'name'  => '01 The Harbour',
]);
if ($code !== 202) throw new RuntimeException("submit failed: $body");
$job = json_decode($body, true);

// 2. Wait (in real use: store $job['id'] and check from a cron or queue instead of blocking)
do {
    sleep(5);
    [, $body] = vtt_call('GET', "$api/jobs/{$job['id']}");
    $job = json_decode($body, true);
} while (in_array($job['status'], ['queued', 'running'], true));
if ($job['status'] !== 'done') throw new RuntimeException("alignment failed: {$job['error']}");

// 3. Download and save next to the audio
[, $vtt] = vtt_call('GET', "$api/jobs/{$job['id']}/vtt");
file_put_contents('/path/01 The Harbour.vtt', $vtt);
vtt_call('DELETE', "$api/jobs/{$job['id']}");   // optional: tidy up now
```

---

## Command line (no API)

The worker's engine can be run directly, e.g. to test a file:

```bash
/opt/vtt-aligner/venv/bin/python /opt/vtt-aligner/worker/aligner.py \
    "01 The Harbour.mp3" "01 The Harbour.md" --report report.json
# writes "01 The Harbour.vtt" next to the audio
```

Options: `-o out.vtt`, `--format text`, `--pronunciations file.txt`, `--keep-unread`.

---

## Operations

| Task | How |
|---|---|
| Logs | `journalctl -u vtt-aligner-worker` (worker), nginx/PHP-FPM error logs (API) |
| Restart worker | `sudo systemctl restart vtt-aligner-worker`. A job in progress goes back to the queue and is redone. |
| Worker crashed mid-job | On its next start, jobs left "running" are re-queued automatically. |
| Disk use | Uploaded audio is deleted when a job finishes (`keep_inputs = false`); everything is removed after `retention_hours`. Each job briefly needs about 4× its audio size in temporary space. |
| Update | `git pull` in your md-reader clone, copy `utility/vtt-aligner` over `/opt/vtt-aligner` (your `config.ini` and `venv/` aren't in the repo, so they're kept), re-run the `pip install` line, `sudo systemctl restart vtt-aligner-worker`. |

### Troubleshooting

| Symptom | Cause and fix |
|---|---|
| **502 Bad Gateway** from nginx | nginx can't reach PHP-FPM. `tail /var/log/nginx/error.log`: if it says `php8.3-fpm.sock … No such file or directory`, your PHP version differs. Run `ls /run/php/` and put that socket name in `fastcgi_pass`, then `sudo nginx -t && sudo systemctl reload nginx`. If `/run/php/` is empty, start PHP-FPM: `sudo systemctl enable --now php8.X-fpm`. |
| Every request returns `not_configured` | The web server can't read `config.ini`. Run `sudo chown root:www-data /opt/vtt-aligner/config.ini && sudo chmod 640 /opt/vtt-aligner/config.ini` (needed again after any tool replaces the file). |
| `pip install` fails building pocketsphinx with `Could NOT find Python (missing: … Development.Module)` | No ready-made package exists for your Python version, and the headers needed to compile one are missing. Run `sudo apt install python3-dev build-essential` and re-run `pip install`. BISON/FLEX warnings are harmless. |
| `/v1/health` returns 503 with `"alive": false` | The web side works but the worker isn't running. Check `systemctl status vtt-aligner-worker` and `journalctl -u vtt-aligner-worker -n 50`. |
| Uploads fail with **413** | A size limit is lower than the file. Raise `max_upload_mb`, `public/.user.ini` and nginx's `client_max_body_size` together. Behind Cloudflare, Free/Pro plans also cap uploads at 100 MB: use MP3 rather than WAV, or reach the service directly (e.g. over a VPN or Tailscale). |
| Job fails with "the narration doesn't match this text" | The audio and text don't belong together, or the text is in a very different order from the narration. Check the pair; the report says what percentage of words were heard. |

### Files

```
vtt-aligner/
├── config.ini.example        settings (copy to config.ini)
├── public/                   web root: only this is served
│   ├── index.php
│   ├── .htaccess             Apache routing
│   └── .user.ini             PHP upload limits
├── src/api.php               the API
├── src/upload-page.php       the upload page (served at /)
├── worker/
│   ├── aligner.py            alignment engine (also a CLI)
│   ├── worker.py             queue worker
│   └── requirements.txt
└── deploy/
    ├── nginx-vtt-aligner.conf
    └── vtt-aligner-worker.service
```

### Security notes

- Only `public/index.php` is reachable over HTTP; config, code and job files are outside the web root.
- There is no authentication by design: access is controlled at the network/web-server level (see [Keep it internal](#keep-it-internal)).
- Uploads are checked by content (not file name): audio must really be audio, text must be UTF-8 with no control characters.
- Job IDs are 128-bit random hex; anything else is rejected before touching the disk.
- The worker runs as its own unprivileged user under systemd with a read-only filesystem except its storage folder.
- There's no rate limiting beyond the queue limit; keep the service on a private network or behind your reverse proxy's limits.
