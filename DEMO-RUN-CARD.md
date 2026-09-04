# DEMO RUN CARD — Thinking Darkroom

Production: https://thinking-darkroom.vercel.app
Demo project: **#2 — Sprint 3 Certification — Culling Demo** (12 real JPEGs + sidecar annotations)
Login: photographer@webmcp.test / password (seeder default)
Pre-flight: `scripts/demo-prep.sh` (add `--analyze` if photos_observed < 12)

Pre-flight state verified 2026-09-04: login 302 ✓, photos_observed=12 ✓,
has_direction=yes ✓ (Creative Room direction already adopted).

---

## 0. Talking points (the one-line story)

> "The agent can look, measure, and propose — but a human decides.
> Every artifact carries honest provenance, and every execution reports
> real applied/failed/skipped counts. If zero of N apply, it fails loudly
> and rolls back — it never pretends."

## 1. Login + Dashboard (30s)

- Open /login, sign in as photographer@webmcp.test.
- Land on /dashboard → open project #2 (Sprint 3 Certification).
- **Say:** two actors exist — a human photographer and a WebMCP agent.
  The agent account cannot approve (hard 403 + audit log).

## 2. Workspace: photos already analyzed (1 min)

- Photos grid shows 12 shots; each carries evidence badges.
- **Say:** technical observations come from deterministic pixel analysis
  (`pixel_analysis` provenance); creative annotations come from the
  photographer's own sidecar files (`demo_sidecar_annotation`).
  The API never blurs that line.
- If asked about VLM: production runs the honest fallback (no VLM key
  configured); a configured key upgrades the same pipeline — provenance
  always states which provider produced the read.

## 3. Culling review (2 min)

- Open culling view → recommendation per photo: strong_keep / keep / review.
- Dataset is certified: sharp candid laugh → strong_keep (0.86);
  flat expression / posed studio → review.
- **Say:** adopted creative direction ("Documentary Intimacy — emotion
  over perfection") flows into recommendations: emotion-first weighting
  flips technical reads. Context-aware culling, not a fixed formula.

## 4. Agent proposes (2 min)

- Agent conversation → agent inspects workspace context and proposes
  a cull (propose_cull) for the `review` set.
- **Say:** propose is PROPOSE authority — it never touches selections,
  proposals stay pending until a human approves. Rate-limited, audited
  (agent_tool_calls table), exception-contained with stable error codes.

## 5. Human approves (1 min)

- Review the proposal card → Approve (confirmation dialog).
- **Say:** approval is deliberately absent from the WebMCP tool catalog.
  An agent token hitting this endpoint gets 403 — the boundary is
  enforced server-side, not by convention.
- Approve → status approved. Cancel-approval path also exists
  (cancel + note, supersede-safe).

## 6. Execute plan (2 min)

- Execute Plan → the execute tool registers dynamically (it only exists
  while an approved proposal is pending) and runs.
- **Say:** results show REAL counts — applied / failed / skipped.
  Honesty gate: 0 applied of N → HTTP 422, rollback, proposal stays
  approved and retryable. Partial failures are stored and displayed.
- Note: first execute renders derivatives, so expect a few seconds;
  afterwards it's idempotent (re-uses existing derivatives).

## 7. Retouch leg (1.5 min) — optional if time

- Agent proposes retouch_plan on a photo → approve → execute →
  derivative appears with before/after; original bytes untouched
  (sha256-verified non-destructive pipeline).
- Revert only stamps `reverted_at` and restores prior state — bytes
  are never deleted.

## 8. Export (1.5 min)

- /report page → human-only.
- /report/markdown → session markdown with honest provenance sections.
- /report/deliverables.zip → only non-reverted derivatives +
  SESSION-REPORT.md.
- **Say:** the deliverable bundle is part of the product, not an export
  afterthought — it packages what the session actually produced,
  evidence included.

## 9. Close (30s)

- Decision ledger (decisions panel) shows the full audit trail:
  who proposed, who approved, what executed, with timestamps.
- **Say:** "Every step you just watched is logged, scoped, and honest.
  That's the product: an agent that works *under* a photographer,
  not instead of one."

---

## Known demo-state facts (verified 2026-09-04)

- Production DB (Neon): 5 projects / 22 photos. P2 has an executed cull
  proposal in history — good (shows a completed loop).
- No VLM/LLM key configured in production env → analysis runs the
  deterministic provider with honest provenance. This is a feature of
  the demo (honesty gate talking point), not a gap.
- derivatives=0 right now → first execute will render fresh (few
  seconds delay). Warn the audience or pre-run one execute.
- P1 (Coastal Studio) is missing 3 seed photos (deleted during the 405
  incident investigation). Use P2 for the demo; P1 can be re-seeded if
  needed.
- A deploy-window 405 (old bundle without the delete route) was seen
  on 2026-09-04; if any endpoint 405s during the demo, hard-refresh
  (Ctrl+Shift+R) first — it's the stale-bundle case, not a bug.
