"""
Background worker: takes queued jobs from the storage folder, one at a time,
and runs the aligner on them. Run it under systemd (see README).

    python worker.py            # run forever
    python worker.py --once     # process whatever is queued, then exit

Each job is a folder storage/jobs/<id>/ containing:
    audio.<ext>    uploaded narration
    text.txt       uploaded text
    options.json   format, pronunciations, drop_unread, name
    status.json    written by the API, then by this worker
    job.lock       flock()ed while the job is being processed or deleted
and, when done:
    result.vtt
"""
import configparser
import fcntl
import json
import os
import shutil
import signal
import sys
import time
import traceback

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import aligner  # noqa: E402

CONFIG = os.environ.get("VTT_ALIGNER_CONFIG", os.path.join(os.path.dirname(HERE), "config.ini"))


def load_config():
    cp = configparser.ConfigParser(inline_comment_prefixes=(";", "#"))
    if not cp.read(CONFIG):
        sys.exit(f"config not found: {CONFIG}")
    s = cp["service"]
    w = cp["worker"] if cp.has_section("worker") else {}
    return {
        "storage": s.get("storage_dir"),
        "max_audio_minutes": s.getint("max_audio_minutes", 240),
        "retention_hours": s.getfloat("retention_hours", 72),
        "keep_inputs": s.getboolean("keep_inputs", False),
        "cores": int(w.get("cores", 0) or 0),
        "max_job_minutes": int(w.get("max_job_minutes", 60) or 60),
        "poll_seconds": float(w.get("poll_seconds", 2) or 2),
    }


def now():
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def write_json(path, data):
    tmp = f"{path}.tmp{os.getpid()}"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=1)
    os.replace(tmp, path)


def read_json(path, default=None):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


class Stop(Exception):
    pass


class TimedOut(Exception):
    pass


STOPPING = False


def on_term(signum, frame):
    global STOPPING
    STOPPING = True
    raise Stop()


def on_alarm(signum, frame):
    raise TimedOut()


def heartbeat(cfg, state):
    write_json(os.path.join(cfg["storage"], "worker.json"),
               {"pid": os.getpid(), "seen": time.time(), "state": state, "version": aligner.VERSION})


def queued_jobs(jobs_dir):
    out = []
    for name in os.listdir(jobs_dir):
        st = read_json(os.path.join(jobs_dir, name, "status.json"))
        if st and st.get("status") == "queued":
            out.append((st.get("created_at", ""), name))
    return [n for _, n in sorted(out)]


def recover_stale(jobs_dir):
    """A job left 'running' whose lock nobody holds was interrupted: queue it again."""
    for name in os.listdir(jobs_dir):
        d = os.path.join(jobs_dir, name)
        st = read_json(os.path.join(d, "status.json"))
        if not st or st.get("status") != "running":
            continue
        try:
            with open(os.path.join(d, "job.lock"), "a") as lk:
                fcntl.flock(lk, fcntl.LOCK_EX | fcntl.LOCK_NB)
                st.update(status="queued", progress={"stage": "queued (restarted)"}, started_at=None)
                write_json(os.path.join(d, "status.json"), st)
        except OSError:
            pass  # someone holds it: genuinely running


