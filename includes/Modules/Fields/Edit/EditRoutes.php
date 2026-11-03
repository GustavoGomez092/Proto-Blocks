<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Edit;

use ProtoBlocks\API\RestAPI;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use WP_Error;
use WP_REST_Request;

/**
 * Edit-time REST routes for the field boxes. Both are reads behind the REST
 * cookie authentication (core checks the `wp_rest` nonce); no route here
 * writes field values: values are saved by the post, term and user forms
 * (SaveHandler) and by core's meta endpoints under the auth callbacks.
 *
 * - GET proto-blocks/v1/fields/errors?object=post:<id>|term:<id>|user:<id>|option:<page>
 *   The errors the current user's last save left for that object (read
 *   once). Permission: the object's edit capability (manage_options for an
 *   options page); 403 `proto_blocks_forbidden` otherwise.
 *
 * - GET proto-blocks/v1/fields/relationship?group=<key>&field=<name|key>&search=&page=1
 *   → {items: [{id, title, type, status}], page, hasMore}, 20 per page.
 *   Filters come from the field's declaration only (its `post_type` list;
 *   its `taxonomy` filter as `taxonomy:slug` entries); request parameters
 *   other than group, field, search and page are ignored. A declared type is
 *   kept when registered with show_ui AND either publicly viewable or
 *   editable by the user (its `edit_posts` capability); with no type
 *   declared, the viewable show_ui types are searched. Only published posts
 *   and private posts the user may read are queried (`perm => readable`),
 *   and each result is checked again: publicly viewable, or `read_post` for
 *   the user. There is no total: a count taken before that check could
 *   reveal posts the user may not read, so `hasMore` says whether another
 *   page exists. Titles are RAW (get_the_title(), may contain markup or
 *   entities); the client renders them as text nodes, never as HTML.
 *   Permission: edit_posts.
 */
final class EditRoutes
{
    public const PER_PAGE = 20;
    public const MAX_SEARCH = 100;
    public const MAX_PAGE = 1000;

    private const OBJECT_PATTERN = '/^(post|term|user):([1-9][0-9]{0,18})\z|^option:([a-z0-9][a-z0-9_-]{0,63})\z/D';

    private \Closure $query;

    private \Closure $pageCapability;

    /**
     * @param (callable(array<string, mixed>): array{posts: list<object>, total: int, pages: int})|null $query
     *        runs the relationship query (default: WP_Query); injectable for tests
     * @param (callable(string): ?string)|null $pageCapability options page slug => its declared
     *        capability, null when unknown (default: the options pages registry)
     */
    public function __construct(
        private readonly GroupIndex $index,
        private readonly ErrorStore $errors,
        ?callable $query = null,
        ?callable $pageCapability = null,
    ) {
        $this->pageCapability = $pageCapability !== null ? \Closure::fromCallable($pageCapability) : \Closure::fromCallable([self::class, 'declaredCapability']);
        $this->query = $query !== null ? \Closure::fromCallable($query) : \Closure::fromCallable([self::class, 'wpQuery']);
    }

