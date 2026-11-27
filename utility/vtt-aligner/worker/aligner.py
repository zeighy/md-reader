"""
Sentence-level read-along timings (WebVTT) from narration audio + its text.

    python aligner.py narration.mp3 chapter.md -o chapter.vtt [--report report.json]

How it works
  1. Audio is converted to 16 kHz mono and its pauses are mapped.
  2. The text (Markdown or plain) is split into the sentences a reader sees.
     Code blocks, tables and image captions are never included.
  3. Words the recogniser doesn't know (names, coinages) get pronunciations
     from espeak-ng; a language model is built from this text alone, so the
     recogniser expects exactly these sentences.
  4. The audio is transcribed with word timestamps (CMU PocketSphinx), in
     parallel chunks split at pauses.
  5. Page words are aligned to heard words (banded Needleman-Wunsch), giving
     each sentence's first word a time.
  6. Each boundary is moved into the pause before that sentence; crowded
     quick-fire dialogue is spread across the available pauses.
  7. Headings and quotes that were never read aloud are dropped.
  8. A report scores every cue against what was heard in it.

English only (PocketSphinx en-us model).
"""
import bisect
import difflib
import json
import math
import os
import re
import shutil
import subprocess
import tempfile
import time
import unicodedata
import wave
from collections import Counter, defaultdict
from functools import lru_cache
import multiprocessing

import numpy as np
from markdown_it import MarkdownIt
from pocketsphinx import Decoder, get_model_path

VERSION = "1.0.0"


class AlignError(Exception):
    """A problem with the input that the caller should be told about."""


# ---------------------------------------------------------------------------
# Text
# ---------------------------------------------------------------------------

ABBREV = {"mr", "mrs", "ms", "dr", "st", "sr", "jr", "vs", "etc", "e.g", "i.e", "vol",
          "fig", "mt", "prof", "capt", "gen", "lt", "col", "sgt", "rev", "hon", "ave"}


def split_sentences(p):
    out, start = [], 0
    for m in re.finditer(r'[.!?…]+["”’)\]]*\s+', p):
        nxt = p[m.end():m.end() + 1]
        if not nxt or not (nxt.isupper() or nxt.isdigit() or nxt in '"“‘(['):
            continue
        if m.group(0)[0] == ".":
            prev = re.search(r"([A-Za-z.]+)\.$", p[start:m.start() + 1])
            if prev and prev.group(1).lower() in ABBREV:
                continue
        out.append(p[start:m.end()].strip())
        start = m.end()
    out.append(p[start:].strip())
    return [x for x in out if x]


def _inline_text(tok):
    parts = []
    for c in tok.children or []:
        if c.type in ("text", "code_inline"):
            parts.append(c.content)
        elif c.type in ("softbreak", "hardbreak"):
            parts.append(" ")
        # images (alt text) and inline HTML are not read aloud
    return re.sub(r"\s+", " ", "".join(parts)).strip()


def markdown_blocks(md):
    """[(kind, text)] for each block of readable text; kind is heading/quote/para."""
    tokens = MarkdownIt("commonmark").enable("table").enable("strikethrough").parse(md)
    blocks, quote, table = [], 0, 0
    for i, t in enumerate(tokens):
        if t.type == "blockquote_open": quote += 1
        elif t.type == "blockquote_close": quote -= 1
        elif t.type == "table_open": table += 1
        elif t.type == "table_close": table -= 1
        elif t.type == "inline" and not table:
            kind = "heading" if tokens[i - 1].type == "heading_open" else ("quote" if quote else "para")
            text = _inline_text(t)
            if re.search(r"\w", text):
                blocks.append((kind, text))
    return blocks


def text_blocks(txt):
    return [("para", re.sub(r"\s+", " ", b).strip()) for b in re.split(r"\n\s*\n", txt) if re.search(r"\w", b)]


def ascii_fold(s):
    return unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()


def words(s):
    """Lower-case letters/digits only: what alignment compares."""
    s = ascii_fold(s.lower().replace("’", "").replace("'", ""))
    return re.sub(r"[^a-z0-9]+", " ", s).split()