def housekeeping(cfg, jobs_dir):
    cutoff = time.time() - cfg["retention_hours"] * 3600
    for name in os.listdir(jobs_dir):
        d = os.path.join(jobs_dir, name)
        try:
            if name.endswith(".tmp") or ".del-" in name:
                if os.path.getmtime(d) < time.time() - 3600:
                    shutil.rmtree(d, ignore_errors=True)
                continue
            st = read_json(os.path.join(d, "status.json"), {})
            if st.get("status") in ("done", "failed") and os.path.getmtime(os.path.join(d, "status.json")) < cutoff:
                with open(os.path.join(d, "job.lock"), "a") as lk:
                    fcntl.flock(lk, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    shutil.rmtree(d, ignore_errors=True)
        except OSError:
            pass


def process(cfg, d):
    status_path = os.path.join(d, "status.json")
    st = read_json(status_path)
    if not st or st.get("status") != "queued":
        return
    opts = read_json(os.path.join(d, "options.json"), {})
    audio = next((os.path.join(d, f) for f in os.listdir(d) if f.startswith("audio.")), None)
    st.update(status="running", started_at=now(), progress={"stage": "starting"}, error=None)
    write_json(status_path, st)
    last = [0.0]

    def progress(stage, pct=None):
        if time.time() - last[0] < 1 and stage == st["progress"].get("stage"):
            return
        last[0] = time.time()
        st["progress"] = {"stage": stage, **({"percent": pct} if pct is not None else {})}
        write_json(status_path, st)
        heartbeat(cfg, f"working on {os.path.basename(d)}")

    signal.signal(signal.SIGALRM, on_alarm)
    signal.alarm(cfg["max_job_minutes"] * 60)
    try:
        if not audio:
            raise aligner.AlignError("no audio file in job")
        with open(os.path.join(d, "text.txt"), encoding="utf-8") as f:
            text = f.read()
        vtt, report = aligner.run(
            audio, text, fmt=opts.get("format", "markdown"), pronunciations=opts.get("pronunciations", ""),
            drop_unread=opts.get("drop_unread", True), cores=cfg["cores"],
            max_minutes=cfg["max_audio_minutes"], progress=progress, title=opts.get("name") or None)
        with open(os.path.join(d, "result.vtt.tmp"), "w", encoding="utf-8") as f:
            f.write(vtt)
        os.replace(os.path.join(d, "result.vtt.tmp"), os.path.join(d, "result.vtt"))
        st.update(status="done", finished_at=now(), progress={"stage": "done", "percent": 100}, report=report)
    except Stop:
        st.update(status="queued", started_at=None, progress={"stage": "queued (worker restarted)"})
        write_json(status_path, st)
        raise
    except TimedOut:
        st.update(status="failed", finished_at=now(), error=f"took longer than {cfg['max_job_minutes']} minutes")
    except aligner.AlignError as e:
        st.update(status="failed", finished_at=now(), error=str(e))
    except Exception:
        traceback.print_exc()
        st.update(status="failed", finished_at=now(), error="internal error while aligning (see worker log)")
    finally:
        signal.alarm(0)
    if not cfg["keep_inputs"] and audio and st["status"] in ("done", "failed"):
        try:
            os.remove(audio)
        except OSError:
            pass
    write_json(status_path, st)
    print(f"{now()} job {os.path.basename(d)}: {st['status']}"
          + (f" ({st['error']})" if st.get("error") else f" ({st['report']['cues']} cues, {st['report']['processing_seconds']}s)"),
          flush=True)


def main():
    os.umask(0o007)  # files shared with the web server's group
    cfg = load_config()
    jobs_dir = os.path.join(cfg["storage"], "jobs")
    os.makedirs(jobs_dir, exist_ok=True)
    signal.signal(signal.SIGTERM, on_term)
    once = "--once" in sys.argv
    print(f"{now()} vtt-aligner worker {aligner.VERSION} started; storage {cfg['storage']}", flush=True)
    recover_stale(jobs_dir)
    last_housekeeping = 0.0
    try:
        while not STOPPING:
            heartbeat(cfg, "idle")
            if time.time() - last_housekeeping > 600:
                housekeeping(cfg, jobs_dir)
                last_housekeeping = time.time()
            did = False
            for name in queued_jobs(jobs_dir):
                d = os.path.join(jobs_dir, name)
                try:
                    lk = open(os.path.join(d, "job.lock"), "a")
                except OSError:
                    continue  # deleted meanwhile
                with lk:
                    try:
                        fcntl.flock(lk, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except OSError:
                        continue  # being deleted or handled elsewhere
                    if not os.path.isdir(d):
                        continue
                    heartbeat(cfg, f"working on {name}")
                    process(cfg, d)
                    did = True
                break  # re-scan after each job so newer state is seen
            if once and not did:
                break
            if not did:
                time.sleep(cfg["poll_seconds"])
    except Stop:
        pass
    finally:
        heartbeat(cfg, "stopped")
        print(f"{now()} worker stopped", flush=True)


if __name__ == "__main__":
    main()
