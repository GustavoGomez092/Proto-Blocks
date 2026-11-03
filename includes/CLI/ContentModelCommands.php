<?php
/**
 * WP-CLI: `wp proto-blocks type` and `wp proto-blocks taxonomy`.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\CLI;

use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\DeclarationTemplate;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Guardrails;
use ProtoBlocks\ContentModel\Inflector;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\RewriteHash;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\ContentModel\Slug;
use ProtoBlocks\ContentModel\TemplateScaffolder;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Core\Plugin;
use WP_CLI;

/**
 * Manage declared post types and taxonomies.
 *
 * The static helpers hold the logic and take their collaborators as
 * arguments, so they run without WP-CLI; the public commands only wire them
 * to the live site and print.
 */
final class ContentModelCommands {

    private const LIST_COLUMNS = array( 'slug', 'name', 'origin', 'source', 'override', 'double', 'posts' );

    /**
     * `wp proto-blocks type list|create|scaffold|validate|rename`.
     *
     * @param list<string>         $args  Subcommand, then its arguments.
     * @param array<string, mixed> $assoc Flags.
     */
    public function type( array $args, array $assoc ): void {
        $this->dispatch( Declaration::TYPE, $args, $assoc );
    }

    /**
     * `wp proto-blocks taxonomy list|create|validate|rename`.
     *
     * @param list<string>         $args  Subcommand, then its arguments.
     * @param array<string, mixed> $assoc Flags.
     */
    public function taxonomy( array $args, array $assoc ): void {
        $this->dispatch( Declaration::TAXONOMY, $args, $assoc );
    }

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    private function dispatch( string $kind, array $args, array $assoc ): void {
        $sub  = (string) ( $args[0] ?? 'list' );
        $rest = array_slice( $args, 1 );

        if ( 'list' === $sub ) {
            $this->listDeclarations( $kind, $assoc );
        } elseif ( 'create' === $sub ) {
            $this->create( $kind, $rest, $assoc );
        } elseif ( 'validate' === $sub ) {
            $this->validateCommand( $kind, $rest, $assoc );
        } elseif ( 'rename' === $sub ) {
            $this->rename( $kind, $rest, $assoc );
        } elseif ( 'scaffold' === $sub && Declaration::TYPE === $kind ) {
            $this->scaffold( $rest, $assoc );
        } else {
            WP_CLI::error( 'Unknown command: ' . Commands::safeArg( $sub ) );
        }
    }

    // ---- list --------------------------------------------------------------

