# Plan: "Voci" — what YouTube says about the game

Status: **implemented and in production since 2026-09-16.** This document was
the brief for the implementation and is kept as its design record. It records the decisions taken, the
measurements behind them, the data contract, the order of work and the
acceptance criteria. Read `ARCHITECTURE.md`, `DEV.md` and `SECURITY.md` first;
everything below follows their conventions.

## 1. Goal

A fifth view, **Voci** (`#voci`), showing the ten most-viewed YouTube videos
about the game and turning what people say into something actionable:

1. a card per video: thumbnail, title, channel, publish date, views, a
   structured summary of the praise and the complaints, and the handful of
   comments that actually talk about the game;
2. one table with two columns, *what players like* and *what they want
   improved*, aggregated across the ten videos;
3. a holistic verdict that weighs **when** things were said: a complaint from a
   month ago may already be fixed, a recent update may be causing new ones.

Computed once a day at **08:00 Europe/Rome**, cached, served read-only behind
the existing login.

## 2. Where it runs, and why that is not the VPS

**Measured 2026-09-16** with `youtube-transcript-api` against the ten real ids:

| Origin | Transcripts retrieved | Failure reason |
|---|---|---|
| The owner's workstation (residential IP) | **5 / 10** | the other five have captions disabled by the creator |
| The Oracle VPS (datacenter IP) | **0 / 10** | `RequestBlocked` on every request |

YouTube blocks datacenter ranges from the caption endpoints and Oracle Cloud is
one of them. Working around that with residential proxies would mean evading a
platform control and breaching the terms of service; on a repository the owner
shows in a security interview, that is not a trade worth making.

**So the job runs on the owner's workstation and publishes the result to the
VPS, which only serves it.** This is the single most important thing to
understand about this feature; everything else follows from it.

```
workstation, 08:00 Europe/Rome (systemd --user timer)
  bin/voices
    ├─ YouTube Data API v3: search → videos → commentThreads   (official, keyed)
    ├─ tools/transcript.py <id>  → JSON on stdout              (works from here)
    ├─ thumbnails            → data/media/yt/<id>.jpg
    ├─ per-video summary     → data/voices-cache/<id>.json      (LLM, only when missing)
    └─ synthesis             → data/voices.json                 (LLM, daily)
  bin/voices-publish
    └─ rsync over SSH → VPS: /var/lib/manor-ledger/{voices.json,media/yt/}

VPS (serves only)
  GET /api/voices        → voices.json (auth, ETag, 304)
  GET /media/yt/{id}.jpg → the thumbnail (auth, strict id regex, Cache-Control: private)
```

If the workstation is off, the VPS keeps serving the previous file and the page
states its date — the same honesty the dashboard already applies to a missed
refresh. `bin/voices-publish` is idempotent and safe to re-run.

A side benefit worth keeping: **no new secret ever reaches the server.** The
YouTube key and the LLM key stay on the workstation.

## 3. Decisions taken (do not reopen)

| Decision | Reason |
|---|---|
| **The job runs on the workstation; the VPS only serves.** | Transcripts are unobtainable from a datacenter IP; see §2. |
| **Transcripts are the primary source. Comments are a secondary, filtered source.** | Measured (§9): comments are mostly addressed to the creator ("love your videos", "you're my childhood YouTuber"). The creator's spoken reaction while playing is where opinions about the *game* are. |
| **Relevant comments are selected, kept and shown; the rest are discarded.** | Two or three genuine player quotes per card are far more convincing than a paraphrase. The model selects them and must quote verbatim. |
| **Use whatever transcripts exist; never fight a missing one.** | Five of ten have captions disabled at the source. A card with no transcript says so and is summarised from comments alone. |
| **Per-video cache; only the synthesis is regenerated daily.** | A video's summary does not change. Keeps LLM calls near zero on a normal day and survives an LLM outage (previous synthesis shown with its date). |
| **Thumbnails are downloaded and served from our own host.** | The CSP stays `img-src 'self' data:`; the browser never contacts YouTube. |
| **Keep the list to Roblox by exclusion, not inclusion.** | See §5. |
| **One LLM adapter, OpenAI-compatible, with a model chosen per task and switchable by configuration.** | See §6. Ollama exposes that format locally, so local, free-remote and paid models are one code path and the choice stays a config edit. |
| **PHP orchestrates; Python only for transcripts.** | Keeps the codebase coherent and the tests in PHPUnit with injected transports. |
| **Structured JSON in, structured JSON out, validated.** | Model output is untrusted: never rendered as HTML, never executed, never passed on unvalidated. |

