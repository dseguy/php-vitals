<?php

declare(strict_types=1);

namespace Vitals\Format;

use Vitals\Builder;
use Vitals\Exception\BuildException;
use Vitals\Exception\ConfigurationException;
use Vitals\Exception\FormatException;
use Vitals\Flag\UrlFlag;
use Vitals\Internal\AbstractFormat;
use Vitals\Internal\NativeCall;
use Vitals\Option\AllowedSchemes;
use Vitals\Parser;
use Vitals\Validator;
use Vitals\ViolationCode;

/**
 * URLs, in three modes: RFC 3986 (default, pure PHP on every version), WHATWG (PHP 8.5+,
 * native Uri\WhatWg\Url) and parse_url().
 *
 * Components stay percent-encoded exactly as in the input.
 */
final readonly class Url extends AbstractFormat implements Parser, Builder, Validator
{
    private const KEYS = ['scheme', 'user', 'pass', 'host', 'port', 'path', 'query', 'fragment'];
    private const UNRESERVED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    private const SUB_DELIMS = "!$&'()*+,;=";
    private const WHATWG_CLASS = 'Uri\\WhatWg\\Url';

    protected static function flagEnum(): string
    {
        return UrlFlag::class;
    }

    protected static function optionClasses(): array
    {
        return [AllowedSchemes::class];
    }

    protected function checkRequirements(): void
    {
        if ($this->mode() === UrlFlag::WhatWg && !class_exists(self::WHATWG_CLASS)) {
            throw new ConfigurationException('UrlFlag::WhatWg needs PHP 8.5 or later');
        }
        if ($this->config->has(UrlFlag::Idn) && !function_exists('idn_to_ascii')) {
            throw new ConfigurationException('UrlFlag::Idn needs ext-intl');
        }
    }

    /**
     * @return array{scheme: ?string, user: ?string, pass: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string}
     */
    public function parse(string $input): array
    {
        /** @var array{scheme: ?string, user: ?string, pass: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string} */
        return parent::parse($input);
    }

    protected function analyse(string $input): array
    {
        $parts = match ($this->mode()) {
            UrlFlag::WhatWg => $this->whatwg($input),
            UrlFlag::ParseUrl => $this->parseUrl($input),
            default => $this->rfc3986($input),
        };

        if ($parts['scheme'] === null && !$this->config->has(UrlFlag::AllowRelative)) {
            throw FormatException::of(ViolationCode::UrlMissingScheme, 'URL has no scheme', 0);
        }

        return $parts;
    }

    protected function checkParsed(array $parsed): void
    {
        $allowed = $this->config->option(AllowedSchemes::class);
        if ($allowed !== null && is_string($parsed['scheme']) && !$allowed->allows($parsed['scheme'])) {
            throw FormatException::of(ViolationCode::UrlDisallowedScheme, sprintf('scheme "%s" is not allowed', $parsed['scheme']), 0);
        }
    }

    public function build(array $parts): string
    {
        $unknown = array_diff(array_keys($parts), self::KEYS);
        if ($unknown !== []) {
            throw new BuildException(sprintf('unknown key "%s"', (string) reset($unknown)));
        }
        foreach (['scheme', 'user', 'pass', 'host', 'path', 'query', 'fragment'] as $key) {
            if (isset($parts[$key]) && !is_string($parts[$key])) {
                throw new BuildException(sprintf('%s must be a string or null', $key));
            }
        }

        /** @var array{scheme?: ?string, user?: ?string, pass?: ?string, host?: ?string, port?: mixed, path?: ?string, query?: ?string, fragment?: ?string} $parts */
        $scheme = $parts['scheme'] ?? null;
        $user = $parts['user'] ?? null;
        $pass = $parts['pass'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? null;
        $fragment = $parts['fragment'] ?? null;

        if ($port !== null && (!is_int($port) || $port < 0 || $port > 65535)) {
            throw new BuildException('port must be an int between 0 and 65535');
        }
        if ($host === null && ($user !== null || $pass !== null || $port !== null)) {
            throw new BuildException('user, pass and port need a host');
        }
        if ($pass !== null && $user === null) {
            throw new BuildException('pass needs a user');
        }
        if ($host !== null && $path !== '' && $path[0] !== '/') {
            throw new BuildException('with a host, the path must be empty or start with "/"');
        }
        if ($host === null && str_starts_with($path, '//')) {
            throw new BuildException('without a host, the path must not start with "//"');
        }
        if ($scheme === null && $host === null && str_contains(explode('/', $path, 2)[0], ':')) {
            throw new BuildException('without a scheme, the first path segment must not contain ":"');
        }

        $url = ($scheme !== null ? $scheme . ':' : '')
            . ($host !== null
                ? '//' . ($user !== null ? $user . ($pass !== null ? ':' . $pass : '') . '@' : '') . $host . ($port !== null ? ':' . $port : '')
                : '')
            . $path
            . ($query !== null ? '?' . $query : '')
            . ($fragment !== null ? '#' . $fragment : '');

        if ($this->mode() === UrlFlag::Rfc3986) {
            try {
                $this->rfc3986($url);
            } catch (FormatException $exception) {
                throw new BuildException('invalid URL: ' . $exception->getMessage(), 0, $exception);
            }
        }

        return $url;
    }

    private function mode(): UrlFlag
    {
        return $this->config->choice([UrlFlag::Rfc3986, UrlFlag::WhatWg, UrlFlag::ParseUrl]);
    }

    /**
     * @return array{scheme: ?string, user: ?string, pass: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string}
     */
    private function rfc3986(string $input): array
    {
        // RFC 3986, appendix B
        $m = [];
        preg_match('~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s', $input, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        [$scheme] = self::group($m, 1);
        [$authority, $authorityAt] = self::group($m, 2);
        [$path, $pathAt] = self::group($m, 3);
        [$query, $queryAt] = self::group($m, 4);
        [$fragment, $fragmentAt] = self::group($m, 5);
        $path ??= '';

        if ($scheme !== null && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/', $scheme) !== 1) {
            throw FormatException::of(ViolationCode::UrlInvalidScheme, sprintf('invalid scheme "%s"', $scheme), 0);
        }

        $user = $pass = $host = $port = null;
        if ($authority !== null) {
            [$user, $pass, $host, $port] = $this->authority($authority, $authorityAt);
        }

        $pchar = self::UNRESERVED . self::SUB_DELIMS . ':@';
        self::scan($path, $pathAt, $pchar . '/', ViolationCode::UrlInvalidCharacter);
        if ($query !== null) {
            self::scan($query, $queryAt, $pchar . '/?', ViolationCode::UrlInvalidCharacter);
        }
        if ($fragment !== null) {
            self::scan($fragment, $fragmentAt, $pchar . '/?', ViolationCode::UrlInvalidCharacter);
        }

        return [
            'scheme' => $scheme === null ? null : strtolower($scheme),
            'user' => $user,
            'pass' => $pass,
            'host' => $host,
            'port' => $port,
            'path' => $path,
            'query' => $query,
            'fragment' => $fragment,
        ];
    }

    /**
     * A capture group of preg_match() with PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL.
     *
     * @param array<mixed> $matches
     *
     * @return array{?string, int} the group, null when unmatched, and its offset
     */
    private static function group(array $matches, int $index): array
    {
        $group = $matches[$index] ?? null;
        if (!is_array($group) || !is_string($group[0] ?? null)) {
            return [null, 0];
        }

        return [$group[0], is_int($group[1] ?? null) ? $group[1] : 0];
    }

    /**
     * @return array{?string, ?string, string, ?int}
     */
    private function authority(string $authority, int $at): array
    {
        $user = $pass = null;
        $hostAt = $at;
        $arobase = strrpos($authority, '@');
        if ($arobase !== false) {
            $userinfo = substr($authority, 0, $arobase);
            self::scan($userinfo, $at, self::UNRESERVED . self::SUB_DELIMS . ':', ViolationCode::UrlInvalidUserinfo);
            [$user, $pass] = explode(':', $userinfo, 2) + [1 => null];
            $authority = substr($authority, $arobase + 1);
            $hostAt = $at + $arobase + 1;
        }

        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            if ($close === false) {
                throw FormatException::of(ViolationCode::UrlInvalidIpv6, 'IP literal without closing "]"', $hostAt);
            }
            $literal = substr($authority, 1, $close - 1);
            $valid = preg_match('/^[vV][0-9A-Fa-f]+\.[A-Za-z0-9\-._~!$&\'()*+,;=:]+$/', $literal) === 1
                || filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (!$valid) {
                throw FormatException::of(ViolationCode::UrlInvalidIpv6, sprintf('invalid IP literal "%s"', $literal), $hostAt);
            }
            $host = '[' . $literal . ']';
            $rest = substr($authority, $close + 1);
            if ($rest !== '' && $rest[0] !== ':') {
                throw FormatException::of(ViolationCode::UrlInvalidHost, 'unexpected characters after the IP literal', $hostAt + $close + 1);
            }
            $portText = $rest === '' ? null : substr($rest, 1);
            $portAt = $hostAt + $close + 2;
        } else {
            $colon = strpos($authority, ':');
            $host = $colon === false ? $authority : substr($authority, 0, $colon);
            $portText = $colon === false ? null : substr($authority, $colon + 1);
            $portAt = $hostAt + (int) $colon + 1;
            $host = $this->regName($host, $hostAt);
        }

        $port = null;
        if ($portText !== null && $portText !== '') {
            if (!ctype_digit($portText)) {
                throw FormatException::of(ViolationCode::UrlInvalidPort, sprintf('invalid port "%s"', $portText), $portAt);
            }
            $digits = ltrim($portText, '0');
            if (strlen($digits) > 5 || (int) $digits > 65535) {
                throw FormatException::of(ViolationCode::UrlPortOutOfRange, sprintf('port %s is greater than 65535', $portText), $portAt);
            }
            $port = (int) $digits;
        }

        return [$user, $pass, $host, $port];
    }

    private function regName(string $host, int $at): string
    {
        if ($this->config->has(UrlFlag::Idn) && preg_match('/[\x80-\xff]/', $host) === 1) {
            [$ascii] = NativeCall::run(static fn () => idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46));
            if (!is_string($ascii)) {
                throw FormatException::of(ViolationCode::UrlInvalidIdn, sprintf('"%s" cannot be converted to ASCII', $host), $at);
            }
            $host = $ascii;
        }

        self::scan($host, $at, self::UNRESERVED . self::SUB_DELIMS, ViolationCode::UrlInvalidHost);

        if (preg_match('/^\d+(\.\d+){3}$/', $host) === 1) {
            foreach (explode('.', $host) as $octet) {
                if ((strlen($octet) > 1 && $octet[0] === '0') || (int) $octet > 255) {
                    throw FormatException::of(ViolationCode::UrlInvalidIpv4, sprintf('invalid IPv4 address "%s"', $host), $at);
                }
            }
        }

        return $host;
    }

    /**
     * Checks that $value only holds the allowed characters and valid percent-encodings.
     */
    private static function scan(string $value, int $at, string $allowed, ViolationCode $code): void
    {
        $length = strlen($value);
        for ($i = strspn($value, $allowed); $i < $length; $i += 1 + strspn($value, $allowed, $i + 1)) {
            if ($value[$i] !== '%') {
                throw FormatException::of($code, sprintf('character "%s" is not allowed here', self::printable($value[$i])), $at + $i);
            }
            if ($i + 2 >= $length || !ctype_xdigit($value[$i + 1] . $value[$i + 2])) {
                throw FormatException::of(ViolationCode::UrlInvalidPercentEncoding, '"%" must be followed by two hexadecimal digits', $at + $i);
            }
            $i += 2;
        }
    }

    private static function printable(string $char): string
    {
        return ctype_print($char) ? $char : sprintf('\\x%02X', ord($char));
    }

    /**
     * @return array{scheme: ?string, user: ?string, pass: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string}
     */
    private function parseUrl(string $input): array
    {
        [$parts] = NativeCall::run(static fn () => parse_url($input));
        if (!is_array($parts)) {
            throw FormatException::of(ViolationCode::CommonNativeError, 'parse_url() rejected the URL');
        }

        return [
            'scheme' => isset($parts['scheme']) ? strtolower($parts['scheme']) : null,
            'user' => $parts['user'] ?? null,
            'pass' => $parts['pass'] ?? null,
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '',
            'query' => $parts['query'] ?? null,
            'fragment' => $parts['fragment'] ?? null,
        ];
    }

    /**
     * @return array{scheme: ?string, user: ?string, pass: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string}
     */
    private function whatwg(string $input): array
    {
        $class = self::WHATWG_CLASS;
        $errors = [];
        /** @var object|null $url */
        $url = $class::parse($input, null, $errors);
        if ($url === null) {
            $failure = null;
            foreach ($errors as $error) {
                if ($error->failure ?? false) {
                    $failure = $error;
                    break;
                }
            }
            $type = $failure?->type->name ?? 'Unknown';

            throw FormatException::of(self::whatwgCode($type), sprintf('WHATWG URL parser failure: %s', $type));
        }

        /** @var array{scheme: ?string, user: ?string, pass: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string} $parts */
        $parts = [
            'scheme' => $url->getScheme(),
            'user' => $url->getUsername(),
            'pass' => $url->getPassword(),
            'host' => $url->getAsciiHost(),
            'port' => $url->getPort(),
            'path' => $url->getPath(),
            'query' => $url->getQuery(),
            'fragment' => $url->getFragment(),
        ];

        return $parts;
    }

    private static function whatwgCode(string $type): ViolationCode
    {
        return match (true) {
            str_starts_with($type, 'PortOutOfRange') => ViolationCode::UrlPortOutOfRange,
            str_starts_with($type, 'PortInvalid') => ViolationCode::UrlInvalidPort,
            str_starts_with($type, 'Ipv6') => ViolationCode::UrlInvalidIpv6,
            str_starts_with($type, 'Ipv4') => ViolationCode::UrlInvalidIpv4,
            str_starts_with($type, 'Domain'), str_starts_with($type, 'Host') => ViolationCode::UrlInvalidHost,
            str_starts_with($type, 'MissingScheme') => ViolationCode::UrlMissingScheme,
            str_starts_with($type, 'InvalidCredentials') => ViolationCode::UrlInvalidUserinfo,
            default => ViolationCode::CommonNativeError,
        };
    }
}
