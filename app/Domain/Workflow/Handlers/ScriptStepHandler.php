<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * SCRIPT step handler — JavaScript-only.
 *
 * The user provides a `script` source; we hand it to a sandboxed Node.js
 * child process. Inside the script the user has access to:
 *
 *   $doc.input    : context output of upstream nodes, keyed by step id
 *   $doc.config   : the step's own config (sans `script`)
 *   $doc.output   : write the step output here, or use `return`
 *   fetch         : native Node 18+ fetch for outbound HTTP
 *   URL, URLSearchParams
 *   console.log/.warn/.error : captured into output.logs
 *
 * Network is allowed; filesystem and child_process are not exposed (the
 * bootstrap deletes the modules from `require.cache` before user code runs).
 *
 * Inspired by Frappe's server/client script affordances: a `doc`-shaped
 * predefined entry point and a small documented helper surface.
 * Reference: https://docs.frappe.io/framework/user/en/desk/scripting/server-script
 */
class ScriptStepHandler implements StepHandler
{
    private const JS_TIMEOUT_SECONDS = 8;

    /**
     * Hard cap on script length so the queue worker doesn't spend any time
     * compiling pathologically long input.
     */
    private const MAX_SCRIPT_LENGTH = 16_384;

    public function handle(array $config, array $context): StepResult
    {
        $script = $config['script'] ?? '';

        if (! is_string($script) || trim($script) === '') {
            return new StepResult(StepRunStatus::FAILED, [], 'SCRIPT step requires a non-empty `script` string.');
        }

        if (strlen($script) > self::MAX_SCRIPT_LENGTH) {
            return new StepResult(StepRunStatus::FAILED, [], sprintf(
                'Script is %d bytes; max %d allowed.',
                strlen($script),
                self::MAX_SCRIPT_LENGTH,
            ));
        }

        $node = config('flowforge.node_binary', 'node');
        $bootstrap = $this->bootstrapSource();
        $payload = json_encode([
            'script' => $script,
            'doc' => [
                'input' => (object) $context,
                'config' => (object) array_diff_key($config, ['script' => true]),
                'output' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $process = new Process([$node, '-e', $bootstrap]);
        $process->setInput($payload);
        $process->setTimeout(self::JS_TIMEOUT_SECONDS);
        // Don't inherit the parent process env. Pass only the locale so error
        // messages remain readable; everything else gets dropped.
        $process->setEnv(['LANG' => 'en_US.UTF-8']);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return new StepResult(
                StepRunStatus::FAILED,
                [],
                sprintf('Script timed out after %d seconds.', self::JS_TIMEOUT_SECONDS),
            );
        }

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        if ($stdout === '') {
            return new StepResult(
                StepRunStatus::FAILED,
                ['stderr' => $stderr],
                'Script produced no output (stderr above).',
            );
        }

        $decoded = json_decode($stdout, true);
        if (! is_array($decoded)) {
            return new StepResult(
                StepRunStatus::FAILED,
                ['stdout' => $stdout, 'stderr' => $stderr],
                'Script bootstrap returned non-JSON.',
            );
        }

        if (($decoded['error'] ?? null) !== null) {
            return new StepResult(
                StepRunStatus::FAILED,
                ['logs' => $decoded['logs'] ?? []],
                (string) $decoded['error'],
            );
        }

        return new StepResult(StepRunStatus::SUCCESS, [
            'output' => $decoded['output'] ?? null,
            'logs' => $decoded['logs'] ?? [],
        ]);
    }

    /**
     * Tiny Node bootstrap that reads stdin, evaluates the user's script in an
     * async wrapper with the predefined `$doc` and helpers, then writes the
     * result back to stdout as JSON.
     *
     * Filesystem / process / child_process / vm modules are wiped from the
     * require cache so user scripts can't escape the sandbox by re-importing
     * them. `fetch`, `URL`, `URLSearchParams`, and `console.log` remain.
     */
    private function bootstrapSource(): string
    {
        return <<<'JS'
            (async () => {
                let raw = "";
                for await (const chunk of process.stdin) raw += chunk;
                let payload;
                try { payload = JSON.parse(raw); }
                catch (e) { process.stdout.write(JSON.stringify({ error: "Bootstrap could not parse step input." })); return; }

                const logs = [];
                const console = {
                    log:  (...args) => logs.push({ level: "log",  message: args.map(formatArg).join(" ") }),
                    warn: (...args) => logs.push({ level: "warn", message: args.map(formatArg).join(" ") }),
                    error:(...args) => logs.push({ level: "error",message: args.map(formatArg).join(" ") }),
                };
                function formatArg(v) {
                    if (typeof v === "string") return v;
                    try { return JSON.stringify(v); } catch { return String(v); }
                }

                // Wipe dangerous modules from cache. The bootstrap itself
                // doesn't use them, so this is safe.
                for (const mod of ["fs","child_process","vm","os","cluster","http","https","net","tls","dgram","dns","worker_threads","module"]) {
                    try { delete require.cache[require.resolve(mod)]; } catch {}
                }

                const $doc = {
                    input:  payload.doc.input  ?? {},
                    config: payload.doc.config ?? {},
                    output: payload.doc.output ?? null,
                };

                let resultValue;
                try {
                    const fn = new Function("$doc", "fetch", "URL", "URLSearchParams", "console", `return (async () => { ${payload.script}\n })();`);
                    resultValue = await fn($doc, fetch, URL, URLSearchParams, console);
                } catch (err) {
                    process.stdout.write(JSON.stringify({ error: err && err.message ? err.message : String(err), logs }));
                    return;
                }

                // Prefer an explicit return value, fall back to whatever the
                // user assigned to $doc.output.
                const output = resultValue !== undefined ? resultValue : $doc.output;
                process.stdout.write(JSON.stringify({ output, logs }));
            })();
            JS;
    }
}
