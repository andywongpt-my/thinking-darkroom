# Thinking Darkroom — GPT-5.6 Sol Max Audit (2026-08-29)

- Auditor: GPT-5.6 Sol, reasoning=max (openai-codex via hermes chat), session 20260829_151104_d76f63, 40m19s, 389 messages / 384 tool calls, iteration budget 90/90 (report transcription by Meow from Sol's final response + /tmp/sol-audit-max-20260829.log).
- HEAD audited: e8e6766 (== origin/main). READ-ONLY honored: no files modified/committed/pushed.
- Scope: (1) delta/regression vs 2026-08-29 xhigh audit, (2) full project security+correctness, (3) UI audit. Live checks: photographer+agent logins, cookie flags, GitHub public page. PHPUnit 130/752 ✓, Vitest 77 ✓, tsc ✓, composer/npm audit 0, secret scan clean, 60 routes no GET side effects.

## Verdict

**NOT submission-ready.** ≥1 P0 + 4 P1 must be fixed first. Core demo chain (upload→analyze→propose→approve→execute→persist) breaks after upload for newly uploaded photos.

## Findings

### P0 — Blob upload only fixed display; full processing chain still broken
- Upload→MediaStore→Vercel Blob→DB absolute URL works: WorkspacePageController.php:348-375, MediaStore.php:57-59,133-142.
- But analysis/hash/retouch treat absolute Blob URLs as local public-disk paths: DemoPhotoAnalysisProvider.php:105-126,287-301, ProposalApplicator.php:123-126,263-277, DemoRetouchRenderer.php:99-117.
- Retouch derivatives still write to lambda-local /tmp/storage: ProposalApplicator.php:211-251.
- Judge-visible effect: uploaded photos display, but technical analysis, retouch execution, and derivative persistence all fail — the core demo arc.

### P1 — Failed executions still marked `executed`
- Applicator returns failed/skipped summary (ProposalApplicator.php:41-75) but ProposalService.php:253-268 and ProposalController.php:211-259 ignore it, always mark executed + return success; UI shows success too (Workspace.tsx:620-627). All-failed plans become non-retryable; dynamic tool vanishes.

### P1 — WebMCP retouch schema drifts from backend capability
- Frontend declares crop/spot_heal/tone_curve/white_balance (webmcp/tools/proposals.ts:57-100); backend only supports exposure/contrast/saturation/warmth/highlight_recovery/shadow_lift (Domain.php:212-219). Legit agent requests get silently skipped → feeds the false-success above.

### P1 — 15MiB × 20 upload vs Vercel 4.5MB request cap
- Backend still allows 15MB×20 (WorkspacePageController.php:341-344); no client-side size/count precheck (Workspace.tsx:736-760,804-810). Large files 413 at the edge before Laravel validation.

### P1 (high-confidence) — native fetch CSRF token empty
- app.blade.php has no csrf-token meta (confirmed on live page); Workspace POSTs read the missing meta at Workspace.tsx:581-583,600-603,653-659,683-689,714-720 → approve/reject/modify/QA/memory likely 419 in production. Destructive live POST not tested (授权范围限制).

### Notable P2/P3
- /dashboard serves data but Dashboard.tsx:4-25 ignores it — post-login dead page ("You're logged in!"); judges land here.
- DatabaseSeeder.php:86-108 unconditionally creates proposals/decisions — not idempotent; duplicate demo proposals already in prod.
- ContextAwareCullingService.php:52-78 check-then-create race → unique-constraint hits.
- api/storage.php:57-76 refresh deletes-then-renames, not atomic.
- MIME validation ok (content-based); no pixel-dimension/decompression-bomb limits; no throttle/quota on uploads.
- Dashboard N+1 (2× count per project); no pagination on photos/proposals.
- UI: no aria-live/alert on notifications; 10-11px low-contrast text; small touch targets; swallowed errors render as "no data"; permanent "Rendering…" on retouch failure (Workspace.tsx:1182-1184); full reloads instead of Inertia partial reloads.
- README missing live URL/demo creds/judge path/deploy notes/video; test counts stale; claims Laravel 12 + React 19 but manifests show Laravel 13.x + React 18.2 — submission-material drift.

## Prior-findings matrix

| Prior | Status |
|---|---|
| P0 upload /storage 404 | PARTIAL — original Blob URL correct; downstream chain still broken |
| P0 lambda SQLite fork | FIXED (durable pgsql path) |
| P1 upload false success | PARTIAL — original upload ok; multi-file partial + post-approve false success remain |
| P1 silent storage failure | PARTIAL — originals fixed; derivatives not |
| P1 SQLite lockForUpdate no-op | FIXED (pgsql path) |
| P1 15MB vs 4.5MB | NOT FIXED |
| P2 storage atomic refresh | PARTIAL |
| P2 Blob/DB compensation | PARTIAL — single file ok, batch not atomic |
| P2 upload resource exhaustion | PARTIAL — still no throttle/quota/dimensions |
| P2 public media/sidecar | UNCHANGED |
| P2 test gap | PARTIAL — no e2e durable-Blob→analyze/retouch test |
| P3 debug logging | FIXED |

## Verified good
- Login works (photographer + agent) live; cookie Secure/HttpOnly/SameSite=Lax, survives requests.
- Agent cannot see Upload; human-only endpoints 403 tested; executed-plan replay → 409; dynamic tool lifecycle server-side constraint basically correct.
- No secrets in git/client bundle; no GET side-effect routes; MIT LICENSE visible on public repo.

## Submission gate (must fix before submit)
1. MediaStore must cover original reads, hashing, analysis, rendering, derivative Blob writes.
2. Failed executions must not mark executed; add failed/partial/retry states.
3. Align WebMCP retouch schema with backend-executable params.
4. Resolve Vercel 4.5MB upload contract (client+server).
5. Fix fetch CSRF (meta or XSRF cookie); verify approve flow in a real browser.
6. Fix post-login Dashboard navigation (render real content).
7. Update README: live URL, demo creds, judge test path, Neon/Blob deploy notes, versions, video.
8. Re-run real Chrome WebMCP e2e: login→upload→analyze→proposal→human approve→dynamic tool appears→execute→persist→survives refresh.
