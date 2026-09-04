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

1. **Technical image observations** — derived directly from the JPEG pixels.
   Two interchangeable providers sit behind one interface:

   - **Deterministic pixel analysis** (default): sharpness, exposure, motion
     blur, highlight clipping, and perceptual similarity computed from pixels
     alone. No model inference, no network calls — the same pixels always
     produce the same observation.
   - **VLM vision analysis** (optional): when a vision-model API key is
     configured (deployment env or the photographer's own BYO key, see
     below), an OpenAI-compatible vision model describes each frame; results
     pass strict server-side enum coercion, carry honest
     `external_vision_model` provenance, and any provider failure falls back
     to deterministic analysis without blocking the run. Uploads
     auto-analyze within a serverless-friendly batch budget.

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

- Deterministic technical characteristics are **computed from image pixels**.
- VLM observations, when enabled, come from an external vision model and are
  labeled as such in provenance; they never silently replace pixel math.
- Creative attributes in the bundled hackathon demo dataset are **supplied
  through documented sidecar annotations** (`technical_provenance =
  pixel_analysis`, `creative_provenance = demo_sidecar_annotation`).
- Thinking Darkroom combines both with the photographer-approved Creative
  Brief to produce context-aware culling recommendations.
- The system does **not** visually detect emotion from pixels, does **not**
  include a trained photography model, and does **not** learn photographer
  taste through ML.

### Grounded agent reasoning (BYO-key)

Agent conversation turns are answered by `AgentLlmService`, which reasons
over **persisted evidence only** — photo observations with per-assessment
confidence, QA findings, proposal state, similarity groups, and the adopted
creative concept. Hard system rules keep replies evidence-only, 180 words,
with exact filenames and honest provenance; every LLM turn is recorded in
the audit ledger as an ANALYZE tool call. If no key is configured or the
provider fails, a deterministic composer answers instead — an agent turn
never blocks on the model.

Keys come from two places, with the photographer's own key taking
precedence:

- **Per-photographer BYO-key settings** (`Settings → AI`):
  `PATCH /profile/ai-settings` accepts an OpenRouter or NVIDIA NIM preset,
  a write-only API key (encrypted at rest with Laravel Crypt, never echoed
  back — the UI exposes only a `has_key` boolean), and optional model /
  base-URL overrides. The trigger message's human author supplies the
  settings; agent accounts cannot configure or use keys.
- **Deployment env** (`AGENT_LLM_*`, `VLM_*` in `.env.example`) as fallback
  when the photographer has not brought a key.

The audit ledger records `settings_source`
(`photographer_bring_your_own` | `deployment_env`) for every LLM-backed
turn, so a judge can always tell which key produced which reasoning.

### Human authority model

Agents may analyze and propose. Photographers retain final authority over
selection and override decisions.

Authority levels used throughout the codebase (`app/Domain/Domain.php`):

- **READ** — inspect existing state.
- **ANALYZE** — derive and persist non-final evidence: photo observations
  (`photo_observations`) and QA findings (`qa_findings`). Never changes
  selections — these rows are evidence, not decisions.
- **PROPOSE** — create non-final agent-authored collaboration output: a
  proposal row, a Creative Room concept/brief, or an agent conversation
  reply. Never approves a proposal, executes a plan, or changes creative
  state.
- **EXECUTE** — execute an already human-approved, eligible action
  (`apply_approved_plan`, registered only while an approved proposal is
  pending).
- **HUMAN** — adoption, culling override, and final creative decisions.
  These exist only as photographer UI endpoints and are deliberately absent
  from the WebMCP tool catalog; agent accounts are hard-blocked at the HTTP
  layer as well.

### Agent tool surface (WebMCP)

21 static tools are registered per project workspace (12 READ, 7 PROPOSE,
2 ANALYZE), plus 1 dynamic EXECUTE tool:

- **Workspace & conversation** — context, photo listing/inspection,
  decision history, and the durable project conversation. Conversation
  bodies are explicitly untrusted project content; an authenticated project
  agent can reply, but a reply cannot approve or execute creative work.
- **Creative Room** — concept read tools plus `propose_concepts`,
  `propose_concept_revision`, `propose_concept_merge`, and
  `propose_creative_brief` for non-final creative direction.
- **Culling analysis** — `get_culling_context` (READ),
  `analyze_project_photos` (ANALYZE — persists non-final
  `photo_observations` only), and `propose_cull` (PROPOSE).
- **QA** — `run_consistency_review` (ANALYZE, not READ: it persists
  `qa_findings` rows judged against the adopted brief).
- **1 dynamic:** `apply_approved_plan` (EXECUTE), registered only while an
  approved, unexecuted proposal exists and unregistered the moment it is
  executed or rejected.

There is deliberately **no** WebMCP tool for finalizing culls, approving
proposals, overriding decisions, or deleting originals — those are
photographer-only actions.

