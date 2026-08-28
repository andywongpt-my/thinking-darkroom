#!/usr/bin/env bash
# Vercel static-phase build for Thinking Darkroom.
# Runtime-only deployment scaffolding — not part of certified application code.
set -euo pipefail
cd "$(dirname "$0")"

rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php

# Regenerate resources/js/ziggy-route.js from the installed vendor Ziggy so the
# frontend bundle always matches the server's ziggy version. The route payload
# itself comes from @routes at runtime (config/ziggy.php skip-route-function),
# but the route() helper factory must live in the JS bundle — serverless
# lambdas must never read vendor/tightenco/ziggy/dist/route.umd.js (2026-08-29
# includeFiles regression -> /login 500).
node scripts/generate-ziggy-route.mjs

npm ci --no-audit --no-fund
npm run build
mkdir -p vercel-out/build
cp -r public/build/. vercel-out/build/
cp public/favicon.ico public/robots.txt vercel-out/
echo "vercel-out ready"