def lm_tokens(s):
    """Recogniser vocabulary tokens (keeps apostrophes: don't, harl's)."""
    s = ascii_fold(s.lower().replace("’", "'").replace("‘", "'"))
    return [t.strip("'") for t in re.findall(r"[a-z0-9']+", s) if t.strip("'")]


# ---------------------------------------------------------------------------
# Pronunciations
# ---------------------------------------------------------------------------

PHONES = set("AA AE AH AO AW AY B CH D DH EH ER EY F G HH IH IY JH K L M N NG OW OY P R S SH T TH UH UW V W Y Z ZH".split())
IPA = sorted({
    "tʃ": "CH", "dʒ": "JH", "oʊ": "OW", "aɪ": "AY", "eɪ": "EY", "aʊ": "AW", "ɔɪ": "OY",
    "ɜː": "ER", "ɝ": "ER", "ɚ": "ER", "ɜ": "ER", "iː": "IY", "i": "IY", "uː": "UW", "u": "UW",
    "ɪ": "IH", "ᵻ": "IH", "ɛ": "EH", "e": "EH", "æ": "AE", "a": "AE", "ɑː": "AA", "ɑ": "AA", "ɒ": "AA",
    "ɔː": "AO", "ɔ": "AO", "oː": "AO", "o": "OW", "ʌ": "AH", "ə": "AH", "ɐ": "AH", "ʊ": "UH",
    "p": "P", "b": "B", "t": "T", "d": "D", "k": "K", "ɡ": "G", "g": "G", "f": "F", "v": "V",
    "θ": "TH", "ð": "DH", "s": "S", "z": "Z", "ʃ": "SH", "ʒ": "ZH", "h": "HH", "m": "M", "n": "N",
    "ŋ": "NG", "l": "L", "ɹ": "R", "r": "R", "w": "W", "j": "Y", "ɾ": "T", "ʔ": "T", "x": "K", "ɬ": "L",
}.items(), key=lambda kv: -len(kv[0]))


def ipa_to_arpabet(ipa):
    s = ipa.replace("ˈ", "").replace("ˌ", "").replace("̩", "").replace("‿", "")
    out, i = [], 0
    while i < len(s):
        for k, v in IPA:
            if s.startswith(k, i):
                for ph in v.split():
                    if not out or out[-1] != ph or ph in ("AH", "IH"):   # collapse doubled consonants
                        out.append(ph)
                i += len(k)
                break
        else:
            i += 1
    return " ".join(p for p in out if p in PHONES)


def espeak_pronunciation(word):
    try:
        r = subprocess.run(["espeak-ng", "-q", "--ipa", "-v", "en-us"], input=word, capture_output=True,
                           text=True, timeout=10)
    except (OSError, subprocess.TimeoutExpired):
        return None
    arpa = ipa_to_arpabet(r.stdout.strip())
    return arpa or None


def parse_pronunciations(text):
    """User overrides: 'word PH ON EMES' per line (ARPAbet, as in the CMU dictionary)."""
    out = {}
    for n, line in enumerate((text or "").splitlines(), 1):
        line = line.split("#", 1)[0].strip()
        if not line:
            continue
        parts = line.split()
        w = parts[0].lower()
        phones = [re.sub(r"\d", "", p.upper()) for p in parts[1:]]
        if not re.fullmatch(r"[a-z0-9']+", w) or not phones or any(p not in PHONES for p in phones):
            raise AlignError(f"pronunciations line {n}: expected 'word PHONES' using CMU/ARPAbet phones")
        out[w] = " ".join(phones)
    return out


# ---------------------------------------------------------------------------
# Audio
# ---------------------------------------------------------------------------

def to_wav16k(src, dst):
    r = subprocess.run(["ffmpeg", "-nostdin", "-v", "error", "-y", "-i", src, "-vn", "-ac", "1", "-ar", "16000",
                        "-c:a", "pcm_s16le", dst], capture_output=True, text=True)
    if r.returncode != 0 or not os.path.exists(dst) or os.path.getsize(dst) < 1000:
        raise AlignError("the audio file could not be decoded")


