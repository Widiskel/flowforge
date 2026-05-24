<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP step — private network policy
    |--------------------------------------------------------------------------
    |
    | When `true`, HTTP step URLs may resolve to private / loopback / link-local
    | addresses (e.g. `127.0.0.1`, `10.0.0.0/8`, `172.16.0.0/12`,
    | `192.168.0.0/16`, `169.254.0.0/16`). This is what dev environments need
    | because the seeded demo workflows call the local playground at
    | `http://127.0.0.1/api/playground/*`.
    |
    | Set to `false` in production via `HTTP_STEP_ALLOW_PRIVATE_NETWORK=false`
    | so the SSRF guard rejects internal-only targets before the HTTP client
    | touches the network.
    |
    */
    'http_step_allow_private_network' => env('HTTP_STEP_ALLOW_PRIVATE_NETWORK', true),

    /*
    |--------------------------------------------------------------------------
    | Script step — Node.js binary path
    |--------------------------------------------------------------------------
    |
    | Path to the Node.js binary used by the JavaScript script handler. The
    | child process needs Node 18+ for built-in `fetch`. Defaults to whatever
    | `node` resolves to on PATH; override with `SCRIPT_NODE_BINARY` if your
    | deployment pins a specific version (e.g. `/usr/local/bin/node20`).
    |
    */
    'node_binary' => env('SCRIPT_NODE_BINARY', 'node'),
];
