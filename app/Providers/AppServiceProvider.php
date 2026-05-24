<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Workflow;
use App\Policies\WorkflowPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Workflow::class, WorkflowPolicy::class);

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        // Default authenticated API throughput.
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(120)->by((string) $key);
        });

        // Login: per email + IP to slow down brute force.
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email', '');
            $key = mb_strtolower($email).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Refresh token rotation.
        RateLimiter::for('refresh', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(30)->by((string) $key);
        });

        // Webhook trigger — keyed by workflow segment when present.
        RateLimiter::for('webhook', function (Request $request) {
            $key = (string) ($request->route('workflow') ?? $request->ip());

            return Limit::perMinute(60)->by('wf:'.$key);
        });

        // AI failure analysis is expensive — strict per-user cap.
        RateLimiter::for('ai-analyze', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(5)->by('ai:'.$key);
        });

        // Dashboard metrics endpoint — per-user.
        RateLimiter::for('metrics', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(30)->by('m:'.$key);
        });

        // SSE event streams — long-lived; cap concurrent attempts loosely.
        RateLimiter::for('sse', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(30)->by('sse:'.$key);
        });

        // Playground sandbox.
        RateLimiter::for('playground', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(60)->by('pg:'.$key);
        });
    }
}
