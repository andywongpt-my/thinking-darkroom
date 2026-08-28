<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Skip route function
    |--------------------------------------------------------------------------
    |
    | When true, the @routes Blade directive emits only the JSON route payload
    | (const Ziggy = {...}) and does NOT inject the route() helper by reading
    | vendor/tightenco/ziggy/dist/route.umd.js at request time. The helper is
    | instead bundled into the frontend JS (resources/js/ziggy-route.js), so
    | serverless lambdas never touch the vendor file.
    |
    | Root-cause fix for the 2026-08-29 Vercel incident: includeFiles failed
    | to ship vendor/tightenco/ziggy/dist/route.umd.js into the lambda and
    | @routes' file_get_contents() 500'd every page render (/login down).
    |
    */

    'skip-route-function' => true,
];
