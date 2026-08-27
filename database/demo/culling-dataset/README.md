# Sprint 3 Demo Culling Dataset — Provenance & License

## What this is

12 synthetic JPEG "photographs" (960×640) generated **specifically for the
Thinking Darkroom hackathon project** to exercise the deterministic demo photo
analysis provider (`DemoPhotoAnalysisProvider`, classical GD pixel statistics).

## Provenance

- Every file was **generated programmatically** by an original Python script
  (`scripts/gen-culling-dataset.py`, deterministic — fixed seeds, no
  wall-clock or network input). The script is intentionally untracked;
  regenerating the dataset from it reproduces byte-identical JPEGs.
- **No third-party photography is included.** No stock photos, no scraped
  images, no camera-club downloads. The frames are original synthetic
  composites (coastal-scene gradients + geometric figure + procedural fabric
  detail + seeded grain).
- The images **do not depict real people or real client work** and must never
  be presented as client photography. They exist only to demonstrate the
  context-aware culling workflow.

## Licensing status

These files are original works created for this repository and may be
redistributed with it. Suitable license for the demo set: **CC0 1.0
(public-domain dedication)** by the Thinking Darkroom project authors.

## Files → designed technical condition

| File | Technical condition exercised | Provider bands targeted |
|---|---|---|
| `01-candid-laugh-sharp.jpg` | technically sharp + creatively strong | laplacian ≥ 8 → `sharp`; horizontal energy ≥ 2.2 → `none` blur |
| `02-soft-emotive-gaze.jpg` | slightly soft, emotionally strong (counterfactual hero) | laplacian 3.5–8 → `slightly_soft` |
| `03-runner-motion-blur.jpg` | strong motion blur, weak creative fit | horizontal energy < 1.1 → `strong` blur |
| `04-posed-studio-portrait.jpg` | sharp + heavily posed (reverse counterfactual hero) | laplacian ≥ 8 → `sharp` |
| `05-dusk-moody-underexposed.jpg` | underexposed but strong mood | mean luminance < 45 → `underexposed` |
| `06-noon-highlight-clipping.jpg` | highlight clipping risk | ≥ 8% of pixels ≥ 250 → `risk` |
| `07-coastal-environment.jpg` | strong environmental/context frame | normal exposure bands |
| `08-flat-expression.jpg` | weak expression / weak creative fit | normal technical bands |
| `09-burst-frame-a.jpg` | duplicate/similar pair — frame A | 8×8 hash group with `10` |
| `10-burst-frame-b.jpg` | duplicate/similar pair — frame B (2px shift) | 8×8 hash group with `09` |
| `11-neutral-control.jpg` | optional neutral control | all bands mid-range |
| `12-formal-precision-alt.jpg` | second tradeoff: sharp + dramatic/dark mood | laplacian ≥ 8 → `sharp` |

Deliberately **no condition is extreme** beyond its one designed axis — the
set should look plausible for a working photographer's take folder.

## Sidecar creative annotations

Each `*.jpg.obs.json` file carries **human-authored creative labels**
(expression, candidness, mood, …). These are **synthetic demo metadata** —
they describe what the generated frame was *designed to represent*.

Strict provenance separation (must survive into API, tests, UI):

- **technical** observations → source: `pixel_analysis` — computed from the
  decoded JPEG by `DemoPhotoAnalysisProvider` (sharpness, exposure, motion
  blur, highlight clipping, similarity group).
- **creative** observations → source: `demo_sidecar_annotation` — supplied by
  the sidecar file, NOT inferred from pixels by any model.

The API and UI must never imply that `emotional_strength`, `candidness`,
`storytelling` or similar fields were machine-vision-derived. When the
sidecar is absent the provider honestly reports `unobserved`.