    /** rest_api_init. */
    public function registerRoutes(): void
    {
        register_rest_route(RestAPI::NAMESPACE, '/fields/errors', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'errors'],
                'permission_callback' => [$this, 'canReadErrors'],
                'args' => [
                    'object' => ['type' => 'string', 'required' => true],
                ],
            ],
        ]);
        register_rest_route(RestAPI::NAMESPACE, '/fields/relationship', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'relationship'],
                'permission_callback' => [$this, 'canSearch'],
                'args' => [
                    'group' => ['type' => 'string', 'required' => true],
                    'field' => ['type' => 'string', 'required' => true],
                    'search' => ['type' => 'string', 'default' => ''],
                    'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                ],
            ],
        ]);
    }

    /** @return true|WP_Error */
    public function canReadErrors(WP_REST_Request $request)
    {
        $ref = self::objectRef($request->get_param('object'));
        if ($ref === null) {
            return self::forbidden();
        }
        $allowed = match ($ref->kind) {
            ObjectRef::POST => current_user_can('edit_post', $ref->id),
            ObjectRef::TERM => current_user_can('edit_term', $ref->id),
            ObjectRef::USER => current_user_can('edit_user', $ref->id),
            ObjectRef::OPTION => current_user_can(($this->pageCapability)($ref->page) ?? 'manage_options'),
            default => false,
        };

        return $allowed ? true : self::forbidden();
    }

    /** The options page's declared capability; null when the page or the module is unavailable. */
    private static function declaredCapability(string $slug): ?string
    {
        if (!class_exists(\ProtoBlocks\Core\Plugin::class)) {
            return null;
        }
        try {
            $page = \ProtoBlocks\Core\Plugin::getInstance()->getModules()->optionsPages()?->registry()->get($slug);
        } catch (\Throwable) {
            return null;
        }

        return $page !== null && $page->capability !== '' ? $page->capability : null;
    }

    /** @return array{errors: array<string, string>, submitted: array<string, mixed>}|WP_Error */
    public function errors(WP_REST_Request $request)
    {
        $ref = self::objectRef($request->get_param('object'));
        if ($ref === null) {
            return self::forbidden();
        }
        $user = get_current_user_id();
        $entry = $this->errors->peek($ref, $user) !== null ? $this->errors->take($ref, $user) : null;

        return $entry ?? ['errors' => [], 'submitted' => []];
    }

    /** @return true|WP_Error */
    public function canSearch(WP_REST_Request $request)
    {
        return current_user_can('edit_posts') ? true : self::forbidden();
    }

    /** @return array{items: list<array{id: int, title: string, type: string, status: string}>, page: int, hasMore: bool}|WP_Error */
    public function relationship(WP_REST_Request $request)
    {
        $field = $this->relationshipField($request->get_param('group'), $request->get_param('field'));
        if ($field === null) {
            return new WP_Error('proto_blocks_not_found', __('No relationship field matches this request.', 'proto-blocks'), ['status' => 404]);
        }

        $page = $request->get_param('page');
        $page = is_numeric($page) ? max(1, min(self::MAX_PAGE, (int) $page)) : 1;
        $types = self::postTypes($field);
        if ($types === []) {
            return ['items' => [], 'page' => $page, 'hasMore' => false];
        }
        $search = $request->get_param('search');
        $search = is_string($search) ? mb_substr(sanitize_text_field($search), 0, self::MAX_SEARCH) : '';

        $args = [
            'post_type' => $types,
            'post_status' => ['publish', 'private'],
            'perm' => 'readable',
            'posts_per_page' => self::PER_PAGE,
            'paged' => $page,
            'orderby' => $search !== '' ? 'relevance' : 'title',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
        ];
        if ($search !== '') {
            $args['s'] = $search;
        }
        $taxQuery = self::taxQuery($field);
        if ($taxQuery !== []) {
            $args['tax_query'] = $taxQuery;
        }

        $result = ($this->query)($args);
        $items = [];
        foreach ($result['posts'] ?? [] as $post) {
            if (!is_object($post) || !isset($post->ID)) {
                continue;
            }
            $id = (int) $post->ID;
            $type = (string) ($post->post_type ?? '');
            if (!in_array($type, $types, true) || (!is_post_publicly_viewable($post) && !current_user_can('read_post', $id))) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'title' => (string) get_the_title($post), // raw: rendered as text by the client
                'type' => $type,
                'status' => (string) ($post->post_status ?? ''),
            ];
        }

        return ['items' => $items, 'page' => $page, 'hasMore' => $page < (int) ($result['pages'] ?? 0)];
    }

    /**
     * The default query runner.
     *
     * @param array<string, mixed> $args
     * @return array{posts: list<object>, total: int, pages: int}
     */
    public static function wpQuery(array $args): array
    {
        $query = new \WP_Query($args);

        return ['posts' => array_values(array_filter((array) $query->posts, 'is_object')), 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages];
    }

    private static function objectRef(mixed $object): ?ObjectRef
    {
        if (!is_string($object) || preg_match(self::OBJECT_PATTERN, $object, $m) !== 1) {
            return null;
        }
        if (($m[3] ?? '') !== '') {
            return ObjectRef::option($m[3]);
        }
        $id = (int) $m[2];

        switch ($m[1]) {
            case 'post':
                $ref = ObjectRef::post($id);
                return $ref->subtype !== '' ? $ref : null;
            case 'term':
                $term = get_term($id);
                return is_object($term) && isset($term->taxonomy) ? ObjectRef::term($id, (string) $term->taxonomy) : null;
            case 'user':
                return ObjectRef::user($id);
        }

        return null;
    }

    /** A relationship field of the group, by key or by name at any depth. */
    private function relationshipField(mixed $groupKey, mixed $name): ?Field
    {
        if (!is_string($groupKey) || $groupKey === '' || !is_string($name) || $name === '') {
            return null;
        }
        foreach ($this->index->all() as $group) {
            if ($group->key !== $groupKey) {
                continue;
            }
            $field = $group->fieldByKey($name) ?? self::byName($group->fields, $name);

            return $field !== null && $field->type === 'relationship' ? $field : null;
        }

        return null;
    }

    /** @param list<Field> $fields */
    private static function byName(array $fields, string $name): ?Field
    {
        foreach ($fields as $f) {
            if ($f->name === $name && $f->type === 'relationship') {
                return $f;
            }
        }
        foreach ($fields as $f) {
            $found = self::byName($f->subFields, $name);
            foreach ($f->layouts as $layout) {
                $found ??= self::byName($layout['subFields'], $name);
            }
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The declared post types registered with show_ui that are publicly
     * viewable or editable by the user; with none declared, the viewable
     * show_ui types only (a private type such as shop coupons is never
     * searched by default).
     *
     * @return list<string>
     */
    private static function postTypes(Field $field): array
    {
        $declared = $field->setting('post_type', []);
        $declared = is_string($declared) ? [$declared] : (is_array($declared) ? $declared : []);
        $declared = array_values(array_filter($declared, static fn($t): bool => is_string($t) && $t !== ''));
        $fallback = $declared === [];
        if ($fallback) {
            $declared = array_values(array_map('strval', (array) get_post_types(['show_ui' => true])));
        }

        $out = [];
        foreach ($declared as $type) {
            $object = get_post_type_object($type);
            if (!is_object($object) || empty($object->show_ui)) {
                continue;
            }
            $viewable = is_post_type_viewable($object);
            $cap = is_object($object->cap ?? null) ? ($object->cap->edit_posts ?? null) : null;
            $editable = !$fallback && is_string($cap) && $cap !== '' && current_user_can($cap);
            if ($viewable || $editable) {
                $out[$type] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * ACF's `taxonomy` filter: `taxonomy:slug` entries of registered taxonomies, OR'd.
     *
     * @return array<int|string, mixed>
     */
    private static function taxQuery(Field $field): array
    {
        $entries = $field->setting('taxonomy', []);
        $entries = is_string($entries) ? [$entries] : (is_array($entries) ? $entries : []);
        $byTaxonomy = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || preg_match('/^([a-z0-9_-]{1,32}):([^\s:]{1,200})\z/D', $entry, $m) !== 1) {
                continue;
            }
            if (taxonomy_exists($m[1])) {
                $byTaxonomy[$m[1]][] = $m[2];
            }
        }
        if ($byTaxonomy === []) {
            return [];
        }
        $query = ['relation' => 'OR'];
        foreach ($byTaxonomy as $taxonomy => $slugs) {
            $query[] = ['taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => array_values(array_unique($slugs))];
        }

        return $query;
    }

    private static function forbidden(): WP_Error
    {
        return new WP_Error('proto_blocks_forbidden', __('Sorry, you are not allowed to do that.', 'proto-blocks'), ['status' => 403]);
    }
}
