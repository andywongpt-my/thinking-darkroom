#!/usr/bin/env node
/**
 * Regenerate resources/js/ziggy-route.js from the installed vendor Ziggy.
 *
 * Extracts the UMD factory body of vendor/tightenco/ziggy/dist/route.umd.js
 * and wraps it as an ES module whose default export is the route() helper.
 * Called by vercel-build.sh so the committed module can never drift from the
 * server's Ziggy version. Fail loudly (exit 1) if the vendor file is missing
 * or its shape changes — a silent fallback would reintroduce the 2026-08-29
 * production incident (/login 500, route() undefined).
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const vendorPath = resolve(root, 'vendor/tightenco/ziggy/dist/route.umd.js');
const outPath = resolve(root, 'resources/js/ziggy-route.js');

let src;
try {
    src = readFileSync(vendorPath, 'utf8');
} catch {
    console.error(`FATAL: vendor Ziggy dist not found at ${vendorPath}.`);
    console.error('Run "composer install" first; refusing to emit a broken bundle.');
    process.exit(1);
}

const boundary = src.lastIndexOf('(this,function(){');
if (boundary === -1 || !src.startsWith('!function(')) {
    console.error('FATAL: unexpected route.umd.js shape (UMD wrapper not found).');
    console.error('Ziggy changed its dist format — regenerate this extractor.');
    process.exit(1);
}

// Trailing chars are `});`: `}` closes the factory body, `)` closes the
// `(this, FACTORY)` invocation, `;` ends the statement. Strip `);` only.
const factory = src.slice(boundary + '(this,'.length).trimEnd().replace(/\)\s*;\s*$/, '');
if (!factory.startsWith('function(){') || !factory.endsWith('}')) {
    console.error('FATAL: extracted factory does not look like a function body.');
    process.exit(1);
}

const module = `/**
 * Generated from vendor/tightenco/ziggy/dist/route.umd.js by scripts/generate-ziggy-route.mjs.
 * The frontend bundle carries its own copy of the Ziggy route() factory, so
 * \`@routes\` (with config('ziggy.skip-route-function') = true) only emits the
 * route payload and the server never reads the vendor file at request time.
 * The factory reads globalThis.Ziggy lazily per call; \`@routes\` defines it.
 * Regenerated at build time - do not edit by hand.
 */
const routeFactory = ${factory};

const route = routeFactory();

export default route;
`;

writeFileSync(outPath, module);
console.log(`ziggy-route.js regenerated (${module.length} bytes) from vendor Ziggy.`);