    /** @param array<string, mixed> $assoc */
    private function listDeclarations( string $kind, array $assoc ): void {
        $registry = Plugin::getInstance()->getContentModel();
        $rows     = self::listRows( $kind, $registry, $this->guardrails() );

        WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, self::LIST_COLUMNS );
    }

    /**
     * @return list<array{slug: string, name: string, origin: string, source: string, override: string, double: string, posts: int}>
     */
    public static function listRows( string $kind, Registry $registry, Guardrails $guardrails ): array {
        $declarations = Declaration::TYPE === $kind ? $registry->postTypes() : $registry->taxonomies();
        $overridable  = Declaration::TYPE === $kind ? Slug::OVERRIDABLE_TYPES : Slug::OVERRIDABLE_TAXONOMIES;

        $doubled = array();
        foreach ( $registry->issues() as $issue ) {
            if ( 'double_declaration' === $issue->code && $issue->kind === $kind && null !== $issue->slug ) {
                $doubled[ $issue->slug ] = true;
            }
        }

        $rows = array();
        foreach ( $declarations as $slug => $declaration ) {
            $content = $guardrails->contentFor( $kind, (string) $slug, $declaration->data );
            $rows[]  = array(
                'slug'     => (string) $slug,
                'name'     => $declaration->name() ?? '',
                'origin'   => $declaration->origin,
                'source'   => self::relativeSource( $declaration->source ),
                'override' => in_array( $slug, $overridable, true ) ? 'yes' : 'no',
                'double'   => isset( $doubled[ $slug ] ) ? 'yes' : 'no',
                'posts'    => $content['count'],
            );
        }

        return $rows;
    }

    // ---- create ------------------------------------------------------------

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    private function create( string $kind, array $args, array $assoc ): void {
        $name = trim( (string) ( $args[0] ?? '' ) );
        if ( '' === $name ) {
            WP_CLI::error( 'Usage: wp proto-blocks ' . $kind . ' create <name> [--slug=<slug>] [--plural=<plural>] [--json]' );
            return;
        }

        $slug     = isset( $assoc['slug'] ) ? (string) $assoc['slug'] : null;
        $plural   = isset( $assoc['plural'] ) ? (string) $assoc['plural'] : null;
        $registry = Plugin::getInstance()->getContentModel();

        try {
            $result = self::createDeclaration( $kind, $name, $slug, $plural, $this->writer(), $registry );
        } catch ( WriteException $e ) {
            WP_CLI::error( $e->getMessage() );
            return;
        }

        $registry->invalidate();
        if ( ! empty( $assoc['json'] ) ) {
            WP_CLI::line( (string) json_encode( $result, JSON_UNESCAPED_SLASHES ) );
            return;
        }
        WP_CLI::success( 'Created ' . $result['path'] );
    }

    /**
     * Write a new declaration file from the commented template.
     *
     * @return array{path: string}
     * @throws WriteException 'invalid_slug' (400) for a slug the declaration rule refuses, 'exists' (409) when the Registry already holds it.
     */
    public static function createDeclaration(
        string $kind,
        string $name,
        ?string $slug,
        ?string $plural,
        SchemaFileWriter $writer,
        Registry $registry
    ): array {
        $max  = Declaration::TYPE === $kind ? Slug::TYPE_MAX : Slug::TAXONOMY_MAX;
        $slug = self::normalizeSlug( $slug ?? Slug::fromName( $name, $max ), $kind );
        if ( null === $slug ) {
            throw new WriteException( 'invalid_slug', 400, __( 'That slug cannot be used: it must start with a letter, use only a-z, 0-9, "_" and "-", fit the length limit and not be a reserved word.', 'proto-blocks' ) );
        }

        if ( null !== $registry->get( $kind, $slug ) ) {
            throw new WriteException(
                'exists',
                409,
                /* translators: %s: slug */
                sprintf( __( '"%s" is already declared.', 'proto-blocks' ), $slug )
            );
        }

        $text = DeclarationTemplate::render( $kind, $name );
        if ( null !== $plural && '' !== trim( $plural ) ) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
            $text  = str_replace(
                '  // "plural": ' . json_encode( Inflector::plural( $name ), $flags ) . ',',
                '  "plural": ' . json_encode( trim( $plural ), $flags ) . ',',
                $text
            );
        }

        $written = $writer->write( $kind, $slug, $text, null, null );

        return array( 'path' => $written['path'] );
    }

    /**
     * Lowercase and trim, then accept only a slug the declaration rule allows.
     *
     * @param string $kind 'type' or 'taxonomy'
     */
    public static function normalizeSlug( string $raw, string $kind ): ?string {
        $slug = strtolower( trim( $raw ) );

        return null === Slug::error( $slug, $kind ) ? $slug : null;
    }

    // ---- scaffold ----------------------------------------------------------

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    private function scaffold( array $args, array $assoc ): void {
        $slug     = (string) ( $args[0] ?? '' );
        $registry = Plugin::getInstance()->getContentModel();
        $type     = $registry->get( Declaration::TYPE, $slug );
        if ( null === $type ) {
            WP_CLI::error( "Post type '" . Commands::safeArg( $slug ) . "' is not declared." );
            return;
        }

        $taxonomies = array();
        foreach ( $registry->taxonomies() as $taxSlug => $taxonomy ) {
            $postTypes = is_array( $taxonomy->data['post_types'] ?? null ) ? $taxonomy->data['post_types'] : array();
            if ( in_array( $slug, $postTypes, true ) ) {
                $taxonomies[] = (string) $taxSlug;
            }
        }

        $scaffolder = new TemplateScaffolder(
            get_stylesheet_directory(),
            get_template_directory(),
            (bool) wp_is_block_theme(),
            ! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS
        );

        try {
            $result = $scaffolder->scaffold( $slug, self::scaffoldArchive( $type->data, get_post_type_object( $slug ) ), $taxonomies, ! empty( $assoc['taxonomies'] ) );
        } catch ( WriteException $e ) {
            WP_CLI::error( $e->getMessage() );
            return;
        }

        foreach ( $result['written'] as $name ) {
            WP_CLI::log( 'written: templates/' . $name );
        }
        foreach ( $result['skipped'] as $name ) {
            WP_CLI::log( 'skipped (exists): templates/' . $name );
        }
        WP_CLI::success( sprintf( '%d written, %d skipped.', count( $result['written'] ), count( $result['skipped'] ) ) );
    }

    /**
     * Whether scaffolding writes an archive template: the registered type's
     * effective `has_archive` (so an override of `post` without the key
     * writes none), else the declared key, else the plugin default (true).
     *
     * @param array<string, mixed> $data       The declaration's data.
     * @param object|null          $registered The registered post type object, when there is one.
     */
    public static function scaffoldArchive( array $data, ?object $registered ): bool {
        if ( null !== $registered && property_exists( $registered, 'has_archive' ) ) {
            return ! empty( $registered->has_archive );
        }

        return array_key_exists( 'has_archive', $data ) ? ! empty( $data['has_archive'] ) : true;
    }

    // ---- validate ----------------------------------------------------------

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    private function validateCommand( string $kind, array $args, array $assoc ): void {
        $plugin = Plugin::getInstance();
        $rows   = self::validationRows( $plugin->getContentModel(), $plugin->getModules()->issues() );

        $only = isset( $args[0] ) ? (string) $args[0] : null;
        $rows = array_values(
            array_filter(
                $rows,
                static fn ( array $row ): bool => ( '' === $row['kind'] || $row['kind'] === $kind )
                    && ( null === $only || $row['slug'] === $only )
            )
        );

        self::printValidation( $rows, (string) ( $assoc['format'] ?? 'table' ) );
    }

    /**
     * Print rows and fail when any is an error.
     *
     * @param list<array{kind: string, slug: string, level: string, code: string, message: string, source: string}> $rows
     */
    public static function printValidation( array $rows, string $format = 'table' ): void {
        if ( 'json' === $format ) {
            WP_CLI::line( (string) json_encode( $rows, JSON_UNESCAPED_SLASHES ) );
        } else {
            self::printRows( $rows, $format );
        }

        if ( self::hasErrors( $rows ) ) {
            WP_CLI::error( 'Validation failed for one or more declarations.' );
            return;
        }
        WP_CLI::success( 'All post type and taxonomy declarations are valid.' );
    }

    /**
     * Print the rows as a table (or csv, yaml), nothing for an empty report.
     *
     * @param list<array{kind: string, slug: string, level: string, code: string, message: string, source: string}> $rows
     */
    public static function printRows( array $rows, string $format = 'table' ): void {
        if ( array() !== $rows ) {
            WP_CLI\Utils\format_items( $format, $rows, array( 'kind', 'slug', 'level', 'code', 'message', 'source' ) );
        }
    }

    /** @param list<array{level: string}> $rows */
    public static function hasErrors( array $rows ): bool {
        foreach ( $rows as $row ) {
            if ( Issue::ERROR === $row['level'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * One row per distinct issue of the Registry and the modules. Double
     * declarations arrive as the Registry's `double_declaration` warning,
     * which names both locations. Every loaded declaration also goes through
     * the `proto_blocks_declaration_errors` filter (the checks the writer runs
     * beyond the schema); each error it returns is a `declaration_invalid` row.
     *
     * @param list<Issue> $moduleIssues
     * @return list<array{kind: string, slug: string, level: string, code: string, message: string, source: string}>
     */
    public static function validationRows( Registry $registry, array $moduleIssues ): array {
        $rows = array();
        foreach ( array( ...$registry->issues(), ...self::declarationIssues( $registry ), ...$moduleIssues ) as $issue ) {
            $row = array(
                'kind'    => $issue->kind ?? '',
                'slug'    => $issue->slug ?? '',
                'level'   => $issue->level,
                'code'    => $issue->code,
                'message' => $issue->message,
                'source'  => self::relativeSource( $issue->source ),
            );

            $rows[ implode( "\0", $row ) ] ??= $row;
        }

        return array_values( $rows );
    }

    /**
     * `proto_blocks_declaration_errors` findings for every standalone declaration in $registry.
     *
     * @return list<Issue>
     */
    private static function declarationIssues( Registry $registry ): array {
        $issues = array();
        foreach ( array_keys( FileLoader::FOLDERS ) as $kind ) {
            foreach ( $registry->all( $kind ) as $slug => $declaration ) {
                if ( null !== $declaration->parentType ) {
                    continue; // An inline taxonomy is checked with its type's data.
                }
                foreach ( SchemaFileWriter::declarationErrors( $kind, $declaration->data, (string) $slug ) as $error ) {
                    $issues[] = new Issue(
                        Issue::ERROR,
                        'declaration_invalid',
                        ( '' === $error['path'] ? '/' : $error['path'] ) . ': ' . $error['message'],
                        $declaration->source,
                        (string) $slug,
                        $kind
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * A source path as shown to the developer: from the declaration folder
     * (`proto-types/...`) or from the WordPress root, never the full machine path.
     */
    public static function relativeSource( string $source ): string {
        $normalized = str_replace( '\\', '/', $source );
        foreach ( FileLoader::FOLDERS as $folder ) {
            $at = strrpos( $normalized, '/' . $folder . '/' );
            if ( false !== $at ) {
                return substr( $normalized, $at + 1 );
            }
        }
        if ( defined( 'ABSPATH' ) ) {
            $base = rtrim( str_replace( '\\', '/', (string) ABSPATH ), '/' ) . '/';
            if ( str_starts_with( $normalized, $base ) ) {
                return substr( $normalized, strlen( $base ) );
            }
        }

        return $source;
    }

    // ---- rename ------------------------------------------------------------

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    private function rename( string $kind, array $args, array $assoc ): void {
        $old    = (string) ( $args[0] ?? '' );
        $rawNew = (string) ( $args[1] ?? '' );
        if ( '' === $old || '' === $rawNew ) {
            WP_CLI::error( 'Usage: wp proto-blocks ' . $kind . ' rename <old> <new> [--yes] [--migrate]' );
            return;
        }

        global $wpdb;

        self::runRename( $kind, $old, $rawNew, $assoc, Plugin::getInstance()->getContentModel(), $this->writer(), $this->guardrails(), $wpdb );
    }

    /**
     * The whole rename flow, with its collaborators passed in.
     *
     * @param array<string, mixed> $assoc
     * @param object               $wpdb  Needs `update()`, `posts` and `term_taxonomy`.
     */
    public static function runRename(
        string $kind,
        string $old,
        string $rawNew,
        array $assoc,
        Registry $registry,
        SchemaFileWriter $writer,
        Guardrails $guardrails,
        object $wpdb
    ): void {
        $new = self::normalizeSlug( $rawNew, $kind );
        if ( null === $new ) {
            WP_CLI::error( "'" . Commands::safeArg( $rawNew ) . "' is not a valid slug." );
            return;
        }

        $existing = $registry->get( $kind, $old );
        $problem  = self::renameProblem( $kind, $old, $new, $existing, $registry );
        if ( null !== $problem || null === $existing ) {
            WP_CLI::error( $problem ?? 'Cannot rename.' );
            return;
        }

        $unit    = Declaration::TYPE === $kind ? 'posts' : 'terms';
        $count   = $guardrails->contentFor( $kind, $old, $existing->data )['count'];
        $migrate = ! empty( $assoc['migrate'] );

        if ( empty( $assoc['yes'] ) ) {
            WP_CLI::log( sprintf( 'Plan: move %s to %s/%s/ (%d %s affected).', self::relativeSource( $existing->source ), FileLoader::FOLDERS[ $kind ], $new, $count, $unit ) );
            WP_CLI::log(
                $migrate
                    ? sprintf( 'Existing %s would move to "%s".', $unit, $new )
                    : sprintf( 'Existing %s would stay under "%s"; add --migrate to move them.', $unit, $old )
            );
            WP_CLI::error( 'Nothing changed. Run again with --yes to apply.' );
            return;
        }

        // Checked before anything moves, so a refusal leaves the site untouched.
        if ( $migrate ) {
            $taken = $guardrails->contentFor( $kind, $new, null )['count'];
            if ( $taken > 0 ) {
                WP_CLI::error( sprintf( '%d %s already exist under "%s"; migrating would mix them. Nothing changed.', $taken, $unit, $new ) );
                return;
            }
        }

        try {
            $moved = self::moveDeclaration( $kind, $old, $new, $existing, $writer );
        } catch ( WriteException $e ) {
            WP_CLI::error( $e->getMessage() );
            return;
        }
        $registry->invalidate();
        WP_CLI::log( 'Moved to ' . self::relativeSource( $moved['path'] ) );
        foreach ( $moved['kept'] as $kept ) {
            WP_CLI::warning( 'Left in the old folder: ' . $kept );
        }

        if ( $migrate ) {
            $affected = self::migrateContent( $kind, $old, $new, $wpdb );
            if ( null === $affected ) {
                WP_CLI::error( sprintf( 'The database update failed. The declaration is already renamed to "%s", but its %s are still stored under "%s". Fix the database error and update them yourself, or rename the folder back.', $new, $unit, $old ) );
                return;
            }
            // The next web request sees a different declared set and flushes.
            if ( function_exists( 'delete_option' ) ) {
                delete_option( RewriteHash::OPTION );
            }
            WP_CLI::log( sprintf( '%d %s moved to "%s". Rewrite rules will refresh on the next page load.', $affected, $unit, $new ) );
        } elseif ( $count > 0 ) {
            WP_CLI::warning( sprintf( '%d existing %s stay under the old slug "%s" and are not shown under the new one. Move them yourself, or rename with --migrate.', $count, $unit, $old ) );
        }
        WP_CLI::success( sprintf( 'Renamed "%s" to "%s".', $old, $new ) );
    }

    /**
     * Why a rename cannot proceed, or null when it can.
     */
    public static function renameProblem( string $kind, string $old, string $new, ?Declaration $existing, Registry $registry ): ?string {
        if ( null === $existing ) {
            return "'" . Commands::safeArg( $old ) . "' is not declared.";
        }
        if ( Declaration::ORIGIN_FILE !== $existing->origin ) {
            return 'This declaration is made in PHP; rename it in the theme code.';
        }
        if ( null !== $existing->parentType ) {
            return 'This taxonomy is declared inside a post type; rename it there.';
        }
        if ( $old === $new ) {
            return 'The new slug is the same as the old one.';
        }
        if ( null !== $registry->get( $kind, $new ) ) {
            return "'" . $new . "' is already declared.";
        }

        return null;
    }

    /**
     * Move a declaration file to the folder of its new slug: write the new
     * file (all writer checks apply), then remove the old one.
     *
     * @return array{path: string, kept: list<string>}
     * @throws WriteException
     */
    public static function moveDeclaration( string $kind, string $old, string $new, Declaration $existing, SchemaFileWriter $writer ): array {
        $text = is_file( $existing->source ) ? file_get_contents( $existing->source ) : false;
        if ( false === $text ) {
            throw new WriteException( 'not_found', 404, 'The declaration file could not be read.' );
        }

        $written = $writer->write( $kind, $new, $text, null, null );
        try {
            $deleted = $writer->delete( $kind, $old, $existing );
        } catch ( WriteException $e ) {
            @unlink( $written['path'] );
            @rmdir( dirname( $written['path'] ) );
            throw $e;
        }

        return array(
            'path' => $written['path'],
            'kept' => $deleted['kept'],
        );
    }

    /**
     * Move stored content to the new slug.
     *
     * @param object $wpdb
     * @return int|null Rows moved, or null when the database update failed.
     */
    public static function migrateContent( string $kind, string $old, string $new, object $wpdb ): ?int {
        if ( Declaration::TYPE === $kind ) {
            $affected = $wpdb->update( $wpdb->posts, array( 'post_type' => $new ), array( 'post_type' => $old ) );
        } else {
            $affected = $wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $new ), array( 'taxonomy' => $old ) );
        }
        if ( false === $affected ) {
            return null;
        }

        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        if ( Declaration::TAXONOMY === $kind && function_exists( 'clean_taxonomy_cache' ) ) {
            clean_taxonomy_cache( $old );
            clean_taxonomy_cache( $new );
        }

        return (int) $affected;
    }

    // ---- wiring ------------------------------------------------------------

    private function writer(): SchemaFileWriter {
        return new SchemaFileWriter( SchemaPaths::writableRoots(), ! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS );
    }

    private function guardrails(): Guardrails {
        global $wpdb;

        return new Guardrails(
            static fn ( string $type ): int => (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $type )
            ),
            static fn ( string $taxonomy ): int => (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy )
            )
        );
    }
}