def frame_db(x, sr):
    """Loudness (dBFS) of each 10 ms frame."""
    hop = sr // 100
    n = len(x) // hop
    if n == 0:
        raise AlignError("the audio is empty")
    rms = np.sqrt((x[:n * hop].reshape(n, hop).astype(np.float32) ** 2).mean(axis=1) + 1e-9)
    return 20 * np.log10(rms / 32768 + 1e-12)


def quietest_point(db, lo, hi):
    """Time (s) of the quietest ~50 ms in [lo, hi]: the gap between words, even
    under music or background noise that hides real pauses."""
    a, b = max(0, int(lo * 100)), min(len(db), int(hi * 100))
    if b - a < 5:
        return hi
    seg = np.convolve(db[a:b], np.ones(5) / 5, mode="valid")  # seg[k] centres on frame a+k+2
    return (a + int(np.argmin(seg)) + 2) / 100


def find_pauses(x, sr, db=None):
    if db is None:
        db = frame_db(x, sr)
    n = len(db)
    quiet = db < np.percentile(db, 90) - 30
    pauses, i = [], 0
    while i < n:
        if quiet[i]:
            j = i
            while j < n and quiet[j]:
                j += 1
            if j - i >= 12:
                pauses.append((i / 100, j / 100))
            i = j
        else:
            i += 1
    return pauses, n / 100


# ---------------------------------------------------------------------------
# Recognition (worker-process globals so each process loads the model once)
# ---------------------------------------------------------------------------

_DEC = None
_WAV = None
_SR = 16000


def _init(lm, dic, wav_path):
    global _DEC, _WAV
    import signal
    signal.signal(signal.SIGTERM, signal.SIG_DFL)  # only the main worker handles shutdown
    _DEC = Decoder(samprate=_SR, lm=lm, dict=dic, logfn=os.devnull)
    _WAV = wav_path


def _decode(span):
    s, e = span
    start_frame = max(0, int(s * _SR))
    end_frame = max(start_frame, int(e * _SR))
    with wave.open(_WAV, "rb") as w:
        w.setpos(min(start_frame, w.getnframes()))
        raw = w.readframes(end_frame - start_frame)
    if not raw:
        return []
    _DEC.start_utt()
    _DEC.process_raw(raw, full_utt=True)
    _DEC.end_utt()
    out = []
    for seg in _DEC.seg():
        wd = seg.word
        if wd.startswith(("<", "[")):
            continue
        out.append((wd.split("(")[0], round(s + seg.start_frame / 100, 2), round(s + (seg.end_frame + 1) / 100, 2)))
    return out


def build_lm(sents_tokens, path):
    corpus = [["<s>"] + t + ["</s>"] for t in sents_tokens if t]
    uni = Counter(t for s in corpus for t in s)
    bi = Counter((a, b) for s in corpus for a, b in zip(s, s[1:]))
    N = sum(c for w, c in uni.items() if w != "<s>")
    V = len(uni)
    D = 0.5
    p_uni = {w: (uni[w] + 1) / (N + V) for w in uni}
    p_uni["<s>"] = 1e-9
    follow = defaultdict(list)
    for (a, b), c in bi.items():
        follow[a].append((b, c))
    lines, bow = [], {}
    for a, lst in follow.items():
        ca = sum(c for _, c in lst)
        seen = sum(p_uni[b] for b, _ in lst)
        for b, c in lst:
            lines.append((a, b, (c - D) / ca))
        bow[a] = (D * len(lst) / ca) / max(1e-9, 1 - seen)
    with open(path, "w") as f:
        f.write(f"\\data\\\nngram 1={len(uni)}\nngram 2={len(lines)}\n\n\\1-grams:\n")
        for w in sorted(uni):
            f.write(f"{math.log10(p_uni[w]):.4f} {w} {math.log10(bow.get(w, 1.0)):.4f}\n")
        f.write("\n\\2-grams:\n")
        for a, b, p in lines:
            f.write(f"{math.log10(p):.4f} {a} {b}\n")
        f.write("\n\\end\\\n")


# ---------------------------------------------------------------------------
# Alignment
# ---------------------------------------------------------------------------