## 4. Prerequisites

1. **YouTube Data API v3 key** — *done*. Created in the owner's existing Google
   Cloud project (that project's OAuth client is a different credential type and
   is not reusable). Stored at `data/youtube-api-key`, mode 600, git-ignored.
   Measured cost of one run ≈ **410 units** of the 10 000/day free quota: four
   `search.list` pages at 100 each, plus `videos.list` and ten
   `commentThreads.list` at 1 each.
2. **LLM** — *done*. Google AI Studio key at `data/gemini-api-key`, mode 600,
   git-ignored, verified working against 41 available models. `gemini-3.6-flash`
   is the chosen model for both calls; `qwen2.5:14b-instruct` on the local
   Ollama is the fallback and needs no key. See §6.
3. **SSH access from workstation to VPS** — already in place
   (address kept out of the repository in `deploy/deploy.local.env`; key `~/.ssh/id_ed25519`).

## 5. Keeping the list to Roblox

The game exists only on Roblox, but many creators never write the word: only a
third of the candidates mention it in title, description or tags. Requiring it
was measured to discard 14 of 69 videos **including the single most watched
one**, cutting available comments from 2 198 to 851.

The rule is therefore **exclusion, not inclusion**: keep every video whose title
carries the game name, and drop one only when its title, description or tags
name a different platform (`fortnite`, `minecraft`, `among us`, `geometry dash`,
`garry's mod`/`gmod`) *without* also naming Roblox. On the 2026-09-16 sample
that removed exactly one video — a Fortnite remake — and kept the other 82. The
pattern lives in one constant so it can be extended when a new impostor appears.

## 6. Choosing the model, per task

The workstation has an RTX 3070 (8 GB VRAM), 60 GB RAM, 32 cores, and Ollama
serving an **OpenAI-compatible API** at `http://localhost:11434/v1`, so a local
model and a remote one are the same code path.

The two calls are not equally hard:

| Call | Per day | Difficulty |
|---|---|---|
| **Per-video summary** — read a transcript in any language plus filtered comments, extract praise and complaints, emit fixed JSON in Italian | ≤ 10, and 0 on a normal day thanks to the cache | Extraction and translation under a schema |
| **Synthesis** — weigh ten summaries against their dates, decide what is fixed, persistent or new, write the verdict | Exactly 1 | Reasoning over time; the most visible text on the page |

### Measured on real transcripts (2026-09-16)

Same prompt, JSON response mode, temperature 0.2.

| Model | Input | Time | Verdict |
|---|---|---|---|
| `qwen2.5:7b-instruct` (local) | `eGxhf71MXyE`, English | 5 s | **Fails.** Valid JSON, worthless content: verbatim English fragments instead of concepts, `"I found a key already."` listed as a thing players like. Extraction without comprehension. |
| `qwen2.5:14b-instruct` (local) | same | 21 s | **Passes.** Italian, real concepts: *meccanica di chiavi per aprire porte*, *stamina meter*, *ridurre il ritmo lento delle interazioni*. |
| `qwen2.5:14b-instruct` (local) | `lHul7HACuLo`, **Portuguese** | 40 s | **Content passes, language fails.** Comprehension is good but it answered in **Spanish**. The Italian instruction holds on English input and drifts on Romance-language input. |
| **`gemini-3.6-flash`** (Google AI Studio, free tier at the time of the test) | `lHul7HACuLo`, **Portuguese** | 15 s | **Passes everything, by a wide margin.** Flawless Italian, and the points are specific enough to act on: *glitch di collisione che spingono il giocatore verso il soffitto negli armadi*, *tendenza dei mostri a stazionare davanti ai nascondigli*, *scarsa accessibilità per giocatori daltonici a causa dei codici colore*. That last one is a real accessibility finding neither local model reached. |

`gemma3:4b` was not tested: strictly smaller than a model that already failed.

Two API facts learned while testing, both worth coding against:

