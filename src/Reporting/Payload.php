<?php

namespace Realmwatch\Viti\Reporting;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Realmwatch\Viti\Severity;

/**
 * Builds the report Heimdall gets. Everything here is an allowlist: a
 * request's body, cookies, credentials and query values are never read,
 * and nothing about the user but their ID. See docs/heimdall-contract.md.
 */
final class Payload
{
    public const SCHEMA = 'viti.trip.v1';

    /**
     * The request headers a report may carry. Not every cf-* header:
     * Cf-Access-Jwt-Assertion is a credential.
     */
    public const HEADERS = [
        'accept', 'accept-language', 'referer', 'user-agent',
        'cf-connecting-ip', 'cf-ray', 'cf-ipcountry', 'cf-visitor',
    ];

    /**
     * @param  array<string, mixed>  $context  the tripwire's own details: scalars or lists of scalars only
     * @return array<string, mixed>
     */
    public static function make(
        string $tripwire,
        string $token,
        Severity $severity,
        array $context,
        ?Request $request,
        int|string|null $userId,
        string $site,
        string $root,
        string $version,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'id' => (string) Str::ulid(),
            'site' => self::clip($site, 255),
            'root' => self::clip($root, 512),
            'tripwire' => self::clip($tripwire, 64),
            'token' => self::clip($token, 255),
            'severity' => $severity->value,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'request' => $request ? self::request($request) : null,
            'actor' => [
                'authenticated' => $userId !== null,
                'user_id' => $userId === null ? null : self::clip((string) $userId, 64),
            ],
            'context' => self::context($context),
            'package_version' => self::clip($version, 64),
        ];
    }

    /** @return array<string, mixed> */
    private static function request(Request $request): array
    {
        $headers = [];
        foreach (self::HEADERS as $name) {
            $value = $request->headers->get($name);
            if ($value === null || $value === '') {
                continue;
            }
            if ($name === 'referer') {
                // A referring URL's query string can hold anything: keep the page only.
                $value = (string) strtok($value, '?#');
            }
            $headers[$name] = self::clip($value, 512);
        }

        $keys = array_slice(array_map(fn ($k) => self::clip((string) $k, 64), array_keys($request->query->all())), 0, 50);

        return [
            'ip' => $request->ip(),
            'method' => self::clip($request->getMethod(), 16),
            'path' => self::clip($request->getPathInfo(), 512),
            'query_keys' => $keys,
            'headers' => (object) $headers,
        ];
    }

    /**
     * Keys are short snake_case names; values are scalars or lists of
     * scalars, cut short. Anything else is dropped.
     *
     * @param  array<array-key, mixed>  $context
     */
    private static function context(array $context): object
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (count($out) === 20 || ! is_string($key) || ! preg_match('/^[a-z0-9_]{1,40}$/', $key)) {
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                $value = array_values(array_map(
                    fn ($v) => is_string($v) ? self::clip($v, 200) : $v,
                    array_filter(array_slice($value, 0, 50), fn ($v) => is_scalar($v) || $v === null),
                ));
            } elseif (is_string($value)) {
                $value = self::clip($value, 200);
            } elseif (! is_scalar($value) && $value !== null) {
                continue;
            }
            $out[$key] = $value;
        }

        return (object) $out;
    }

    private static function clip(string $s, int $max): string
    {
        return mb_substr($s, 0, $max);
    }
}
