<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects URLs that the bookmark fetcher must never be pointed at.
 *
 * Bookmarked URLs are fetched server-side and their response body is stored and
 * shown back to the user, so an unrestricted URL is a read primitive against
 * anything the app server can reach: loopback services, private network ranges,
 * and the cloud metadata endpoint on 169.254.169.254.
 */
class PublicHttpUrl implements ValidationRule
{
    /**
     * Carrier-grade NAT range, which PHP's reserved-range filter does not cover.
     */
    private const CARRIER_GRADE_NAT = ['100.64.0.0', '100.127.255.255'];

    /**
     * @var (Closure(string): list<string>)|null
     */
    private static ?Closure $resolver = null;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isFetchable($value)) {
            $fail('The :attribute must be a publicly reachable http or https URL.');
        }
    }

    /**
     * Determine whether the fetcher is allowed to request the given URL.
     *
     * Also called for each redirect hop, since an allowed public host is free to
     * redirect to a private one.
     */
    public static function isFetchable(string $url): bool
    {
        $host = self::publicSchemeHost($url);

        return $host !== null && self::hostIsPublic($host);
    }

    /**
     * Extract the host from a URL, requiring an http/https scheme.
     */
    private static function publicSchemeHost(string $url): ?string
    {
        $parsed = parse_url($url);

        if (! is_array($parsed)) {
            return null;
        }

        if (! in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = $parsed['host'] ?? '';

        return $host === '' ? null : $host;
    }

    /**
     * A host is public only when every address it resolves to is public. Hosts
     * that fail to resolve are rejected rather than passed through to the fetcher.
     */
    private static function hostIsPublic(string $host): bool
    {
        $addresses = self::resolve(trim($host, '[]'));

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values((self::$resolver)($host));
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }

    /**
     * PHP's filters already reject loopback, link-local, private and reserved
     * ranges, and unwrap IPv4-mapped IPv6 addresses. Only carrier-grade NAT has
     * to be excluded by hand.
     */
    private static function isPublicAddress(string $address): bool
    {
        $isPublic = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        if (! $isPublic) {
            return false;
        }

        return ! self::isCarrierGradeNat($address);
    }

    private static function isCarrierGradeNat(string $address): bool
    {
        $long = ip2long($address);

        if ($long === false) {
            return false;
        }

        [$start, $end] = self::CARRIER_GRADE_NAT;

        return $long >= ip2long($start) && $long <= ip2long($end);
    }

    /**
     * Swap out DNS resolution so tests do not depend on live records.
     *
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }
}