def align_words(P, A, band=200):
    """Banded global alignment. Returns [(page_index, heard_index)] for matches."""
    n, m = len(P), len(A)
    freq = Counter(P)

    @lru_cache(maxsize=None)
    def score(a, b):
        if a == b:
            return 1.0 + min(2.0, len(a) / 3) + (1.0 if freq[a] <= 3 else 0.0)
        if len(a) >= 4 and len(b) >= 4 and difflib.SequenceMatcher(None, a, b).ratio() >= 0.75:
            return 1.0
        return -1.0

    # Coarse diagonal from long exact runs keeps the band on track even across
    # long unread passages or unscripted intros/interludes.
    sm = difflib.SequenceMatcher(None, P, A, autojunk=False)
    lo_anc = {0: 0, n: m}
    hi_anc = {0: 0, n: m}
    for b in sm.get_matching_blocks():
        if b.size >= 3:
            for k in range(b.size):
                for x, y in ((b.a + k, b.b + k), (b.a + k + 1, b.b + k + 1)):
                    lo_anc[x] = min(lo_anc[x], y) if x in lo_anc else y
                    hi_anc[x] = max(hi_anc[x], y) if x in hi_anc else y
    lo_pts = sorted(lo_anc.items())
    hi_pts = sorted(hi_anc.items())
    grid = np.arange(n + 1)
    lo_c = np.interp(grid, np.array([p[0] for p in lo_pts], dtype=float),
                     np.array([p[1] for p in lo_pts], dtype=float)).astype(int)
    hi_c = np.interp(grid, np.array([p[0] for p in hi_pts], dtype=float),
                     np.array([p[1] for p in hi_pts], dtype=float)).astype(int)
    lo = [max(0, int(c) - band) for c in lo_c]
    hi = [min(m, int(c) + band) for c in hi_c]
    lo[0] = 0
    hi[n] = m
    for i in range(1, n + 1):
        if lo[i] > hi[i - 1] + 1:
            lo[i] = hi[i - 1] + 1
        if hi[i] < lo[i - 1]:
            hi[i] = lo[i - 1]
    GAP, NEG = -0.8, -1e18
    prev = [NEG] * (hi[0] - lo[0] + 1)
    prev[0] = 0.0
    for j in range(1, hi[0] + 1):
        prev[j] = prev[j - 1] + GAP
    trace = [bytes(len(prev))]
    for i in range(1, n + 1):
        L, H = lo[i], hi[i]
        pl, ph = lo[i - 1], hi[i - 1]
        row = [NEG] * (H - L + 1)
        tb = bytearray(H - L + 1)
        pw = P[i - 1]
        for j in range(L, H + 1):
            best, t = NEG, 3
            if j > 0 and pl <= j - 1 <= ph:
                v = prev[j - 1 - pl]
                if v > NEG / 2:
                    v += score(pw, A[j - 1])
                    if v > best: best, t = v, 0
            if pl <= j <= ph:
                v = prev[j - pl]
                if v > NEG / 2 and v + GAP > best: best, t = v + GAP, 1
            if j > L:
                v = row[j - 1 - L]
                if v > NEG / 2 and v + GAP > best: best, t = v + GAP, 2
            row[j - L] = best
            tb[j - L] = t
        prev = row
        trace.append(bytes(tb))
    # end: prefer finishing at the last heard word
    L, H = lo[n], hi[n]
    j = max(range(L, H + 1), key=lambda jj: prev[jj - L] + GAP * (m - jj))
    i, pairs = n, []
    while i > 0 and j >= 0:
        t = trace[i][j - lo[i]] if lo[i] <= j <= hi[i] else 3
        if t == 0:
            if score(P[i - 1], A[j - 1]) > 0:
                pairs.append((i - 1, j - 1))
            i, j = i - 1, j - 1
        elif t == 1:
            i -= 1
        elif t == 2:
            j -= 1
        else:
            break
    pairs.reverse()
    return pairs


def _boundary(a, b):
    return round(max(a + (b - a) / 2, b - 0.35), 3)


