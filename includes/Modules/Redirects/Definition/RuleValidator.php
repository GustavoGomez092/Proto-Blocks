<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Definition;

/**
 * Rule-level checks on a normalised rule (spec sections 1-3). Errors:
 * missing_source, invalid_source, use_when_server, invalid_regex,
 * invalid_target, invalid_else, invalid_code, self_redirect, invalid_header,
 * invalid_condition. Warning: unanchored_regex. The schema and the
 * group-level checks (duplicate_rule, redirect_chain) are GroupValidator's.
 */
final class RuleValidator
{
    public const URL_CODES = [301, 302, 303, 307, 308];
    public const ERROR_CODES = [400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504];
    public const WHEN_TYPES = ['login', 'role', 'referrer', 'agent', 'header', 'cookie', 'ip', 'language', 'server', 'filter', '404'];

    private const ROLE = '/^[A-Za-z0-9_-]{1,100}$/D';
    private const COOKIE_NAME = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,100}$/D';
    private const LANGUAGE = '/^(?:\*|[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*)$/D';
    private const FILTER = '/^[a-z0-9_-]+$/D';

    /**
     * @param array<string, mixed> $rule normalised (RuleNormalizer::normalize)
     * @return list<array{path: string, code: string, message: string, level: string}>
     */
    public static function validate(array $rule, string $path = ''): array
    {
        $out = [];
        $add = static function (string $at, string $code, string $message, string $level = 'error') use (&$out, $path): void {
            $out[] = ['path' => $path . $at, 'code' => $code, 'message' => $message, 'level' => $level];
        };

        $when = is_array($rule['when'] ?? null) ? $rule['when'] : null;
        $action = (string) ($rule['action'] ?? 'url');
        $regex = ($rule['regex'] ?? false) === true;
        $from = is_string($rule['from'] ?? null) ? $rule['from'] : null;
        $to = is_string($rule['to'] ?? null) ? $rule['to'] : null;
        $else = is_string($rule['else'] ?? null) ? $rule['else'] : null;
        $code = $rule['code'] ?? null;

        // Source.
        if ($from === null || $from === '') {
            if (($when['type'] ?? null) !== '404') {
                $add('/from', 'missing_source', __('A rule needs a source ("from") unless its condition is "404".', 'proto-blocks'));
            }
        } elseif ($regex) {
            [, $error] = Patterns::compile($from, ($rule['ignoreCase'] ?? true) === true);
            if ($error !== null) {
                /* translators: %s: PHP's message */
                $add('/from', 'invalid_regex', sprintf(__('The pattern does not compile: %s', 'proto-blocks'), $error));
            } elseif (!Patterns::anchored($from)) {
                $add('/from', 'unanchored_regex', __('The pattern has neither "^" nor "$", so it matches anywhere in the URL. Anchor it unless that is intended.', 'proto-blocks'), 'warning');
            }
        } elseif (Patterns::splitAbsolute($from) !== null) {
            $add('/from', 'use_when_server', __('The source is a URL on another host. Use a path and the condition {"type": "server", "value": "<host>"} instead.', 'proto-blocks'));
        } elseif ($from[0] !== '/' || Patterns::hasControl($from)) {
            $add('/from', 'invalid_source', __('The source must be a path starting with "/" (or a pattern with "regex": true).', 'proto-blocks'));
        }

        // Target and code, per action.
        switch ($action) {
            case 'url':
                if ($to === null) {
                    $add('/to', 'invalid_target', __('A redirect needs a target ("to").', 'proto-blocks'));
                } elseif (($error = self::targetError($to, false)) !== null) {
                    $add('/to', 'invalid_target', $error);
                }
                if (!in_array($code, self::URL_CODES, true)) {
                    $add('/code', 'invalid_code', sprintf(
                        /* translators: %s: allowed codes */
                        __('A redirect code must be one of %s.', 'proto-blocks'),
                        implode(', ', self::URL_CODES)
                    ));
                }
                break;
            case 'error':
                if ($to !== null) {
                    $add('/to', 'invalid_target', __('An "error" rule has no target.', 'proto-blocks'));
                }
                if (!in_array($code, self::ERROR_CODES, true)) {
                    $add('/code', 'invalid_code', sprintf(
                        /* translators: %s: allowed codes */
                        __('An error code must be one of %s.', 'proto-blocks'),
                        implode(', ', self::ERROR_CODES)
                    ));
                }
                break;
            case 'pass':
                if ($to === null) {
                    $add('/to', 'invalid_target', __('A "pass" rule needs a target path ("to").', 'proto-blocks'));
                } elseif (($error = self::targetError($to, true)) !== null) {
                    $add('/to', 'invalid_target', $error);
                }
                if ($code !== null) {
                    $add('/code', 'invalid_code', __('A "pass" rule takes no code.', 'proto-blocks'));
                }
                break;
            default: // nothing
                if ($to !== null) {
                    $add('/to', 'invalid_target', __('A "nothing" rule has no target.', 'proto-blocks'));
                }
                if ($code !== null) {
                    $add('/code', 'invalid_code', __('A "nothing" rule takes no code.', 'proto-blocks'));
                }
        }

        if ($else !== null) {
            if ($when === null) {
                $add('/else', 'invalid_else', __('"else" needs a condition ("when").', 'proto-blocks'));
            } elseif ($action !== 'url') {
                $add('/else', 'invalid_else', __('"else" is only allowed on "url" rules.', 'proto-blocks'));
            } elseif (($error = self::targetError($else, false)) !== null) {
                $add('/else', 'invalid_else', $error);
            }
        }

        // Self-loop: a plain rule whose target is its own source.
        if (!$regex && $action === 'url' && $from !== null && $from !== '') {
            foreach (['to' => $to, 'else' => $else] as $key => $target) {
                if ($target !== null && self::sameTarget($from, $target, $rule)) {
                    $add('/' . $key, 'self_redirect', __('The rule redirects to its own source.', 'proto-blocks'));
                }
            }
        }

        foreach (is_array($rule['headers'] ?? null) ? $rule['headers'] : [] as $name => $value) {
            $error = Patterns::headerError((string) $name, $value);
            if ($error !== null) {
                $add('/headers/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name), 'invalid_header', $error);
            }
        }

        if ($when !== null) {
            foreach (self::conditionErrors($when) as [$at, $code, $message]) {
                $add('/when' . $at, $code, $message);
            }
        }

        return $out;
    }

    /** null when $target may be redirected to, else why not. $pathOnly: an internal (pass) target. */
    public static function targetError(string $target, bool $pathOnly): ?string
    {
        if ($target === '' || Patterns::hasControl($target)) {
            return __('The target may not be empty or hold control characters.', 'proto-blocks');
        }
        if (str_starts_with($target, '//') || str_starts_with($target, '/\\')) {
            return __('A target path may not start with "//" (that is another host).', 'proto-blocks');
        }
        if ($target[0] === '/') {
            return null;
        }
        if ($pathOnly) {
            return __('A "pass" target must be a path on this site, starting with "/".', 'proto-blocks');
        }
        $parts = Patterns::splitAbsolute($target);
        if ($parts === null) {
            return __('The target must be a path starting with "/" or an http:// or https:// URL.', 'proto-blocks');
        }
        if ($parts[0] === null || $parts[0] === '') {
            return __('The target URL needs a host and may not carry a user name or password.', 'proto-blocks');
        }

        return null;
    }

    /** @param array<string, mixed> $rule */
    private static function sameTarget(string $from, string $target, array $rule): bool
    {
        $target = RuleNormalizer::reduce($target);
        if ($target === '' || $target[0] !== '/') {
            return false;
        }
        $fold = static function (string $path) use ($rule): string {
            if (($rule['ignoreCase'] ?? true) === true) {
                $path = mb_strtolower($path, 'UTF-8');
            }
            if (($rule['ignoreTrailingSlash'] ?? true) === true) {
                $path = rtrim($path, '/');
                $path = $path === '' ? '/' : $path;
            }

            return $path;
        };

        return $fold($from) === $fold($target);
    }

    /**
     * @param array<string, mixed> $when normalised condition
     * @return list<array{0: string, 1: string, 2: string}> [path, code, message]
     */
    private static function conditionErrors(array $when): array
    {
        $type = (string) ($when['type'] ?? '');
        $value = $when['value'] ?? null;
        $name = $when['name'] ?? null;
        $regex = ($when['regex'] ?? false) === true;
        $bad = static fn(string $at, string $message): array => [$at, 'invalid_condition', $message];

        if (!in_array($type, self::WHEN_TYPES, true)) {
            return [$bad('/type', sprintf(
                /* translators: %s: allowed types */
                __('The condition type must be one of %s.', 'proto-blocks'),
                implode(', ', self::WHEN_TYPES)
            ))];
        }

        $out = [];
        if ($name !== null && !in_array($type, RuleNormalizer::NAMED_TYPES, true)) {
            $out[] = $bad('/name', __('Only "header" and "cookie" conditions take a "name".', 'proto-blocks'));
        }
        if ($regex && !in_array($type, RuleNormalizer::REGEX_TYPES, true)) {
            $out[] = $bad('/regex', __('Only "referrer", "agent", "header" and "cookie" conditions take "regex".', 'proto-blocks'));
        }

        if ($type === 'login' || $type === '404') {
            if ($value !== null) {
                $out[] = $bad('/value', __('This condition takes no "value".', 'proto-blocks'));
            }
            return $out;
        }

        if (in_array($type, RuleNormalizer::LIST_TYPES, true)) {
            if (!is_array($value) || $value === [] || !array_is_list($value)) {
                $out[] = $bad('/value', __('This condition needs a list of values.', 'proto-blocks'));
                return $out;
            }
            foreach ($value as $i => $item) {
                $ok = is_string($item) && ($type === 'ip'
                    ? Patterns::validIpOrCidr(trim($item))
                    : preg_match(self::LANGUAGE, $item) === 1);
                if (!$ok) {
                    $out[] = $bad('/value/' . $i, $type === 'ip'
                        ? __('Not an IP address or CIDR range.', 'proto-blocks')
                        : __('Not a language tag (for example "en" or "pt-BR").', 'proto-blocks'));
                }
            }
            return $out;
        }

        if (!is_string($value) || $value === '') {
            $out[] = $bad('/value', __('This condition needs a "value" string.', 'proto-blocks'));
            return $out;
        }
        if (mb_strlen($value, 'UTF-8') > 500) {
            $out[] = $bad('/value', __('A condition value may not be longer than 500 characters.', 'proto-blocks'));
            return $out;
        }

        if (in_array($type, RuleNormalizer::NAMED_TYPES, true)) {
            $pattern = $type === 'header' ? Patterns::HEADER_NAME : self::COOKIE_NAME;
            if (!is_string($name) || preg_match($pattern, $name) !== 1) {
                $out[] = $bad('/name', $type === 'header'
                    ? __('A header condition needs a "name" of letters, digits and "-".', 'proto-blocks')
                    : __('A cookie condition needs a valid cookie "name".', 'proto-blocks'));
            }
        }

        switch ($type) {
            case 'role':
                if (preg_match(self::ROLE, $value) !== 1) {
                    $out[] = $bad('/value', __('A role or capability uses only letters, digits, "_" and "-".', 'proto-blocks'));
                }
                break;
            case 'server':
                if (!Patterns::validHost($value, true)) {
                    $out[] = $bad('/value', __('The server condition needs a bare host name, without scheme, port or path.', 'proto-blocks'));
                }
                break;
            case 'filter':
                if (preg_match(self::FILTER, $value) !== 1) {
                    $out[] = $bad('/value', __('A filter name uses only a-z, 0-9, "_" and "-".', 'proto-blocks'));
                }
                break;
            default: // referrer, agent, header, cookie
                if ($regex) {
                    [, $error] = Patterns::compile($value, true);
                    if ($error !== null) {
                        /* translators: %s: PHP's message */
                        $out[] = ['/value', 'invalid_regex', sprintf(__('The pattern does not compile: %s', 'proto-blocks'), $error)];
                    }
                }
        }

        return $out;
    }
}