- **Model names drift.** `gemini-2.5-flash` returns HTTP 404 with *"no longer
  available to new users"* and names its successor in the message. Read the
  model id from configuration, never hardcode it, and log the API's suggestion
  when a 404 mentions one.
- **The service can be busy.** On the free tier used for this test,
  `gemini-flash-latest` returned HTTP 503
  *"experiencing high demand"* on the same run that `gemini-3.6-flash`
  succeeded. Retries with backoff are not optional, and the local fallback
  below earns its place.

### The configuration to build

`config/app.php` gains an `llm` block with two named profiles, `summary` and
`synthesis`, each carrying `driver` (`gemini` | `openai` | `anthropic`),
`baseUrl`, `model`, `keyFile` (nullable) and `timeout`, plus an optional
`fallback` profile name. All of them go through the same `LlmClient`.

| Profile | Primary | Fallback |
|---|---|---|
| `summary` (≤ 10 calls/day, 0 on a cached day) | `gemini-3.6-flash` | `qwen2.5:14b-instruct` on Ollama |
| `synthesis` (exactly 1 call/day) | `gemini-3.6-flash` | `qwen2.5:14b-instruct` on Ollama |

Gemini wins both on the measurements above, costs very little at this volume,
and is the only candidate that held the output language on non-English input.
**The key has since been moved to the paid tier**, which is what `SECURITY.md`
describes; the measurements above were taken on the free one. The key is
already in place at `data/gemini-api-key` (mode 600, git-ignored); it is a
newer-format key, 53 characters, not the classic `AIza…` shape, so **do not
validate keys by prefix**.

The local 14B model stays wired as the fallback and is genuinely useful: it
covers the 503s, it keeps the feature working offline, and it means a demo
never depends on someone else's service being up. When the fallback is used,
record it in `models` in the output so the page can say so.

Since Gemini is doing the work, `SECURITY.md` states plainly what leaves the
machine: public video titles, public comments and public captions, sent to
Google under the terms of the **paid** Gemini API. Read on 2026-09-16 at
<https://ai.google.dev/gemini-api/terms>, those terms say that for paid
services "Google doesn't use your prompts (including associated system
instructions, cached content, and files such as images, videos, or documents)
or responses to improve our products", whereas for the unpaid tier "Google uses
the content you submit to the Services and any generated responses to provide,
improve, and develop Google products and services and machine learning
technologies". Re-read them whenever the key or the plan changes. Nothing of the
owner's own data, and no Roblox figures, ever go near it.

### Quality gate (step 4 of the order of work)

Already run for `qwen2.5:7b-instruct` and `qwen2.5:14b-instruct`; results in
the table above. Re-run it whenever the model or the prompt changes, over the
**same three videos**, including the Portuguese one, checking four points:

1. valid JSON on all three attempts;
2. list items are judgements about the game, not quoted lines;
3. output is in Italian regardless of the input language;
4. nothing addressed to the creator leaks into the lists.

Record new results in the table above. Today 7B fails points 2 and 3; 14B
fails only point 3, and only on non-English input, which is what the language
validator exists to catch.

Whatever is chosen, `SECURITY.md` must state what leaves the machine: public
video metadata, public comments and public captions, never our own data, and
only for the calls that actually go to a remote provider.

## 7. Components

### Namespace `ManorLedger\Voices`

