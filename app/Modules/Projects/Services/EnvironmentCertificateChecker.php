<?php

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Notifications\CertificateExpiring;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the TLS certificate of an https environment and alerts as expiry
 * approaches.
 *
 * Two deliberate choices: peer verification is off, because dev and qual often
 * run self-signed certificates and we want the expiry date regardless (chain
 * validity is recorded separately, not alerted on); and a certificate problem
 * never marks the environment down — that is the HTTP check's job, and keeping
 * them independent stops one cert issue from muddying the uptime figure.
 */
class EnvironmentCertificateChecker
{
    public function __construct(private EnvironmentIncidentManager $incidents) {}

    public function check(ProjectEnvironment $environment): ?array
    {
        if (! $environment->isHttps()) {
            return null;
        }

        $parts = parse_url($environment->url);
        $host = $parts['host'] ?? null;

        if (! $host) {
            return null;
        }

        $port = $parts['port'] ?? 443;

        // Same guard as the health checker (skipped under testing): never open a socket to an internal host.
        if (! app()->environment('testing') && ! \App\Support\SafeUrl::isPubliclyRoutable($environment->url)) {
            return null;
        }
        $timeout = (int) config('projects.ssl.timeout', 5);

        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);

            $client = @stream_socket_client(
                "ssl://{$host}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (! $client) {
                $environment->forceFill([
                    'ssl_checked_at' => now(),
                    'ssl_valid_chain' => null,
                ])->save();

                return null;
            }

            $params = stream_context_get_params($client);
            fclose($client);

            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

            if (! $certificate) {
                $environment->forceFill(['ssl_checked_at' => now()])->save();

                return null;
            }

            $parsed = openssl_x509_parse($certificate);

            if (! $parsed || empty($parsed['validTo_time_t'])) {
                $environment->forceFill(['ssl_checked_at' => now()])->save();

                return null;
            }

            $expiresAt = Carbon::createFromTimestamp($parsed['validTo_time_t']);
            $issuer = $parsed['issuer']['CN'] ?? ($parsed['issuer']['O'] ?? null);

            $environment->forceFill([
                'ssl_expires_at' => $expiresAt,
                'ssl_issuer' => $issuer ? mb_substr($issuer, 0, 255) : null,
                'ssl_checked_at' => now(),
                'ssl_valid_chain' => $this->verifiesChain($host, $port, $timeout),
            ])->save();

            $this->alertIfExpiring($environment->refresh());

            return ['expires_at' => $expiresAt, 'issuer' => $issuer];
        } catch (Throwable) {
            $environment->forceFill(['ssl_checked_at' => now()])->save();

            return null;
        }
    }

    /**
     * Each threshold alerts once: ssl_alerted_at_days remembers the last one
     * crossed, so a certificate 20 days out doesn't email every morning.
     */
    protected function alertIfExpiring(ProjectEnvironment $environment): void
    {
        $days = $environment->sslDaysRemaining();

        if ($days === null) {
            return;
        }

        $thresholds = collect(config('projects.ssl.thresholds', [30, 14, 7, 3, 1]))
            ->sort()
            ->values();

        // The tightest threshold the remaining days have crossed: 5 days left
        // means the 7-day threshold, not the 30-day one.
        $crossed = $thresholds->filter(fn (int $threshold) => $days <= $threshold)->min();

        if ($crossed === null) {
            // Comfortably valid again (renewed): allow future thresholds to fire.
            if ($environment->ssl_alerted_at_days !== null) {
                $environment->forceFill(['ssl_alerted_at_days' => null])->save();
            }

            return;
        }

        // Already alerted at this threshold or a tighter one.
        if ($environment->ssl_alerted_at_days !== null && $environment->ssl_alerted_at_days <= $crossed) {
            return;
        }

        $environment->forceFill(['ssl_alerted_at_days' => $crossed])->save();

        $this->incidents->notify($environment, new CertificateExpiring($environment, (int) $days));
    }

    protected function verifiesChain(string $host, int $port, int $timeout): ?bool
    {
        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host],
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client) {
            fclose($client);

            return true;
        }

        return false;
    }
}
