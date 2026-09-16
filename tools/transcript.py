#!/usr/bin/env python3
"""Print the captions of one YouTube video as a single JSON object.

Contract with the PHP side (ManorLedger\\Voices\\TranscriptFetcher): exactly one
JSON object on stdout, exit code 0 even on failure, so PHP only ever has to
parse. Statuses, all observed in the field test of 2026-09-16:

    ok       captions retrieved
    missing  the creator disabled them (five of the ten videos)
    blocked  YouTube refused the request; this is what a datacenter IP gets,
             and the reason the job runs on the workstation and not on the VPS
    error    anything else, including a missing dependency

Usage: transcript.py <video-id> | --check
"""
import json
import re
import sys

MAX_CHARS = 20000
PREFERRED = ("it", "en")
MISSING = {"TranscriptsDisabled", "NoTranscriptFound", "VideoUnavailable",
           "TranslationLanguageNotAvailable", "NotTranslatable", "InvalidVideoId"}
BLOCKED = {"RequestBlocked", "IpBlocked", "YouTubeRequestFailed", "AgeRestricted",
           "VideoUnplayable", "TooManyRequests"}


def emit(**payload):
    sys.stdout.write(json.dumps(payload, ensure_ascii=False))
    sys.stdout.write("\n")
    sys.exit(0)


def pick(transcripts):
    """Italian, then English, then whatever exists, manual before generated."""
    for language in PREFERRED:
        try:
            return transcripts.find_transcript([language])
        except Exception:
            pass
    available = list(transcripts)
    if not available:
        return None
    available.sort(key=lambda t: bool(getattr(t, "is_generated", False)))
    return available[0]


def text_of(fetched):
    parts = []
    for snippet in fetched:
        value = getattr(snippet, "text", None)
        if value is None and isinstance(snippet, dict):
            value = snippet.get("text", "")
        parts.append((value or "").strip())
    return re.sub(r"\s+", " ", " ".join(p for p in parts if p)).strip()


def main():
    check = len(sys.argv) == 2 and sys.argv[1] == "--check"
    if not check and (len(sys.argv) != 2 or not re.fullmatch(r"[A-Za-z0-9_-]{11}", sys.argv[1])):
        emit(status="error", error="usage: transcript.py <video-id> | --check")
    try:
        from youtube_transcript_api import YouTubeTranscriptApi
    except ImportError:
        emit(status="error", error="youtube-transcript-api is not installed in this interpreter (make voices-venv)")
    if check:
        emit(status="ok", check=True)
    video_id = sys.argv[1]

    try:
        api = YouTubeTranscriptApi()
        # 1.x is instance-based; keep working on the 0.6 static API as well.
        lister = getattr(api, "list", None) or getattr(YouTubeTranscriptApi, "list_transcripts")
        transcript = pick(lister(video_id))
        if transcript is None:
            emit(status="missing", error="no transcript track")
        text = text_of(transcript.fetch())
    except Exception as exc:  # the taxonomy matters, the stack trace does not
        name = type(exc).__name__
        status = "missing" if name in MISSING else "blocked" if name in BLOCKED else "error"
        emit(status=status, error="%s: %s" % (name, str(exc).splitlines()[0][:200]))

    if not text:
        emit(status="missing", error="empty transcript")
    emit(
        status="ok",
        language=getattr(transcript, "language_code", None),
        generated=bool(getattr(transcript, "is_generated", False)),
        chars=len(text[:MAX_CHARS]),
        text=text[:MAX_CHARS],
    )


if __name__ == "__main__":
    main()