def place_cues(sents, page_words, owner, asr, pauses, dur, drop_unread=True):
    """Return (cues, stats). cues: [{start, end, text, how}]"""
    asr_words = [(words(w) or [""])[0] for w, _, _ in asr]
    pairs = align_words(page_words, asr_words)
    if len(pairs) < max(3, len(page_words) * 0.4):
        raise AlignError("the narration doesn't match this text: only "
                         f"{100 * len(pairs) / max(1, len(page_words)):.0f}% of its words were heard (real narrations score 80%+)")
    anchors = [(pi, asr[hi][1], asr[hi][2]) for pi, hi in pairs]
    ai = np.array([a[0] for a in anchors])
    at = np.array([a[1] for a in anchors])
    onset = np.interp(np.arange(len(page_words)), ai, at)
    anchored = set(ai.tolist())
    anchored_end = {a[0]: a[2] for a in anchors}
    # Narrator's pace (seconds per word), from neighbouring heard words.
    steps = [at[k + 1] - at[k] for k in range(len(ai) - 1) if ai[k + 1] - ai[k] == 1]
    spw = float(np.clip(np.median(steps), 0.15, 1.0)) if steps else 0.35

    heard_as = dict(pairs)  # page word -> index into asr
    pause_starts = [a for a, _ in pauses]

    def first_word_time(idx):
        """When the sentence's first word was (probably) said.

        Unscripted audio (an announcement, a side change) comes out of the
        recogniser as stray words from the book itself, so a short opening word
        like "the" can match a stray copy seconds before the sentence starts.
        A real match is followed by the rest of the sentence; a stray one is
        followed by a run of extra heard words before the next match (or, for a
        short word, by an extra word and a silence no sentence has between
        neighbouring words), so such leading matches are set aside. A narrator's dramatic pause after the
        first word adds silence but no words, so it is kept. Then, if the first word wasn't heard,
        count back from the first trusted match at the narrator's pace, rather
        than interpolating from the previous sentence across the stray audio."""
        fw = idx[0]
        hits = [k for k in idx if k in anchored]
        # Look for that break after the first or second match (strays can come
        # in pairs, "the ... was"), and only set aside the smaller side.
        cut = 0
        for j in range(min(2, len(hits) - 1)):
            a, b = hits[j], hits[j + 1]
            extra = (heard_as[b] - heard_as[a] - 1) - (b - a - 1)  # heard words with no page word to explain them
            gap = asr[heard_as[b]][1] - asr[heard_as[a]][2] - (b - a - 1) * spw
            # The weaker sign (one extra word and a silence) only counts against
            # short common words, the ones that recur nearby: noise can add a
            # word to a narrator's pause, but rarely right after "Mara".
            if (extra >= 3 or (extra >= 1 and gap > 0.6 and len(page_words[a]) <= 3)) \
                    and len(hits) - (j + 1) >= j + 1:
                cut = j + 1
        hits = hits[cut:]
        if not hits:
            return float(onset[fw])
        first = hits[0]
        if first == fw:
            return float(onset[fw])
        prior = np.searchsorted(ai, fw) - 1  # last heard word before this sentence
        floor = anchored_end[int(ai[prior])] if prior >= 0 else 0.0
        return max(floor, count_back(float(onset[first]), first - fw))

    def count_back(t, n_words):
        """Step back from time t over n_words of speech at the narrator's pace,
        skipping silences (a dramatic pause holds no words). Sentences start at
        pauses, so stop at a pause once the speech after it accounts for all but
        about one of the words (and for some of them, so that a pause straight
        after an unheard first word isn't taken for the sentence's start)."""
        need, got, pos = n_words * spw, 0.0, t
        for k in range(bisect.bisect_left(pause_starts, pos) - 1, -1, -1):
            a, b = pauses[k]
            seg = max(0.0, pos - b)
            if got + seg >= need:
                return pos - (need - got)
            got += seg
            if need - got <= spw and got >= 0.3 * need:
                return b
            pos = a
        return max(0.0, pos - (need - got))

    by_sent = defaultdict(list)
    for k, s in enumerate(owner):
        by_sent[s].append(k)

    # Drop headings/quotes that were never read, and long passages with nothing heard.
    keep, dropped = [], []
    for i, s in enumerate(sents):
        idx = by_sent[i]
        ratio = sum(1 for k in idx if k in anchored) / max(1, len(idx))
        unread = (s["kind"] in ("heading", "quote") and ratio < 0.34) or (len(idx) >= 6 and ratio < 0.1)
        if drop_unread and unread:
            dropped.append(s["text"])
        elif idx:
            keep.append(i)

    merged = []
    for a, b in pauses:
        if merged and a - merged[-1][1] < 0.1:
            merged[-1] = (merged[-1][0], b)
        else:
            merged.append((a, b))

    starts, how = [], []
    prev_idx = None
    for n_, i in enumerate(keep):
        t = first_word_time(by_sent[i])
        best = next(((a, b) for a, b in pauses if a - 0.1 <= t <= b + 0.1), None)
        tag = "pause"
        if best is None:
            for a, b in pauses:
                if t - 0.7 <= b <= t + 0.4 and (best is None or abs(b - t) < abs(best[1] - t)):
                    best = (a, b)
        if best is None and prev_idx is not None:
            prev_anchored = [k for k in by_sent[prev_idx] if k in anchored_end]
            prev_end = anchored_end[prev_anchored[-1]] if prev_anchored else t - 2
            cands = [(a, b) for a, b in pauses if a >= prev_end - 0.15 and a < t + 0.4 and b - a >= 0.25]
            if cands:
                # Nearest to the estimated start, with a nudge towards longer pauses.
                best = min(cands, key=lambda p: abs(p[1] - t) - 0.25 * min(p[1] - p[0], 2.0))
                tag = "pause*"
        if n_ == 0 and best is not None and best[0] <= 0.05:
            starts.append(0.0); how.append("start")
        elif best:
            starts.append(_boundary(*best)); how.append(tag)
        else:
            starts.append(round(max(0.0, t - 0.05), 3)); how.append("word")
        prev_idx = i
    for k in range(1, len(starts)):
        if starts[k] <= starts[k - 1] + 0.2:
            starts[k] = round(starts[k - 1] + 0.2, 3)
            how[k] = "forced"

    # Crowded quick-fire dialogue: spread forced boundaries across the pauses
    # between the trustworthy boundaries on either side.
    k = 1
    while k < len(starts):
        if how[k] != "forced":
            k += 1
            continue
        j = k
        while j + 1 < len(starts) and how[j + 1] == "forced":
            j += 1
        run = list(range(max(1, k - 1), j + 1))
        L = starts[run[0] - 1]
        R = starts[j + 1] if j + 1 < len(starts) else dur
        cands = [(a, b) for a, b in merged if a >= L + 0.25 and b <= R - 0.05 and b - a >= 0.25]
        if len(cands) >= len(run):
            pick = sorted(sorted(cands, key=lambda p: p[1] - p[0], reverse=True)[:len(run)])
            for r, (a, b) in zip(run, pick):
                starts[r] = _boundary(a, b)
                how[r] = "pause+"
        k = j + 1

    starts = [min(round(max(0.0, s), 3), round(dur, 3)) for s in starts]
    cues = []
    for n_, i in enumerate(keep):
        end = starts[n_ + 1] if n_ + 1 < len(keep) else round(dur, 3)
        cues.append({"start": starts[n_], "end": max(starts[n_], round(end, 3)), "text": sents[i]["text"], "how": how[n_]})

    first3 = sum(1 for i in keep if any(k in anchored for k in by_sent[i][:3]))
    stats = {
        "sentences": len(sents), "cues": len(cues), "dropped_unread": dropped,
        "words_matched_pct": round(100 * len(anchored) / max(1, len(page_words)), 1),
        "cues_anchored_at_start": first3,
        "boundaries": dict(Counter(how)),
    }
    return cues, stats


