# Thinking Darkroom

A photographer-first photo workspace where a WebMCP AI agent can inspect,
analyze, propose — and the photographer alone decides. Built as a hackathon
project for the WebMCP Challenge on Laravel 13 + Inertia v2 + React 18.

**Live demo:** https://thinking-darkroom.vercel.app

**Demo credentials:**

- Photographer (human decision-maker): `photographer@webmcp.test` / `password`
- Agent (WebMCP agent account): `agent@webmcp.test` / `password`

## What it demonstrates

### Context-Aware Culling

Thinking Darkroom combines three sources of information:

1. **Technical image observations** — derived directly from the demo JPEG
   pixels using deterministic image analysis (sharpness, exposure, motion
   blur, highlight clipping, and perceptual similarity). No model inference,
   no network calls: the same pixels always produce the same observation.

2. **Creative annotations** — in the bundled hackathon demo dataset,
   subjective creative attributes (emotional strength, candidness, posing,
   storytelling, mood) are supplied by documented demo sidecar annotations
   (`database/demo/culling-dataset/*.obs.json`).

3. **Photographer-approved Creative Brief** — Thinking Darkroom uses the
   adopted Creative Brief to decide how technical and creative tradeoffs
   should be weighted for the current project.

The same photo observation can therefore receive different recommendations
under different photographer-approved creative directions:

- **Emotion-first direction:** a slightly-soft but emotionally strong frame
  → KEEP.
- **Technical-precision direction:** the same observation → REJECT CANDIDATE.

This demonstrates context-aware decision-making rather than generic image
quality ranking.

**Claim boundary (read this before quoting the demo):**

- Technical characteristics are **computed from image pixels**.
- Creative attributes in the bundled hackathon demo dataset are **supplied
  through documented sidecar annotations** (`technical_provenance =
  pixel_analysis`, `creative_provenance = demo_sidecar_annotation`).
- Thinking Darkroom combines both with the photographer-approved Creative
  Brief to produce context-aware culling recommendations.
- The system does **not** visually detect emotion from pixels, does **not**
  include a trained photography model, and does **not** learn photographer
  taste through ML.

### Human authority model

Agents may analyze and propose. Photographers retain final authority over
selection and override decisions.

Authority levels used throughout the codebase (`app/Domain/Domain.php`):

- **READ** — inspect existing state.
- **ANALYZE** — derive and persist non-final observations
  (`photo_observations`); never changes selections. This is why
  `analyze_project_photos` is not read-only: it persists observation rows,
  but observations are evidence, not decisions.
- **PROPOSE** — recommend actions (proposal rows only) for photographer
  review.
- **EXECUTE** — execute an already human-approved, eligible action
  (`apply_approved_plan`, registered only while an approved proposal is
  pending).
- **HUMAN** — adoption, culling override, and final creative decisions.
  These exist only as photographer UI endpoints and are deliberately absent
  from the WebMCP tool catalog; agent accounts are hard-blocked at the HTTP
  layer as well.

### Agent tool surface (WebMCP)

19 normal base tools are registered per project workspace:

- Sprint 1 — 8 static (5 READ, 3 PROPOSE): workspace/proposal/qa inspection
  and proposal tools.
- Sprint 2 — 8 static (4 READ, 4 PROPOSE): Creative Room concepts and brief
  proposal tools.
- Sprint 3 — 3 static (2 READ, 1 ANALYZE): `get_photo_analysis` (READ),
  `get_culling_context` (READ), `analyze_project_photos` (ANALYZE — persists
  non-final `photo_observations` only).
- 1 dynamic: `apply_approved_plan` (EXECUTE), registered only while an
  approved, unexecuted proposal exists and unregistered the moment it is
  executed or rejected.

There is deliberately **no** WebMCP tool for finalizing culls, approving
proposals, overriding decisions, or deleting originals — those are
photographer-only actions.

The retouch vocabulary (`propose_retouch_plan` operations) mirrors
`Domain::RETOUCH_ADJUSTMENTS` exactly: exposure, contrast, saturation,
warmth, highlight_recovery, shadow_lift — each a normalized −1.0…+1.0
offset interpreted by the deterministic demo renderer.

## Demo dataset

`database/demo/culling-dataset/` contains 12 synthetic JPEGs (960×640)
generated specifically for this project, with a sidecar annotation file per
image and a provenance README. No third-party photography is included. See
[dataset provenance README](database/demo/culling-dataset/README.md).

## Architecture

- **Laravel 13** API + Inertia v2/React 18 workspace UI.
- **Context-aware culling:** `ContextAwareCullingService` combines
  `PhotoObservation`s (from `DemoPhotoAnalysisProvider` pixel statistics)
  with `CreativeRoomService::structuredIntentFor()` to produce
  recommendation + confidence + technical/creative rationale + tradeoff +
  `influenced_by` traceability.
- **Persistence:** `photo_observations` table; photographer photo-level
  decisions persist to `photographer_decisions.photo_id`.
- **Workspace culling UI:** per-photo recommendation badges with technical
  quality, creative fit, WHY rationale, `influenced_by`, confidence,
  provenance labels, and similarity grouping — with keyboard-accessible
  photographer override.
- **Durable media on serverless:** `app/Services/Media/MediaStore.php`
  writes originals and retouched derivatives to Vercel Blob (public URLs
  stored in DB path columns) when `BLOB_READ_WRITE_TOKEN` is present, with
  a local public-disk fallback for development and tests. Analysis, hashing,
  and rendering read bytes through the same service, so durable photos work
  end to end.

## Deployment (Vercel + Neon)

Production runs Laravel on Vercel serverless with:

- **Neon Postgres** via the Vercel Neon integration — `DATABASE_URL` and
  `DB_CONNECTION=pgsql` are injected as Vercel env vars. The schema is
  initialized once with `scripts/init-durable-db.sh` (migrate + seed against
  the external DB).
- **Vercel Blob** store for all mutable media (originals + derivatives) —
  created with `vercel blob` CLI; `BLOB_READ_WRITE_TOKEN` is a Vercel env
  var. Without the token the app transparently falls back to local disk.
- **Encrypted cookie sessions** so login survives lambda recycling.
- `api/index.php` durable mode skips all sqlite/`/tmp` unpacking; seed demo
  assets are immutable and served from the committed `seed-storage/`
  directory.

Upload contract: up to 10 images per batch, each under 4.3MB — this keeps a
single-file request under Vercel's 4.5MB body limit.

## Demo dataset licensing

The bundled demo JPEGs and sidecar annotations are original works created
for this repository (see `database/demo/culling-dataset/README.md`) and may
be redistributed with it (CC0 1.0).

## Development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
composer run dev
```

Run the test suites:

```bash
php artisan test        # backend feature + unit suite
npx vitest run          # frontend (77 tests)
npx tsc -p tsconfig.json --noEmit
npm run build
```

## Judge walkthrough

1. Log in as `photographer@webmcp.test` (or the agent account to see the
   restricted surface — agent accounts get 403 on every human-only
   endpoint).
2. Upload photos (≤10 files, ≤4.3MB each), run culling analysis, override a
   cull, adopt a Creative Brief.
3. Let the agent propose a retouch plan; drag an adjustment value yourself;
   approve. `apply_approved_plan` appears in the WebMCP tool list, executes,
   and vanishes. The executed derivative reflects YOUR values.
4. Refresh — everything (photos, decisions, derivatives, memory) persists.
