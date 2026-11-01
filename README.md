# md-reader

**An easy-to-deploy Markdown reader with a little more for storytelling.**

Drop a folder of Markdown files on any PHP host and get a clean, readable site: a sidebar of chapters, Previous/Next links that run through the whole collection, a lightbox for images, 14 reading themes, a dyslexia-friendly font and adjustable text size. Add an MP3 beside a chapter and it gets an audio player; add a timing file and the text highlights sentence by sentence as it's read aloud.

No database, no build step, no Composer. Copy the files, put your `.md` files in `docs/`, done.

---

## Features

**Reading**
- Sidebar of your documents and folders, in natural order (`Chapter 2` before `Chapter 10`); only the folders leading to the page you're on are expanded.
- **Previous / Next** at the bottom of every page, running through the whole collection across folders, plus **Back to top**.
- Optional `header.md` and `footer.md` shown on every page (a site banner, a copyright line, a link back home).
- Images resolve relative to each Markdown file; click any image to view it full screen and swipe or arrow through the page's images.
- Links between Markdown files open inside the reader, with shareable URLs and working `#section` anchors.

**Comfort and accessibility**
- 14 themes: Light, Dark, Sepia, Solarized, Nord, Dracula, Gruvbox, One Dark, Catppuccin, Tokyo Night and more, or Auto to follow the device. All tuned to meet WCAG AA contrast.
- Adjustable text size and an OpenDyslexic font toggle, remembered per browser.
- Works well on phones: slide-out navigation, large touch targets, notch-aware layout.

**Audio narration and read-along**
- Put `chapter.mp3` next to `chapter.md` and a player appears, docked at the bottom: play/pause, skip ±15 s, seek, speed 0.75–2×, volume, lock-screen controls.
- Audio keeps playing while you browse; open another narrated chapter and it offers to switch. The player's Next button moves on to the next chapter's audio.
- Add `chapter.vtt` (sentence timings) and the sentence being read is highlighted and kept in view. Tap any sentence to jump there.
- Includes a tool to make those timing files for you: [`utility/vtt-aligner`](utility/vtt-aligner/).

