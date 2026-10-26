<?php

namespace App\Support;

/**
 * Whether a URL the server is about to fetch points somewhere publicly routable — security audit
 * 2026-10-26, finding 4.
 *
 * A monitoring URL is typed by a user, and `url` validation is a format check: `http://127.0.0.1:6379/`,
 * `http://169.254.169.254/`, `http://10.0.0.1/` all pass it. Without this guard the health and certificate
 * checkers would fetch any of them on a button press and again on a schedule, turning the server into an
 * SSRF proxy for its own internal network. Every address the host resolves to must be public, because a
 * name can point at a private IP and a literal can be written to dodge a naive string check.
 *
 * Resolve-time only: a determined DNS-rebinding setup could still move the name between the check and the
 * connection. Closing that needs the caller to connect to the pinned address, which these checkers do not;
 * the residual is noted in the audit. This blocks the straightforward case, which is the whole of the
 * reported finding.
 */
final class SafeUrl
{
    public static function isPubliclyRoutable(?string $url): bool
    {
        $parts = parse_url((string) $url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $ips = self::resolve($host);

        // Unresolvable: fail closed rather than hand an unknown host to the socket layer.
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    private static function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        $ips = array_values(array_filter($ips));

        if ($ips === [] && is_array($resolved = gethostbynamel($host))) {
            $ips = $resolved;
        }

        return $ips;
    }

    private static function isPublicIp(string $ip): bool
    {
        // Rejects loopback, private and reserved ranges (which covers link-local).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Carrier-grade NAT 100.64.0.0/10 is not in every build's reserved set.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        }

        return true;
    }
}