The retouch vocabulary (`propose_retouch_plan` operations) mirrors
`Domain::RETOUCH_ADJUSTMENTS` exactly: exposure, contrast, saturation,
warmth, highlight_recovery, shadow_lift — each a normalized −1.0…+1.0
offset.

### Pro retouch renderer

Approving a retouch plan no longer runs a demo approximation:
`ProRetouchRenderer` applies a production-grade 6-key LUT — EV, rational
S-curve contrast, Rec.709 saturation, multiplicative temperature, highlight
knee recovery, and shadow lift — deterministically, to the same bytes the
photographer uploaded. LUT invariants are smoke-tested and real-pixel E2E
verified.

### Collaboration surface

- **Reachable agent conversation:** a fixed `Chat with agent` launcher opens
  a responsive workspace drawer with durable history, before-cursor history
  paging (50 per page), Escape-to-close, and a live character counter.
  Human messages use the photographer UI endpoint; external Darkroom Agents
  read and reply through dedicated WebMCP tools. Conversation text is
  untrusted context with no authority to approve or execute work.
- **Presence:** the agent presence strip is clickable and opens the chat,
  with honest copy about what presence means.
- **Workspace culling UI:** per-photo recommendation badges with technical
  quality, creative fit, WHY rationale, `influenced_by`, confidence,
  provenance labels, and similarity grouping — with keyboard-accessible
  photographer override.
- **Decision ledger:** agent proposals, photographer adoptions/overrides,
  and executed plans are visible as decision history, both in the UI and to
  the agent via `get_decision_history`.

## Demo dataset

`database/demo/culling-dataset/` contains 12 synthetic JPEGs (960×640)
generated specifically for this project, with a sidecar annotation file per
image and a provenance README. No third-party photography is included. See
[dataset provenance README](database/demo/culling-dataset/README.md).

## Architecture

- **Laravel 13** API + Inertia v2/React 18 workspace UI.
- **Context-aware culling:** `ContextAwareCullingService` combines
  `PhotoObservation`s (from the deterministic `DemoPhotoAnalysisProvider`
  or the optional `VlmPhotoAnalysisProvider`) with
  `CreativeRoomService::structuredIntentFor()` to produce recommendation +
  confidence + technical/creative rationale + tradeoff + `influenced_by`
  traceability.
- **Grounded LLM layer:** `AgentLlmService` (evidence-only system prompt,
  9KB auditable context cap, provider-agnostic OpenAI-compatible transport)
  with per-photographer BYO-key wiring in `User::effectiveAiSettings()`
  (preset → env fallback precedence).
- **Persistence:** `photo_observations`, `qa_findings`,
  `photographer_decisions.photo_id`, and project-scoped human↔agent
  messages in `agent_conversation_messages` with server-derived authorship
  and UUIDv5 idempotency.
- **Workspace UI:** per-photo recommendation badges (technical quality,
  creative fit, WHY rationale, `influenced_by`, confidence, provenance
  labels, similarity grouping) with keyboard-accessible photographer
  override; presence strip and decision ledger on the same evidence.
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
- **Database-backed sessions** so login survives lambda recycling.
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

Optional AI configuration (everything works without these):

```bash
# .env — agent reasoning + VLM analysis (OpenRouter-compatible)
# AGENT_LLM_API_KEY=...
# AGENT_LLM_MODEL=meta-llama/llama-3.3-70b-instruct:free
# AGENT_LLM_VISION=true
# VLM_MODEL=google/gemini-2.5-flash
```

Or skip env entirely: each photographer can bring their own key under
**Settings → AI** (OpenRouter / NVIDIA NIM presets, key encrypted at rest).

Run the test suites:

```bash
php artisan test        # backend feature + unit suite (280+ tests)
npx vitest run          # frontend (160+ tests)
npx tsc -p tsconfig.json --noEmit
npm run build
```

## Judge walkthrough

1. Use Codex and type login https://thinking-darkroom.vercel.app/ and use Photographer Log in as `photographer@webmcp.test` (or the agent account to see the
   restricted surface — agent accounts get 403 on every human-only
   endpoint, including AI settings).
2. Open `Chat with agent`, send a project message, then have the
   authenticated agent read and reply through WebMCP. Refresh to show that
   both sides persist; page back through history with *Load earlier
   messages*. Note that chat text cannot approve or execute creative work.
3. Upload photos (≤10 files, ≤4.3MB each) — they auto-analyze on upload.
   Run culling analysis, override a cull, adopt a Creative Brief.
4. Let the agent propose a retouch plan; drag an adjustment value yourself;
   approve. `apply_approved_plan` appears in the WebMCP tool list, executes,
   and vanishes. The executed derivative reflects YOUR values, rendered by
   the production 6-key LUT. `get_decision_history` shows the full trail.
5. Optionally, bring your own key under **Settings → AI** (OpenRouter /
   NVIDIA NIM) and send another agent message — the reply is now grounded
   LLM reasoning over persisted evidence, and the audit ledger records
   `photographer_bring_your_own` as the settings source.
6. Refresh — everything (photos, decisions, derivatives, conversation,
   memory) persists.
