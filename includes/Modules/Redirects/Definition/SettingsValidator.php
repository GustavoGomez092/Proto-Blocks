<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Definition;

/**
 * proto-redirects/settings.jsonc: the schema (`$defs/settings`: unknown keys,
 * types, ranges, enums) plus header grammar (`invalid_header`), host-only
 * aliases (`invalid_host`), the relocate URL (`invalid_url`) and trusted
 * proxy addresses (`invalid_ip`).
 */
final class SettingsValidator
{
    /**
     * @param array<string|int, mixed> $data
     * @return array{errors: list<array{path: string, code: string, message: string, level: string}>, warnings: list<array{path: string, code: string, message: string, level: string}>}
     */
    public static function validate(array $data): array
    {
        $errors = Schema::check($data, 'settings');
        $add = static function (string $path, string $code, string $message) use (&$errors): void {
            $errors[] = ['path' => $path, 'code' => $code, 'message' => $message, 'level' => 'error'];
        };

        $headers = is_array($data['headers'] ?? null) ? $data['headers'] : [];
        foreach (['site', 'redirect'] as $scope) {
            foreach (is_array($headers[$scope] ?? null) ? $headers[$scope] : [] as $name => $value) {
                $error = Patterns::headerError((string) $name, $value);
                if ($error !== null) {
                    $add('/headers/' . $scope . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name), 'invalid_header', $error);
                }
            }
        }

        $canonical = is_array($data['canonical'] ?? null) ? $data['canonical'] : [];
        foreach (is_array($canonical['aliases'] ?? null) ? $canonical['aliases'] : [] as $i => $alias) {
            if (is_string($alias) && !Patterns::validHost($alias)) {
                $add('/canonical/aliases/' . $i, 'invalid_host', __('An alias is a host name only: no scheme, port or path (use punycode for international names).', 'proto-blocks'));
            }
        }
        $relocate = $canonical['relocate'] ?? null;
        if (is_string($relocate) && $relocate !== '' && !Settings::validRelocate($relocate)) {
            $add('/canonical/relocate', 'invalid_url', __('"relocate" must be empty or an absolute http:// or https:// URL without query or fragment.', 'proto-blocks'));
        }

        $log = is_array($data['log'] ?? null) ? $data['log'] : [];
        foreach (is_array($log['trustedProxies'] ?? null) ? $log['trustedProxies'] : [] as $i => $proxy) {
            if (is_string($proxy) && !Patterns::validIpOrCidr($proxy)) {
                $add('/log/trustedProxies/' . $i, 'invalid_ip', __('Not an IP address or CIDR range.', 'proto-blocks'));
            }
        }

        return ['errors' => $errors, 'warnings' => []];
    }
}
