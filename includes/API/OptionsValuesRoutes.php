<?php

declare(strict_types=1);

namespace ProtoBlocks\API;

use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\OptionsValues;
use ProtoBlocks\OptionsPages\PageDefinition;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * `proto-blocks/v1/options/<slug>`: read (GET) and save (POST) the values of
 * one settings page. Both need a logged-in user who holds the page's own
 * declared capability; nothing weaker, nothing implied by manage_options.
 * Cookie-authenticated requests also carry the `wp_rest` nonce, which REST
 * core enforces before permission() runs (without it the user is 0).
 */
final class OptionsValuesRoutes
{
    public const NAMESPACE = 'proto-blocks/v1';
    public const ROUTE = '/options/(?P<slug>[a-z][a-z0-9-]{0,63})';

    /** `D`: `$` must not accept a trailing newline. */
    private const SLUG_RE = '/^[a-z][a-z0-9-]{0,63}$/D';

    public function __construct(
        private readonly OptionsPagesRegistry $registry,
        private readonly OptionsValues $values,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get'],
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'post'],
                'permission_callback' => [$this, 'permission'],
            ],
        ]);
    }

    public function permission(\WP_REST_Request $r): bool|\WP_Error
    {
        // A logged-out request never passes, whatever capability a page names ("exist" is held by everyone).
        if (get_current_user_id() <= 0) {
            return false;
        }
        $page = $this->page($r);
        if ($page === null) {
            return self::notFound();
        }

        return current_user_can($page->capability);
    }

    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $page = $this->page($r);
        if ($page === null) {
            return self::notFound();
        }

        $userId = get_current_user_id();
        $readOnly = $this->values->readOnly($page, $userId);

        return new \WP_REST_Response([
            'values' => self::mask($this->values->read($page), $readOnly),
            'trusted' => !AttributeSanitizer::shouldSanitizeForUser($userId),
            // field name => reason; cast so an empty map is `{}` in JSON, not `[]`.
            'readOnly' => (object) $readOnly,
        ], 200);
    }

    public function post(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $page = $this->page($r);
        if ($page === null) {
            return self::notFound();
        }

        $userId = get_current_user_id();
        $result = $this->values->save(
            $page,
            self::groupsOf($r->get_param('fields'), static fn (mixed $v): bool => is_array($v)),
            self::presentOf($r->get_param('present')),
            $userId
        );
        if ($result instanceof \WP_Error) {
            return $result;
        }

        return new \WP_REST_Response([
            'values' => self::mask($result, $this->values->readOnly($page, $userId)),
            'notices' => $this->values->notices(),
        ], 200);
    }

    /**
     * Values with every read-only field (code, or a container holding code,
     * for a user without unfiltered_html) set to null, so those values never
     * leave the server for that user. The same rule PageRenderer applies.
     *
     * @param array<string, mixed>  $values
     * @param array<string, string> $readOnly field name => reason
     * @return array<string, mixed>
     */
    private static function mask(array $values, array $readOnly): array
    {
        foreach (array_keys($readOnly) as $name) {
            if (array_key_exists($name, $values)) {
                $values[$name] = null;
            }
        }

        return $values;
    }

    private function page(\WP_REST_Request $r): ?PageDefinition
    {
        $slug = $r->get_param('slug');
        if (!is_string($slug) || preg_match(self::SLUG_RE, $slug) !== 1) {
            return null;
        }

        return $this->registry->get($slug);
    }

    private static function notFound(): \WP_Error
    {
        return new \WP_Error(
            'proto_blocks_options_page_not_found',
            __('No settings page has that slug.', 'proto-blocks'),
            ['status' => 404]
        );
    }

    /**
     * groupKey => array entries of a JSON object; anything else is dropped.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function groupsOf(mixed $raw, callable $keep): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $key => $value) {
            if (is_string($key) && $keep($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * groupKey => list of field names (strings only).
     *
     * @return array<string, list<string>>
     */
    private static function presentOf(mixed $raw): array
    {
        $out = [];
        foreach (self::groupsOf($raw, static fn (mixed $v): bool => is_array($v)) as $key => $names) {
            $out[$key] = array_values(array_filter($names, 'is_string'));
        }

        return $out;
    }
}
