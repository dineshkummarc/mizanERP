<?php

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Models\ProjectEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pings an environment URL and records the result.
 *
 * Reachability, not correctness, is the signal: 2xx/3xx plus 401/403 count as
 * up, because most of these URLs sit behind basic auth or SSO and a checker
 * that cried "production down" whenever it wasn't logged in would train people
 * to ignore it. Redirects are not followed — a 3xx answered, and following it
 * would let a mistyped or compromised host bounce our server elsewhere.
 *
 * No response body is ever stored: only status, latency and a truncated error.
 */
class EnvironmentHealthChecker
{
    public function __construct(private EnvironmentIncidentManager $incidents) {}

    /**
     * Run one check, persist it, and let the incident manager decide whether
     * this changes the environment's confirmed state (and alerts).
     */
    public function check(ProjectEnvironment $environment): EnvironmentCheckResult
    {
        $result = $this->probe($environment);

        $environment->recordCheck($result->isUp, $result->statusCode, $result->latencyMs, $result->error);

        $this->incidents->register($environment->refresh(), $result);

        return $result;
    }

    /**
     * Make the request without touching the database — used by the checker and
     * directly by tests.
     */
    public function probe(ProjectEnvironment $environment): EnvironmentCheckResult
    {
        if (! filled($environment->url)) {
            return new EnvironmentCheckResult(false, null, null, 'No URL configured.');
        }

        // Never fetch an internal address a user typed — App\Support\SafeUrl, security audit finding 4. The
        // guard is skipped under testing, where HTTP is faked and nothing leaves the process; SafeUrl is
        // unit-tested directly instead.
        if (! app()->environment('testing') && ! \App\Support\SafeUrl::isPubliclyRoutable($environment->url)) {
            return new EnvironmentCheckResult(false, null, null, 'URL must resolve to a public address.');
        }

        $wantsBody = filled($environment->expected_content);
        $startedAt = microtime(true);

        try {
            $response = $wantsBody
                ? $this->request($environment)->get($environment->url)
                : $this->request($environment)->head($environment->url);

            // Some servers reject HEAD outright; retry those once with GET
            // rather than reporting a false outage.
            if (! $wantsBody && in_array($response->status(), [405, 501], true)) {
                $response = $this->request($environment)->get($environment->url);
            }

            $latency = $this->elapsedMs($startedAt);

            return $this->evaluate($environment, $response, $latency);
        } catch (ConnectionException $e) {
            return new EnvironmentCheckResult(false, null, $this->elapsedMs($startedAt), $this->reason($e));
        } catch (Throwable $e) {
            return new EnvironmentCheckResult(false, null, $this->elapsedMs($startedAt), $this->reason($e));
        }
    }

    protected function request(ProjectEnvironment $environment)
    {
        return Http::withoutRedirecting()
            ->withoutVerifying()
            ->timeout((int) config('projects.health.timeout', 5))
            ->connectTimeout((int) config('projects.health.connect_timeout', 3))
            ->withHeaders(['User-Agent' => config('projects.health.user_agent', 'MPR-HealthCheck/1.0')]);
    }

    protected function evaluate(ProjectEnvironment $environment, Response $response, int $latency): EnvironmentCheckResult
    {
        $status = $response->status();

        if ($environment->expected_status) {
            if ($status !== (int) $environment->expected_status) {
                return new EnvironmentCheckResult(false, $status, $latency, "Expected HTTP {$environment->expected_status}.");
            }
        } elseif (! $this->statusIsHealthy($status)) {
            return new EnvironmentCheckResult(false, $status, $latency, 'Unexpected HTTP status.');
        }

        if (filled($environment->expected_content)) {
            $body = mb_strcut((string) $response->body(), 0, (int) config('projects.health.max_body_bytes', 262144));

            if (! str_contains($body, $environment->expected_content)) {
                return new EnvironmentCheckResult(false, $status, $latency, 'Content assertion failed.');
            }
        }

        return new EnvironmentCheckResult(true, $status, $latency);
    }

    protected function statusIsHealthy(int $status): bool
    {
        if ($status >= 200 && $status < 400) {
            return true;
        }

        return in_array($status, (array) config('projects.health.healthy_codes', [401, 403]), true);
    }

    protected function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    protected function reason(Throwable $e): string
    {
        // Guzzle messages carry the full URL and a stack of context; keep the
        // first line so the stored error stays readable.
        return trim(strtok($e->getMessage(), "\n") ?: class_basename($e));
    }
}