**Secure by default**
- Strict path checking (no `../` tricks, symlink escapes or hidden files), a tight Content Security Policy, pinned and integrity-checked libraries, and sanitised Markdown HTML. Details in [Security](#security).

---

## Quick start

**Try it locally** (PHP 8.0+ installed):

```bash
git clone https://github.com/zeighy/md-reader.git
cd md-reader
php -S localhost:8000
```

Open http://localhost:8000. The sample pages in `docs/` show what the reader does.

**Put it online:** copy the repository's files to your web host (any PHP 8.0+ host: Apache, nginx + PHP-FPM, shared hosting), then replace the sample content in `docs/` with your own. See [Deploying](#deploying) for web server settings.

---

## Writing your content

Everything lives in the `docs/` folder (or another folder you point to in `config.php`):

```
docs/
├── header.md               ← optional: shown at the top of every page
├── footer.md               ← optional: shown at the bottom of every page
├── README.md               ← the start page
├── images/
│   └── map.png
├── 01 Arrival/
│   ├── 01 The Harbour.md
│   ├── 01 The Harbour.mp3  ← optional narration
│   ├── 01 The Harbour.vtt  ← optional read-along timings
│   └── 02 The Market.md
└── 02 Departure/
    └── 01 Night Crossing.md
```

- **Order:** the sidebar, and Previous/Next, follow file and folder names in natural order, with subfolders before files. Number your files (`01 …`, `02 …`) to control the order.
- **Start page:** the first of `README.md` or `index.md` that exists (configurable).
- **Images:** write them the usual way, relative to the Markdown file: `![Map](../images/map.png)`. A leading `/` means the docs folder root. PNG, JPG, GIF, WebP, AVIF, SVG, BMP and ICO are supported, and web images work too.
- **Links:** `[next chapter](02%20The%20Market.md)` or `[a section](notes.md#tables)` open inside the reader.
- **Header and footer:** `header.md` / `footer.md` at the top level of `docs/` appear on every page and never in the sidebar or Previous/Next. Paths inside them are relative to `docs/`.
- **HTML in Markdown** is allowed but sanitised: no scripts, styles, forms or iframes.

---

## Configuration

Edit `config.php`:

| Setting | Default | What it does |
|---|---|---|
| `docs_dir` | `__DIR__ . '/docs'` | The folder with your content. It can be outside the web root, which is the safest place for it. |
| `site_title` | `Markdown Viewer` | Shown in the sidebar and the browser tab. |
| `default_theme` | `auto` | The theme for first-time visitors: `auto`, `light`, `dark`, `sepia`, `solarized-light`, `solarized-dark`, `nord`, `dracula`, `gruvbox-light`, `gruvbox-dark`, `one-dark`, `catppuccin-latte`, `catppuccin-mocha`, `tokyo-night`. Visitors' own choice is remembered. |
| `default_files` | `['README.md', 'index.md']` | Which file opens when no page is requested. |

---

## Audio narration and read-along

### Adding narration

Put an audio file with the **same name** next to a chapter:

```
01 The Harbour.md
01 The Harbour.mp3     (also: m4a, aac, ogg, opus, webm, flac, wav)
```

That chapter now shows the player. Chapters without audio are unaffected.

**Tip:** encode narration as **constant-bitrate MP3**, e.g. 64 kbps mono for speech:
`ffmpeg -i narration.wav -c:a libmp3lame -b:a 64k -ac 1 "01 The Harbour.mp3"`.
Browsers seek precisely in constant-bitrate files, which keeps read-along in sync after skipping.

### How the player behaves

| Situation | Result |
|---|---|
| Nothing playing, page has audio | The audio is loaded and waits for you to press play |
| Nothing playing, page has no audio | The player stays out of the way |
| Audio playing, you open a page **with** audio | The current audio keeps playing; a prompt offers **Play it** to switch |
| Audio playing, you open a page **without** audio | The current audio keeps playing; a short note says the page has no audio |
| The player's **Next** button | Opens the next chapter and starts its audio |
| The **Next** link at the bottom of a page | Only changes the page |
| Audio reaches the end | It stops and waits |

The ⌄ tab hides the player behind a small round button. Speed, volume, read-along and hidden/shown are remembered. On phones, volume is left to the hardware buttons (iPhones don't let web pages change volume at all).

### Read-along

Add a WebVTT timing file with one cue per sentence:

```
01 The Harbour.vtt
```

```
WEBVTT

00:00:00.000 --> 00:00:03.200
The boat came in on a flat pale morning.

00:00:03.200 --> 00:00:05.900
Nobody on the quay looked up.
```

The sentence being read is highlighted in the theme's accent colour and kept in view. Scroll away and a **Back to reading position** button appears. Tap a sentence to jump the audio to it. Cues are matched to the page text tolerantly (punctuation, quote styles, accents and small wording differences don't matter), and narration that isn't on the page is simply not highlighted.

### Making timing files

- **[`utility/vtt-aligner`](utility/vtt-aligner/)** (included): a small self-hosted service. Upload the MP3 and the chapter's `.md` on its web page and download the `.vtt`, or call its API from another system. It runs entirely on your own server, generates pronunciations for invented names automatically, and skips titles and intro lines the narrator doesn't read. See its [guide](utility/vtt-aligner/README.md).
- **[aeneas](https://github.com/readbeyond/aeneas)**: with the sentences in a text file, one per line,
  `python -m aeneas.tools.execute_task "ch.mp3" "ch.txt" "task_language=eng|is_text_type=plain|os_task_file_format=vtt" "ch.vtt"`.
- **[WhisperX](https://github.com/m-bain/whisperX)**: `whisperx "ch.mp3" --output_format vtt` works from the audio alone; its segments are usually close to sentences.

Read-along needs a current browser (Chrome, Edge, Firefox, or Safari 17.2+). On older ones the audio still plays without highlighting.

---

## Deploying

### Requirements
- PHP 8.0 or newer with `mbstring` (standard on most hosts). No other extensions, no database.
- Any web server that runs PHP. Apache and nginx configurations are included.

### Apache / shared hosting
Upload the files. The included `.htaccess` files block direct access to `docs/`, the config, dotfiles and `utility/`, and turn off directory listings. Nothing else is needed.

### nginx + PHP-FPM
Use [`nginx.conf.example`](nginx.conf.example) as your server block. Set `server_name`, `root`, and the PHP-FPM socket: `ls /run/php/` shows its name (e.g. `php8.3-fpm.sock`). A wrong socket name gives **502 Bad Gateway**.

### Recommended
- **Keep content outside the web root:** set `docs_dir` to e.g. `/srv/stories`. The reader serves files from it safely either way, but then nothing can reach them except through the reader.
- **Use HTTPS.**
- **Self-host the libraries (optional):** htmx, marked and DOMPurify load from public CDNs, pinned with integrity hashes; the OpenDyslexic font loads only when its toggle is on. For an offline or locked-down network, download them next to `index.php` and update the three `<script src>` lines in `index.php` and the `@font-face` URLs at the top of `style.css`.

### Behind Cloudflare (or another CDN)
- Cloudflare caches `.js` and `.css` files at its edge for about a day. **After updating the reader, purge the cache** (Caching → Configuration → Purge Cache) or browsers will keep running the old scripts. While testing, *Development Mode* bypasses the cache for 3 hours.
- Keep **Rocket Loader** off: it rewrites script tags and conflicts with the reader's security policy.
- Images and audio go through `api.php`, so Cloudflare doesn't cache them and replaced files show up immediately.

---

## Security

The only input the reader accepts is the URL (`?file=…` and `?action=…`), and it treats both as hostile:

- **Paths** are checked as text first: no absolute paths, drive letters or stream wrappers (`php://`), no `..`, no hidden files (`.git`, `.env`), no Windows device names, length and depth limits. Then they're resolved with `realpath()` (following symlinks) and must land inside the docs folder with the right real file type. Missing and forbidden files get the same "Not found", so nothing can be probed.
- **Responses** carry a strict Content Security Policy: only the site's own files, the pinned CDN scripts (with Subresource Integrity) and one nonce-tagged inline script may run. No framing, no forms, no plugins. Only GET/HEAD requests are accepted, and PHP errors are logged, never shown.
- **Markdown HTML** is sanitised with DOMPurify plus extra rules: no scripts, event handlers, `javascript:` links, styles, forms or iframes, and no htmx attributes.
- **Media** (images, audio, timing files) is served with `nosniff` and a sandboxing policy, so an SVG opened directly can't run script. Range requests are supported for seeking.

---

## Customising

### Adding a theme
1. In `style.css`, copy one of the `:root[data-theme="…"]` blocks, give it a new id, and set its colours.
2. In `lib.php`, add the id and its menu label to `THEME_GROUPS`, under Light or Dark.

The theme menu and saved settings both read from that list, so nothing else needs changing.

### Project layout

| File | Role |
|---|---|
| `index.php` | The page: sidebar, reading controls, the current document, the audio player |
| `api.php` | Serves document fragments (for in-page navigation) and media (images, audio, timing files) |
| `lib.php` | Path safety, the document tree and reading order, themes, rendering |
| `config.php` | Your settings |
| `app.js` | Markdown rendering, links and images, reading controls, lightbox, navigation |
| `player.js` | The audio player and read-along |
| `style.css` | Layout and themes |
| `docs/` | Your content (sample pages included) |
| `utility/vtt-aligner/` | Optional tool for making read-along timing files ([guide](utility/vtt-aligner/README.md)) |

---

## Credits

Built with [htmx](https://htmx.org/), [marked](https://marked.js.org/) and [DOMPurify](https://github.com/cure53/DOMPurify). Dyslexia-friendly font: [OpenDyslexic](https://opendyslexic.org/) (SIL Open Font License), served via [Fontsource](https://fontsource.org/). The VTT aligner uses [CMU PocketSphinx](https://github.com/cmusphinx/pocketsphinx), [espeak-ng](https://github.com/espeak-ng/espeak-ng) and [FFmpeg](https://ffmpeg.org/).
