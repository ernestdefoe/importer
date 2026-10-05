<?php

namespace ErnestDefoe\Importer\Importers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Download an image named by the OLD board's data (an avatar, a tag cover).
 *
 * 🚨 Those addresses were typed by the old board's members, not by the
 * operator, so they are not trusted: a member who set their avatar to
 * http://169.254.169.254/… or http://127.0.0.1:6379/ would otherwise have
 * this server request it during the import. Only http(s) to public
 * addresses is fetched; every redirect hop is checked again and the vetted
 * address is pinned so DNS cannot change its answer in between. The one
 * exception is the old board's own host (the operator's --assets-base),
 * which may legitimately sit on a private network during a migration.
 *
 * Returns the body, or null for anything refused or failed: a picture that
 * can't be fetched is a member with no picture, never a failed import.
 */
final class SafeFetch
{
    private const MAX_REDIRECTS = 3;

    public static function get(string $url, int $maxBytes, int $timeout, string $trustedHost = ''): ?string
    {
        $client = new Client();

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::vet($url, $trustedHost);
            if ($target === null) {
                return null;
            }

            $options = [
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'timeout' => $timeout,
                'connect_timeout' => 5,
                'headers' => ['User-Agent' => 'curl/8.5.0'],
            ];

            if ($target['pin'] !== null && defined('CURLOPT_RESOLVE')) {
                $options['curl'] = [
                    CURLOPT_RESOLVE => [$target['pin']],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ];
            }

            $response = $client->request('GET', $url, $options);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));
                continue;
            }

            if ($status !== 200) {
                return null;
            }

            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (int) $length > $maxBytes) {
                return null;
            }

            $body = $response->getBody();
            $bytes = '';
            while (! $body->eof() && strlen($bytes) <= $maxBytes) {
                $bytes .= $body->read(65536);
            }
            $body->close();

            return strlen($bytes) > $maxBytes ? null : $bytes;
        }

        return null;
    }

    /**
     * Whether a URL may be fetched, and the curl "host:port:ip" pin to use.
     *
     * @return array{pin: string|null}|null
     */
    public static function vet(string $url, string $trustedHost = ''): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if ($trustedHost !== '' && $host === strtolower($trustedHost)) {
            return ['pin' => null];
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublic($host) ? ['pin' => null] : null;
        }

        // A host spelled only as numbers must be a plain dotted quad: 0177.0.0.1
        // is 127.0.0.1 to curl (octal) but a public address to some resolvers.
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+))*\.?$/i', $host)) {
            return null;
        }

        $ips = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return null;
            }
        }

        $pin = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        return ['pin' => $host.':'.$port.':'.$pin];
    }

    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (str_contains($ip, ':')) {
            // IPv4 hidden inside IPv6 (mapped, NAT64, 6to4) and unique-local
            // / link-local ranges are refused outright rather than decoded.
            return ! preg_match('/^(::ffff:|64:ff9b:|2002:|fc|fd|fe[89ab])/i', $ip) && $ip !== '::';
        }

        // Carrier-grade NAT (100.64.0.0/10) is private in practice.
        $long = ip2long($ip);

        return ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
    }
}
