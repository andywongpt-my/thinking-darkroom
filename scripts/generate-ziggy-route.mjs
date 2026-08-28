#!/usr/bin/env node
/**
 * Generate resources/js/ziggy-route.js from the installed vendor Ziggy.
 *
 * Extracts the UMD factory body of vendor/tightenco/ziggy/dist/route.umd.js,
 * wraps it as an ES module default-exporting route(), and stamps the header
 * with the ziggy version + factory sha256. The stamp lives ONLY in the file
 * header — the JS payload is byte-identical to a plain extraction.
 *
 * Two modes:
 *  - Vendor present (local dev / composer install): extract + write + stamp.
 *  - Vendor absent (Vercel: the vercel-php runtime installs composer deps
 *    AFTER buildCommand): validate the committed file's stamp against
 *    composer.lock. Mismatch (ziggy upgraded or factory changed) → loud
 *    failure — a silent fallback would reintroduce the 2026-08-29 /login 500.
 */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const vendorPath = resolve(root, 'vendor/tightenco/ziggy/dist/route.umd.js');
const outPath = resolve(root, 'resources/js/ziggy-route.js');

let src;
if (existsSync(vendorPath)) {
    src = readFileSync(vendorPath, 'utf8');
} else {
    // Vercel buildCommand phase runs BEFORE the vercel-php runtime installs
    // composer deps, so vendor may legitimately be absent there. The committed
    // module must then provably match composer.lock (version + factory hash).
    validateStamp(root, outPath);
    console.log('vendor Ziggy dist absent — committed ziggy-route.js stamp validated against composer.lock.');
    process.exit(0);
}

const lock = JSON.parse(readFileSync(resolve(root, 'composer.lock'), 'utf8'));
const pkg = (lock.packages ?? []).find((p) => p.name === 'tightenco/ziggy');
if (!pkg) {
    fail('tightenco/ziggy not found in composer.lock (require section).');
}
const factory = extractFactory(src);

const module = `/**
 * Generated from vendor/tightenco/ziggy/dist/route.umd.js by scripts/generate-ziggy-route.mjs.
 * The frontend bundle carries its own copy of the Ziggy route() factory, so
 * \`@routes\` (with config('ziggy.skip-route-function') = true) only emits the
 * route payload and the server never reads the vendor file at request time.
 * The factory reads globalThis.Ziggy lazily per call; \`@routes\` defines it.
 * Regenerated at build time - do not edit by hand.
 * @stamped ${stampFor(pkg.version, factory)}
 */
const routeFactory = ${factory};

const route = routeFactory();

export default route;
`;

writeFileSync(outPath, module);
console.log(`ziggy-route.js regenerated (${module.length} bytes) from vendor Ziggy.`);

function extractFactory(source) {
    const boundary = source.lastIndexOf('(this,function(){');
    if (boundary === -1 || !source.startsWith('!function(')) {
        fail('unexpected route.umd.js shape (UMD wrapper not found) — Ziggy changed its dist format; regenerate this extractor.');
    }
    // Trailing chars are `});`: `}` closes the factory body, `)` closes the
    // `(this, FACTORY)` invocation, `;` ends the statement. Strip `);` only.
    const factory = source.slice(boundary + '(this,'.length).trimEnd().replace(/\)\s*;\s*$/, '');
    if (!factory.startsWith('function(){') || !factory.endsWith('}')) {
        fail('extracted factory does not look like a function body.');
    }
    return factory;
}

function stampFor(ziggyVersion, factory) {
    return `ziggy ${ziggyVersion} factory-sha256 ${createHash('sha256').update(factory).digest('hex')}`;
}

function factoryFromModule(moduleSource) {
    const start = moduleSource.indexOf('const routeFactory = ');
    if (start === -1) {
        fail('committed ziggy-route.js is missing the routeFactory block.');
    }
    const end = moduleSource.indexOf('\n\nconst route', start);
    if (end === -1) {
        fail('committed ziggy-route.js is missing the route export block.');
    }
    const factory = moduleSource
        .slice(start + 'const routeFactory = '.length, end)
        .trimEnd()
        .replace(/;\s*$/, '');
    if (!factory.startsWith('function(){') || !factory.endsWith('}')) {
        fail('committed ziggy-route.js factory does not look like a function body.');
    }
    return factory;
}

function validateStamp(root, outPath) {
    const lock = JSON.parse(readFileSync(resolve(root, 'composer.lock'), 'utf8'));
    const pkg = (lock.packages ?? []).find((p) => p.name === 'tightenco/ziggy');
    if (!pkg) {
        fail('tightenco/ziggy not found in composer.lock (require section).');
    }
    const committed = readFileSync(outPath, 'utf8');
    const expected = stampFor(pkg.version, factoryFromModule(committed));
    const actual = committed.match(/^ \* @stamped (ziggy .+)$/m)?.[1];
    if (actual !== expected) {
        console.error(`FATAL: ziggy-route.js stamp mismatch (expected "${expected}", found "${actual ?? 'none'}").`);
        console.error('The committed factory no longer matches composer.lock — run this script locally with vendor present, or revert the file.');
        process.exit(1);
    }
}

function fail(message) {
    console.error(`FATAL: ${message}`);
    process.exit(1);
}
