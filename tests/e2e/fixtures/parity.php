<?php
/**
 * In-WordPress parity check (Phase 1 done criterion), run inside wp-env:
 *
 *     npx wp-env run tests-cli wp eval-file wp-content/pb-e2e-fixtures/parity.php
 *
 * For each recon fixture (tests/php/fixtures/content-model/recon, the live
 * sites' hand-written register_post_type calls) it registers the original
 * args, snapshots the registered WP_Post_Type, unregisters it, registers the
 * one-line declaration built by PostTypeArgs::build(), snapshots again and
 * compares. Labels are compared on the original's keys, modulo a terminal
 * ".". Prints {"<slug>": {"equal": bool, "diff": {...}}} and exits 1 on any
 * difference.
 *
 * @package ProtoBlocks
 */

use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\PostTypeArgs;

$pb_recon = PROTO_BLOCKS_DIR . 'tests/php/fixtures/content-model/recon/';

// The one-line declarations, exactly as tests/php/ContentModel/ParityTest.php writes them.
$pb_cases = [
    [
        'fixture' => 'cadco-resource.php',
        'name'    => 'Resource',
        'args'    => ['has_archive' => false, 'menu_icon' => 'dashicons-media-document', 'rewrite' => ['slug' => 'resources']],
    ],
    [
        'fixture' => 'oit-resource.php',
        'name'    => 'Resource',
        'args'    => [
            'slug'          => 'oit_resource',
            'has_archive'   => 'resources',
            'rewrite'       => ['slug' => 'resources'],
            'menu_icon'     => 'dashicons-portfolio',
            'supports'      => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields'],
            'template'      => [
                ['proto-blocks/oit-breadcrumbs-eyebrow-title', []],
                ['proto-blocks/oit-video-preview', []],
                ['core/spacer', ['height' => '48px']],
                ['proto-blocks/oit-text-image', []],
                ['core/spacer', ['height' => '48px']],
                ['proto-blocks/oit-form', []],
                ['core/spacer', ['height' => '48px']],
                ['proto-blocks/oit-related-resources', []],
                ['core/spacer', ['height' => '80px']],
                ['proto-blocks/oit-link-cards', []],
            ],
            'template_lock' => false,
            'labels'        => [
                'featured_image'        => 'Cover Image',
                'set_featured_image'    => 'Set cover image',
                'remove_featured_image' => 'Remove cover image',
                'use_featured_image'    => 'Use as cover image',
            ],
        ],
    ],
];

/**
 * The registered object as plain data: object props minus `labels` and `cap`
 * (compared separately / derived), plus the supports WordPress moved into
 * its own registry, sorted.
 *
 * @return array{props: array<string, mixed>, labels: array<string, string>}
 */
$pb_snapshot = static function (string $slug): array {
    $object = get_post_type_object($slug);
    if (!$object instanceof WP_Post_Type) {
        return ['props' => ['registered' => false], 'labels' => []];
    }

    $props = json_decode((string) wp_json_encode(get_object_vars($object)), true);
    $labels = is_array($props['labels'] ?? null) ? $props['labels'] : [];
    unset($props['labels'], $props['cap']);

    $supports = array_keys(get_all_post_type_supports($slug));
    sort($supports);
    $props['supports'] = $supports;
    ksort($props);

    return ['props' => $props, 'labels' => $labels];
};

$pb_report = [];
$pb_failed = false;

foreach ($pb_cases as $pb_case) {
    $pb_fixture = require $pb_recon . $pb_case['fixture'];
    $pb_slug = $pb_fixture['slug'];

    if (post_type_exists($pb_slug)) {
        unregister_post_type($pb_slug);
    }

    register_post_type($pb_slug, $pb_fixture['args']);
    $pb_original = $pb_snapshot($pb_slug);
    unregister_post_type($pb_slug);

    $pb_declaration = new Declaration(
        Declaration::TYPE,
        $pb_slug,
        ['name' => $pb_case['name']] + $pb_case['args'],
        Declaration::ORIGIN_PHP,
        __FILE__ . ':' . __LINE__,
        null
    );
    $pb_built = PostTypeArgs::build($pb_declaration, get_stylesheet_directory(), get_stylesheet_directory_uri());

    $pb_diff = [];
    foreach ($pb_built['issues'] as $pb_issue) {
        $pb_diff['issues'][] = $pb_issue->code . ': ' . $pb_issue->message;
    }

    if ($pb_built['args'] !== []) {
        register_post_type($pb_slug, $pb_built['args']);
    }
    $pb_declared = $pb_snapshot($pb_slug);
    unregister_post_type($pb_slug);

    $pb_keys = array_unique(array_merge(array_keys($pb_original['props']), array_keys($pb_declared['props'])));
    foreach ($pb_keys as $pb_key) {
        $pb_a = $pb_original['props'][$pb_key] ?? null;
        $pb_b = $pb_declared['props'][$pb_key] ?? null;
        if ($pb_a !== $pb_b) {
            $pb_diff['props'][$pb_key] = ['original' => $pb_a, 'declared' => $pb_b];
        }
    }

    foreach ($pb_fixture['args']['labels'] as $pb_key => $pb_label) {
        $pb_got = $pb_declared['labels'][$pb_key] ?? null;
        if (!is_string($pb_got) || rtrim($pb_label, '.') !== rtrim($pb_got, '.')) {
            $pb_diff['labels'][$pb_key] = ['original' => $pb_label, 'declared' => $pb_got];
        }
    }

    $pb_equal = $pb_diff === [];
    $pb_failed = $pb_failed || !$pb_equal;
    $pb_report[$pb_slug] = ['equal' => $pb_equal, 'diff' => (object) $pb_diff];
}

echo wp_json_encode($pb_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

if ($pb_failed) {
    exit(1);
}