def check_cues(cues, asr):
    """Score each cue by the words heard inside it; flag likely mistimings."""
    def sim(a, b):
        return difflib.SequenceMatcher(None, a, b, autojunk=False).ratio()
    mids = [((s + e) / 2, (words(w) or [""])[0]) for w, s, e in asr]
    times = [m[0] for m in mids]
    scores, flagged = [], []
    for i, c in enumerate(cues):
        a, b = bisect.bisect_left(times, c["start"]), bisect.bisect_left(times, c["end"])
        heard = [w for _, w in mids[a:b] if w]
        own = sim(heard, words(c["text"]))
        nb = max([sim(heard, words(cues[k]["text"])) for k in (i - 1, i + 1) if 0 <= k < len(cues)] or [0])
        scores.append(own)
        if nb > own and nb > 0.3:
            flagged.append({"cue": i + 1, "start": c["start"], "text": c["text"], "heard": " ".join(heard),
                            "reason": "audio matches a neighbouring sentence better"})
    s = sorted(scores) or [0]
    return {"median_match": round(s[len(s) // 2], 2), "low_match_cues": sum(1 for x in scores if x < 0.5),
            "flagged": flagged}


def to_vtt(cues, title=None):
    def ts(t):
        t = max(0.0, t)
        return f"{int(t // 3600):02d}:{int(t % 3600 // 60):02d}:{t % 60:06.3f}"

    def esc(s):
        return (s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                 .replace("\r", " ").replace("\n", " "))

    clean_title = title.replace("-->", "->").replace("\r", " ").replace("\n", " ") if title else None
    out = ["WEBVTT", "", "NOTE", f"Sentence timings for read-along{(': ' + clean_title) if clean_title else ''}.",
           f"Generated by vtt-aligner {VERSION}; one cue per sentence.", ""]
    for i, c in enumerate(cues, 1):
        out += [str(i), f"{ts(c['start'])} --> {ts(c['end'])}", esc(c["text"]), ""]
    return "\n".join(out)


# ---------------------------------------------------------------------------
# Whole pipeline
# ---------------------------------------------------------------------------

def run(audio_path, text, fmt="markdown", pronunciations="", drop_unread=True, cores=0,
        max_minutes=240, progress=None, title=None, tmp_dir=None):
    """Align and return (vtt_text, report). Raises AlignError for bad input."""
    t0 = time.time()
    say = progress or (lambda stage, pct=None: None)
    cores = cores or max(1, os.cpu_count() or 1)
    work = tempfile.mkdtemp(prefix="vtt-aligner-", dir=tmp_dir)
    try:
        say("preparing text")
        text = text.lstrip("﻿")
        blocks = markdown_blocks(text) if fmt == "markdown" else text_blocks(text)
        sents = [{"kind": k, "text": s} for k, b in blocks for s in split_sentences(b)]
        page_words, owner = [], []
        for i, s in enumerate(sents):
            for w in words(s["text"]):
                page_words.append(w)
                owner.append(i)
        if len(page_words) < 5:
            raise AlignError("the text has no readable sentences")

        say("decoding audio")
        wav = os.path.join(work, "a16k.wav")
        to_wav16k(audio_path, wav)
        with wave.open(wav, "rb") as w:
            x = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16)
        if len(x) / _SR > max_minutes * 60:
            raise AlignError(f"the audio is longer than the {max_minutes}-minute limit")
        db = frame_db(x, _SR)
        pauses, dur = find_pauses(x, _SR, db)
        del x

        say("building vocabulary")
        base = os.path.join(get_model_path(), "en-us", "cmudict-en-us.dict")
        known = set()
        with open(base, encoding="utf-8", errors="ignore") as f:
            for line in f:
                known.add(line.split(" ", 1)[0].split("(")[0])
        overrides = parse_pronunciations(pronunciations)
        toks = [lm_tokens(s["text"]) for s in sents]
        extra, generated = dict(overrides), []
        for t in sorted({t for ts in toks for t in ts}):
            if t in extra or t in known:
                continue
            p = espeak_pronunciation(t)
            if p:
                extra[t] = p
                generated.append(t)
        dic = os.path.join(work, "words.dict")
        shutil.copyfile(base, dic)
        with open(dic, "a", encoding="utf-8") as f:
            for wd, ph in extra.items():
                if wd in known:  # override: add as an alternative pronunciation
                    f.write(f"{wd}(9) {ph}\n")
                else:
                    f.write(f"{wd} {ph}\n")
        vocab = known | set(extra)
        lm = os.path.join(work, "text.lm")
        lm_sents = [[t for t in ts if t in vocab] for ts in toks]
        if not any(lm_sents):
            raise AlignError("none of the words in the text could be matched to a pronunciation")
        build_lm(lm_sents, lm)

        # Chunks of ~18-60 s cut in the middle of pauses. Where no pause turns up
        # for 60 s (narration over music or noise), cut at the quietest moment
        # 30-60 s in, so a cut lands between words rather than through one.
        cuts, last = [0.0], 0.0
        for a, b in pauses:
            mid = (a + b) / 2
            while mid - last > 60:
                last = quietest_point(db, last + 30, last + 60)
                cuts.append(last)
            if (b - a >= 0.4 and mid - last >= 18) or (mid - last >= 45):
                cuts.append(mid)
                last = mid
        while dur - last > 60:
            last = quietest_point(db, last + 30, last + 60)
            cuts.append(last)
        if dur > last:
            cuts.append(dur)
        chunks = list(zip(cuts[:-1], cuts[1:]))
        asr = []
        # Start each recogniser as a fresh interpreter, never by fork(): the
        # worker runs a heartbeat thread and numpy starts its own threads (even
        # in a forkserver), and forking a threaded process can deadlock children.
        ctx = multiprocessing.get_context("spawn")
        pool = ctx.Pool(min(cores, max(1, len(chunks))), initializer=_init, initargs=(lm, dic, wav))
        try:
            for n, res in enumerate(pool.imap(_decode, chunks), 1):
                asr.extend(res)
                say("transcribing", round(100 * n / len(chunks)))
            pool.close()
        except BaseException:
            pool.terminate()
            raise
        finally:
            pool.join()

        say("aligning")
        cues, stats = place_cues(sents, page_words, owner, asr, pauses, dur, drop_unread)
        quality = check_cues(cues, asr)
        if quality["median_match"] < 0.4:
            raise AlignError("the narration doesn't match this text: sentences and audio disagree "
                             f"(median match {quality['median_match']}; real narrations score 0.85+)")
        report = {
            "version": VERSION, "audio_seconds": round(dur, 2), "processing_seconds": round(time.time() - t0, 1),
            **stats, "quality": quality, "pronunciations_generated": generated,
        }
        return to_vtt(cues, title), report
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    import argparse
    ap = argparse.ArgumentParser(description="Make sentence-level WebVTT read-along timings.")
    ap.add_argument("audio")
    ap.add_argument("text", help="Markdown (.md) or plain text (.txt) that the audio narrates")
    ap.add_argument("-o", "--output", help="where to write the .vtt (default: next to the audio)")
    ap.add_argument("--format", choices=["markdown", "text"], help="default: from the text file's extension")
    ap.add_argument("--pronunciations", help="file of 'word PHONES' lines")
    ap.add_argument("--keep-unread", action="store_true", help="don't drop headings/quotes that aren't narrated")
    ap.add_argument("--report", help="write a JSON report here")
    a = ap.parse_args()
    fmt = a.format or ("text" if a.text.lower().endswith(".txt") else "markdown")
    if a.pronunciations:
        with open(a.pronunciations, encoding="utf-8") as f:
            pron = f.read()
    else:
        pron = ""
    with open(a.text, encoding="utf-8") as f:
        text_in = f.read()
    vtt, rep = run(a.audio, text_in, fmt, pron, not a.keep_unread,
                   progress=lambda s, p=None: print(f"  {s}{'' if p is None else f' {p}%'}", flush=True))
    out = a.output or os.path.splitext(a.audio)[0] + ".vtt"
    with open(out, "w", encoding="utf-8") as f:
        f.write(vtt)
    if a.report:
        with open(a.report, "w", encoding="utf-8") as f:
            json.dump(rep, f, indent=2, ensure_ascii=False)
    print(f"wrote {out}: {rep['cues']} cues; {rep['words_matched_pct']}% words matched; "
          f"median match {rep['quality']['median_match']}; flagged {len(rep['quality']['flagged'])}")