| Class | Responsibility |
|---|---|
| `YouTubeClient` | `search()`, `videos()`, `comments()`. REST over the existing curl transport pattern (`fn(array $requests): array`) so tests inject fixtures. **`order=relevance`, never `order=viewCount`** (§9). HTML-unescape every title. Two pages of each of two queries, filter titles on *locust* + *manor*, resolve statistics and full snippets with `videos.list`, apply the Roblox exclusion, sort by views locally, take ten. |
| `CommentFilter` | Deterministic pre-filter before any model call: drop comments under 40 characters, pure emoji or punctuation, and obvious creator-directed chatter (a small multilingual pattern list: *love your videos*, *first*, *who's watching*, *pin me*, *notification squad*, greetings to the channel). Keep at most 60 per video ordered by likes. Pure functions, fully unit-tested — this part must not need a model. |
| `TranscriptFetcher` | Runs `tools/transcript.py <id>` via `proc_open`, 60 s timeout, parses `{status, language, generated, text}`. Never throws: returns a status. |
| `ThumbnailStore` | Downloads `snippet.thumbnails.medium.url`, validates JPEG magic bytes, writes `data/media/yt/<id>.jpg` 0644, skips when present. |
| `LlmClient` | `complete(array $messages, array $options): array` against a named profile. OpenAI-compatible `POST {base}/chat/completions` plus an `anthropic` driver; JSON response format when supported, temperature 0.2, timeouts, 3 retries with backoff on 429/5xx, key read from file when the profile has one, key redacted from every exception message. |
| `VideoSummarizer` | Builds the per-video prompt (title, channel, date, transcript ≤ 12 000 chars, filtered comments ≤ 15 000 chars), calls the `summary` profile, validates the shape, caches to `data/voices-cache/<id>.json`. |
| `Synthesizer` | Takes the ten summaries **sorted oldest first**, calls the `synthesis` profile, validates, returns. |
| `VoicesBuilder` | Orchestrates, assembles `data/voices.json`; on LLM failure keeps the previous synthesis and marks it `stale`. |
| `Http\Controllers\VoicesController` | `GET /api/voices` (auth, `Response::file`, ETag) and `GET /media/yt/{id}.jpg` (auth, id must match `^[A-Za-z0-9_-]{11}$`, `Cache-Control: private, max-age=86400` so Cloudflare never caches an authenticated response). |

### `tools/transcript.py`

Python 3.12, one dependency: `youtube-transcript-api` (1.2.4 at the time of
writing). Virtualenv at `data/venv`, created by a `make` target. Prints one JSON
object and exits 0 even on failure, so PHP only has to parse. Language
preference: Italian, then English, then the first available track. Truncates to
20 000 characters.

Statuses, all observed in the field test: `ok`, `missing` (`TranscriptsDisabled`,
five of ten videos), `blocked` (`RequestBlocked`, what the VPS returns and the
workstation should never see), `error`.

### `bin/voices-publish`

`rsync -az` `data/voices.json` and `data/media/yt/` into a staging directory the
server creates for itself under `/var/tmp` (mode 700, never world-writable
`/tmp`), then
one `ssh sudo` step installing them as `manor-fetch:manor`, `0640` for the JSON
and `0644` for the images, under `/var/lib/manor-ledger/`. Same shape and guard
rails as `deploy/install.sh`. Supports `--dry-run`.

## 8. Data contract: `data/voices.json`

```json
{
  "generatedAt": "2026-09-17T06:03:11Z",
  "host": "workstation",
  "queries": ["The Locust's Manor", "The Locust's Manor Roblox"],
  "models": { "summary": "gemini-3.6-flash", "synthesis": "gemini-3.6-flash",
               "fellBackTo": null },
  "stats": { "candidates": 83, "excludedNonRoblox": 1, "withTranscript": 5 },
  "videos": [
    {
      "id": "O8eWFVZxgcI", "title": "…", "channel": "…", "channelId": "…",
      "publishedAt": "2026-09-02", "views": 90216, "likes": 1399, "commentCount": 135,
      "url": "https://www.youtube.com/watch?v=O8eWFVZxgcI",
      "thumbnail": "/media/yt/O8eWFVZxgcI.jpg",
      "transcript": { "status": "ok" | "missing" | "blocked" | "error",
                      "language": "en", "generated": true, "chars": 19930 },
      "comments": { "fetched": 60, "kept": 22 },
      "summary": {
        "status": "ok" | "failed",
        "generatedAt": "…", "model": "…",
        "basedOn": ["transcript", "comments"],
        "tone": "positive" | "mixed" | "negative",
        "likes":        ["Atmosfera della villa", "…"],
        "improvements": ["Lag nella lobby", "…"],
        "oneLine": "Una frase in italiano che riassume il video.",
        "quotes": [ { "text": "verbatim, mai parafrasato", "likes": 20,
                      "topic": "a cosa si riferisce" } ]
      }
    }
  ],
  "synthesis": {
    "status": "ok" | "stale",
    "generatedAt": "…", "model": "…",
    "videosConsidered": ["id", "…"],
    "likes":        [ { "point": "…", "videos": ["id"], "firstSeen": "2026-08-12", "lastSeen": "2026-09-14" } ],
    "improvements": [ { "point": "…", "videos": ["id"], "firstSeen": "…", "lastSeen": "…",
                        "recency": "recent" | "persistent" | "old" } ],
    "verdict": "Tre-cinque frasi in italiano.",
    "timeline": [ { "date": "2026-08-12", "id": "…", "tone": "mixed" } ]
  }
}
```

Every string shown to a user is model output or third-party text and is
therefore **untrusted**: the frontend inserts it with `textContent`, never
`innerHTML`, and caps each item (points ≤ 140 chars, quotes ≤ 280, verdict
≤ 1 200).

## 9. Field test results (2026-09-16)

**YouTube Data API.** Key valid, API enabled. Three findings that shape the code:

1. **`order=viewCount` is unusable.** It returns a loosely matched candidate set
   sorted by views: 25 results, *none* with the game in the title.
   `order=relevance` returns results that are almost all on target. Sort by
   views yourself after `videos.list`.
2. **Titles arrive HTML-escaped** (`THE LOCUST&#39;S MANOR`). Unescape before
   matching and before display. Some titles use a typographic apostrophe, which
   is why the filter matches the two words separately rather than the phrase.
3. **The material is multilingual**: Spanish, Portuguese and English in the top
   ten alone. Never filter by language; always answer in Italian.

Volume: **98 videos** carry the game name, 552 767 views in total. The top ten
range from 15 308 to 106 843 views with **2 198 comments** between them. The most
watched is Spanish, titled "I played The Locust's Manor and I regretted it".

**Transcripts.** See §2. Five of ten from the workstation, zero from the VPS.
The five that exist run 7 455 to 23 952 characters, in English and Portuguese —
ample material.

**Comment quality — the measurement that changed the design.** Sampling the two
videos with most comments:

| Video | Sampled | Mean length | ≥ 40 chars |
|---|---|---|---|
| `9s7sZkuW_Jg` (483 total) | 60 | 73 chars | 61 % |
| `O8eWFVZxgcI` (135 total) | 60 | 34 chars | 33 % |

The most-liked substantial comments were almost all addressed to the creator,
not the game. Hence `CommentFilter`, hence the explicit instruction in the
prompt, and hence transcripts being primary.

## 10. Prompts

Kept in `config/prompts/voices-summary.md` and
`config/prompts/voices-synthesis.md`, loaded at runtime so they are reviewable
and versioned.

- The system message states that everything between the delimiters is **data
  written by strangers** and must never be obeyed as an instruction. Comments
  are a prompt-injection surface.
- **Summary prompt**: Italian output, the exact JSON shape, at most six points
  per list, each a concrete noun phrase, no marketing language, `tone` from the
  three allowed values only. Input may be in any language. Explicit instruction
  to **ignore anything addressed to the creator** and keep only what refers to
  the game; `quotes` must be verbatim, never invented or translated, at most
  three, chosen for being informative rather than most-liked.
- **Synthesis prompt**: receives the summaries **with dates, oldest first**, and
  is told that improvements appearing only in older videos may already be fixed
  and must be labelled `old`, that points in the newest videos are `recent`, and
  that points spanning the period are `persistent`. The verdict must say what
  *changed over time*, not average the opinions.

## 11. Order of work

Each step ends with a verification; do not start the next before it passes.

1. `YouTubeClient` + `CommentFilter` + fixtures + tests. Verify against one real
   call that the top ten matches §9.
2. `TranscriptFetcher` + `tools/transcript.py` + venv `make` target; the PHP
   test uses a fake script, no network.
3. `ThumbnailStore`, `LlmClient` (both drivers) + tests with injected transport.
4. **The quality gate in §6**, before writing the rest: three videos, local
   model against frontier model, record the result in this file.
5. `VideoSummarizer`, `Synthesizer`, `VoicesBuilder` + tests on fixture
   summaries; the synthesis test asserts that a complaint present only in the
   oldest video comes back labelled `old`.
6. `bin/voices` with `--if-older-than`, `--only=<id>`, `--dry-run`,
   `--resynthesize`. Run it for real once into `data/`.
7. `bin/voices-publish` + `make publish-voices`; run it for real.
8. Routes and controller + `KernelTest` additions (security headers on the new
   routes, 401 without session, 404 on a malformed media id).
9. Frontend `views/voci.js`: cards with thumbnail and quotes, the two-column
   table, the verdict, a timeline strip. Glossary entries. Nav tab. Synthetic
   fixture `tests/fixtures/voices.sample.json`.
10. Scheduling on the workstation: `systemd --user` timer at 08:00 Europe/Rome
    running `bin/voices && bin/voices-publish`, with `loginctl enable-linger` so
    it fires without an active session.
11. Docs: `README.md`, `ARCHITECTURE.md`, `SECURITY.md`, `DEPLOY.md`. Be explicit
    that this job does not run on the server, and why.

## 12. Security and privacy

- Keys live on the workstation in `data/`, mode 600, git-ignored. **No new
  secret is placed on the VPS.**
- The media route validates the id against a strict regex and serves only from
  `data/media/yt/`; no path from the request reaches the filesystem.
- CSP unchanged. Outbound links carry `rel="noopener noreferrer"`.
- Model output and quoted comments are text, length-capped, inserted with
  `textContent`.
- Untrusted text is delimited in prompts and declared as data. Assume a hostile
  comment could still steer a summary: nothing downstream trusts the summary
  beyond displaying it.
- `SECURITY.md` gains a paragraph on the model: what leaves the machine and what
  does not, and that the feature degrades rather than failing open.
- Quoting public comments verbatim reproduces third-party content. Keep quotes
  short, attribute them to the video, never show commenter names.

## 13. Failure modes

| Failure | Behaviour |
|---|---|
| Captions disabled (five of ten today) | `transcript.status: missing`; summary from filtered comments; card shows a "trascrizione non disponibile" badge. |
| Transcript request blocked | Same as missing; should not happen from the workstation. |
| Local model unavailable | Fall back to the synthesis profile for summaries too, and say so in the log; if that also fails, keep yesterday's file. |
| Remote model down or over quota | Cached per-video summaries reused; synthesis kept from the previous run with `status: stale` and its date shown. |
| YouTube quota exceeded | Job exits non-zero, previous `voices.json` untouched, page shows the older data with its date. |
| Workstation off at 08:00 | Nothing published; the VPS serves the previous file and the page states its date. |
| Comments disabled on a video | `comments.fetched: 0`; summary from transcript alone, or `summary.status: failed` with an honest card. |
| A video leaves the top ten | Cache file and thumbnail kept 30 days, then pruned. |

## 14. Acceptance criteria

- `make check` green; no test touches the network.
- `bin/voices --dry-run` lists the planned calls without making them.
- After one real run: ten cards, each with a local thumbnail; at least four
  carry a transcript-based summary; at least one shows a verbatim player quote;
  the synthesis labels every improvement with a `recency`.
- A second run the same day makes **zero** LLM calls (log says "summary
  cached"); `--resynthesize` makes exactly one.
- `GET /api/voices` and `GET /media/yt/<id>.jpg` return 401 without a session.
- CI builds the view from `tests/fixtures/voices.sample.json`.
- The Voci tab reads well at 390 px width.

## 15. Notes for the implementer (learned the hard way on this host)

- **Budget.** Delegate the bulk to one agent with a closed brief and tell it to
  converge; no screenshot loops. Verify with curl, then look once in a browser.
- **Testing login with curl** needs a `Referer` (or `Origin`) header, and nginx
  limits `/login` to 10 requests a minute: wait for the limiter with an `until`
  loop rather than hammering it.
- **Asset versioning is path-based** (`/assets/v<build>/…`) and covers every file
  under `public/assets`, so a deployed frontend change is visible immediately.
- **Two service users on the VPS.** `manor-fetch` owns the data, `manor` serves
  it. Anything the web pool must read is `0640` with group `manor` (pass the mode
  to `JsonStore`); anything it must write lives under `/var/lib/manor-ledger/web`.
  Lock files keep their old owner when ownership changes: `chown` them too.
- **`bin/user --password-stdin` needs a trailing newline**; usernames are 3–32
  characters.
- **rsync excludes are anchored** (`/vendor`, not `vendor`), or
  `public/assets/vendor` never reaches the server.
- **Never edit a systemd unit while its service is running**: systemd drops the
  remaining `ExecStart` lines ("current command vanished").
- **Cloudflare caches aggressively**; anything under `/media/` must be
  `Cache-Control: private`.
