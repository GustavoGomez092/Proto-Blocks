<?php
/**
 * WP-CLI Commands for Proto-Blocks
 *
 * @package ProtoBlocks
 */

namespace ProtoBlocks\CLI;

use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Schema\SchemaValidator;
use WP_CLI;
use WP_CLI_Command;

/**
 * Manage Proto-Blocks from the command line.
 */
class Commands extends WP_CLI_Command {
    /**
     * List all registered Proto-Blocks.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks list
     *     wp proto-blocks list --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function list( array $args, array $assoc_args ): void {
        $plugin    = Plugin::getInstance();
        $registrar = $plugin->getRegistrar();

        if ( ! $registrar ) {
            WP_CLI::error( 'Proto-Blocks is not properly initialized.' );
            return;
        }

        $blocks_data = $registrar->getBlocksData();

        if ( empty( $blocks_data ) ) {
            WP_CLI::warning( 'No Proto-Blocks registered.' );
            return;
        }

        $items = array_map(
            function ( $block ) {
                return [
                    'name'        => 'proto-blocks/' . $block['name'],
                    'title'       => $block['title'],
                    'category'    => $block['category'],
                    'description' => $block['description'] ?? '',
                ];
            },
            $blocks_data
        );

        $format = $assoc_args['format'] ?? 'table';
        WP_CLI\Utils\format_items( $format, $items, [ 'name', 'title', 'category', 'description' ] );
    }

    /**
     * Create a new Proto-Block scaffold.
     *
     * ## OPTIONS
     *
     * <name>
     * : The block name (slug format, e.g., 'card' or 'testimonial').
     *
     * [--title=<title>]
     * : The block title.
     *
     * [--description=<description>]
     * : The block description.
     *
     * [--category=<category>]
     * : The block category.
     * ---
     * default: proto-blocks
     * ---
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields in format name:type (e.g., "title:text,content:wysiwyg,image:image").
     *
     * [--dir=<directory>]
     * : Directory to create the block in.
     * ---
     * default: theme
     * options:
     *   - theme
     *   - plugin
     * ---
     *
     * [--force]
     * : Overwrite existing files.
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks create card --title="Card Block"
     *     wp proto-blocks create testimonial --fields="quote:wysiwyg,author:text,image:image"
     *     wp proto-blocks create hero --title="Hero Section" --category="layout"
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function create( array $args, array $assoc_args ): void {
        $raw_name = (string) ( $args[0] ?? '' );
        $name     = self::normalizeBlockName( $raw_name );

        if ( null === $name ) {
            WP_CLI::error(
                "Invalid block name '" . self::safeArg( $raw_name ) . "'. "
                . 'Use lowercase letters, digits and dashes, starting with a letter (e.g. "feature-card").'
            );
            return;
        }

        // Determine target directory
        $dir_type = $assoc_args['dir'] ?? 'theme';
        if ( 'theme' === $dir_type ) {
            $base_dir = get_stylesheet_directory() . '/proto-blocks';
        } else {
            $base_dir = WP_PLUGIN_DIR . '/proto-blocks-custom';
        }

        $block_dir = $base_dir . '/' . $name;

        // Check if block already exists
        if ( is_dir( $block_dir ) && empty( $assoc_args['force'] ) ) {
            WP_CLI::error( "Block '{$name}' already exists. Use --force to overwrite." );
            return;
        }

        // Create directory
        if ( ! wp_mkdir_p( $block_dir ) ) {
            WP_CLI::error( "Failed to create directory: {$block_dir}" );
            return;
        }

        // Parse fields
        $fields = [];
        if ( ! empty( $assoc_args['fields'] ) ) {
            try {
                $fields = self::parseFields( (string) $assoc_args['fields'] );
            } catch ( \InvalidArgumentException $e ) {
                WP_CLI::error( self::safeArg( $e->getMessage() ) );
                return;
            }
        }

        // Default fields if none specified
        if ( empty( $fields ) ) {
            $fields = [
                'title'   => [ 'type' => 'text', 'tagName' => 'h2' ],
                'content' => [ 'type' => 'wysiwyg' ],
            ];
        }

        // Build block.json
        $title       = $assoc_args['title'] ?? ucwords( str_replace( '-', ' ', $name ) );
        $description = $assoc_args['description'] ?? "A {$title} block.";
        $category    = $assoc_args['category'] ?? 'proto-blocks';

        $block_json = [
            '$schema'     => 'https://schemas.wp.org/trunk/block.json',
            'apiVersion'  => 3,
            'name'        => "proto-blocks/{$name}",
            'title'       => $title,
            'description' => $description,
            'category'    => $category,
            'icon'        => 'block-default',
            'keywords'    => [ $name ],
            'supports'    => [
                'html'            => false,
                'anchor'          => true,
                'customClassName' => true,
                'align'           => [ 'wide', 'full' ],
                'color'           => [
                    'background' => true,
                    'text'       => true,
                ],
                'spacing'         => [
                    'padding' => true,
                    'margin'  => true,
                ],
            ],
            'protoBlocks' => [
                'version'  => '1.0',
                'template' => 'template.php',
                'fields'   => $fields,
            ],
        ];

        // Write block.json
        $json_file = $block_dir . '/block.json';
        $json_content = wp_json_encode( $block_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! file_put_contents( $json_file, $json_content ) ) {
            WP_CLI::error( "Failed to write block.json" );
            return;
        }

        // Generate template.php
        $template_content = $this->generateTemplate( $name, $fields );
        $template_file = $block_dir . '/template.php';
        if ( ! file_put_contents( $template_file, $template_content ) ) {
            WP_CLI::error( "Failed to write template.php" );
            return;
        }

        // Generate style.css
        $style_content = $this->generateStyles( $name, $fields );
        $style_file = $block_dir . '/style.css';
        file_put_contents( $style_file, $style_content );

        WP_CLI::success( "Block '{$name}' created at: {$block_dir}" );
        WP_CLI::log( "Files created:" );
        WP_CLI::log( "  - block.json" );
        WP_CLI::log( "  - template.php" );
        WP_CLI::log( "  - style.css" );
    }

    /**
     * Validate all Proto-Blocks.
     *
     * ## OPTIONS
     *
     * [<name>]
     * : Specific block name to validate.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks validate
     *     wp proto-blocks validate card
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */

    /**
     * Validate the block in one directory and describe the outcome.
     *
     * Split out of validate() so it can be tested without WP-CLI: the command
     * itself needs the plugin singleton and WP_CLI's output helpers, while the
     * decision it makes per block needs neither.
     *
     * SchemaValidator does not return a result object -- validate() returns
     * true and THROWS on error, keeping the messages on the validator itself.
     * Reading it as a result object is what made this command fatal on its
     * first block with "Call to a member function isValid() on bool".
     *
     * @param string          $block_path Directory holding the block.
     * @param SchemaValidator $validator  Validator to run the schema through.
     * @return array{block: string, status: string, message: string}
     */
    public static function validateBlockAt( string $block_path, SchemaValidator $validator ): array {
        $name      = basename( $block_path );
        $json_file = $block_path . '/block.json';

        if ( ! file_exists( $json_file ) ) {
            return [
                'block'   => $name,
                'status'  => 'error',
                'message' => 'Missing block.json',
            ];
        }

        $schema = json_decode( (string) file_get_contents( $json_file ), true );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return [
                'block'   => $name,
                'status'  => 'error',
                'message' => 'Invalid JSON: ' . json_last_error_msg(),
            ];
        }

        try {
            $validator->validate( (array) $schema, $json_file );
        } catch ( \InvalidArgumentException $e ) {
            // The validator collects every error before throwing, so prefer
            // that list; the exception message is the fallback if it is empty.
            $errors = $validator->getErrors();

            return [
                'block'   => $name,
                'status'  => 'error',
                'message' => $errors ? implode( '; ', $errors ) : $e->getMessage(),
            ];
        }

        $warnings = $validator->getWarnings();

        if ( ! empty( $warnings ) ) {
            return [
                'block'   => $name,
                'status'  => 'warning',
                'message' => implode( '; ', $warnings ),
            ];
        }

        return [
            'block'   => $name,
            'status'  => 'valid',
            'message' => 'OK',
        ];
    }

    public function validate( array $args, array $assoc_args ): void {
        $plugin    = Plugin::getInstance();
        $discovery = $plugin->getDiscovery();

        if ( ! $discovery ) {
            WP_CLI::error( 'Proto-Blocks is not properly initialized.' );
            return;
        }

        $validator = new SchemaValidator();
        $blocks    = $discovery->discover();

        // Filter to specific block if provided
        if ( ! empty( $args[0] ) ) {
            $target_name = (string) $args[0];
            $blocks = array_filter(
                $blocks,
                function ( $path ) use ( $target_name ) {
                    return basename( $path ) === $target_name;
                }
            );

            if ( empty( $blocks ) ) {
                WP_CLI::error( "Block '" . self::safeArg( $target_name ) . "' not found." );
                return;
            }
        }

        // A named block scopes the run to that block only.
        $check_content_model = empty( $args[0] );

        if ( empty( $blocks ) ) {
            WP_CLI::warning( 'No Proto-Blocks found to validate.' );
            if ( $check_content_model ) {
                $this->finishValidation( [], true, $assoc_args );
            }
            return;
        }

        $results = [];
        $has_errors = false;

        foreach ( $blocks as $block_path ) {
            $row = self::validateBlockAt( $block_path, $validator );

            $results[] = $row;

            if ( 'error' === $row['status'] ) {
                $has_errors = true;
            }
        }

        $this->finishValidation( $results, $check_content_model, $assoc_args );
    }

    /**
     * Print the block results and, unless a single block was named, the
     * declaration report; then fail once if either holds an error.
     *
     * @param array $results    Block rows.
     * @param bool  $with_model Whether to include post type and taxonomy declarations.
     * @param array $assoc_args Associative arguments.
     */
    private function finishValidation( array $results, bool $with_model, array $assoc_args ): void {
        $format = (string) ( $assoc_args['format'] ?? 'table' );
        $rows   = [];
        if ( $with_model ) {
            $plugin = Plugin::getInstance();
            $rows   = ContentModelCommands::validationRows( $plugin->getContentModel(), array( ...$plugin->getModules()->issues(), ...( $plugin->getModules()->optionsPages()?->issues() ?? [] ) ) );
        }

        $has_errors = ContentModelCommands::hasErrors( $rows );
        foreach ( $results as $row ) {
            if ( 'error' === $row['status'] ) {
                $has_errors = true;
            }
        }

        if ( 'json' === $format ) {
            // One document, so the output stays parseable.
            WP_CLI::line( (string) wp_json_encode( [ 'blocks' => $results, 'declarations' => $rows ] ) );
        } else {
            if ( $results ) {
                WP_CLI\Utils\format_items( $format, $results, [ 'block', 'status', 'message' ] );
            }
            if ( $with_model ) {
                ContentModelCommands::printRows( $rows, $format );
            }
        }

        if ( $has_errors ) {
            WP_CLI::error( 'Validation failed for one or more blocks or declarations.' );
            return;
        }

        WP_CLI::success( 'All blocks and declarations validated successfully.' );
    }

    /**
     * Clear the Proto-Blocks template cache.
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks cache clear
     *
     * @subcommand clear
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function cache_clear( array $args, array $assoc_args ): void {
        $plugin = Plugin::getInstance();
        $cache  = $plugin->getCache();

        if ( ! $cache ) {
            WP_CLI::error( 'Cache system is not available.' );
            return;
        }

        $cache->clear();
        WP_CLI::success( 'Template cache cleared successfully.' );
    }

    /**
     * Show cache statistics.
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks cache stats
     *
     * @subcommand stats
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function cache_stats( array $args, array $assoc_args ): void {
        $plugin = Plugin::getInstance();
        $cache  = $plugin->getCache();

        if ( ! $cache ) {
            WP_CLI::error( 'Cache system is not available.' );
            return;
        }

        $stats = $cache->getStats();

        if ( 0 === (int) $stats['file_count'] ) {
            WP_CLI::log( 'No templates cached yet.' );
        }

        WP_CLI::log( sprintf( 'Cached templates: %d', (int) $stats['file_count'] ) );
        WP_CLI::log( sprintf( 'Total cache size: %s', size_format( (int) $stats['total_size'] ) ) );
        WP_CLI::log( sprintf( 'Cache directory: %s', $stats['directory'] ) );
        WP_CLI::log( sprintf( 'Cache enabled: %s', $stats['enabled'] ? 'yes' : 'no' ) );
    }

    /**
     * Manage Tailwind CSS compilation.
     *
     * ## OPTIONS
     *
     * <action>
     * : What to do.
     * ---
     * options:
     *   - compile
     *   - status
     *   - enable
     * ---
     *
     * [--format=<format>]
     * : Output format for `status`.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks tailwind status
     *     wp proto-blocks tailwind status --format=json
     *     wp proto-blocks tailwind enable
     *     wp proto-blocks tailwind compile
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function tailwind( array $args, array $assoc_args ): void {
        $action  = $args[0] ?? '';
        $manager = Plugin::getInstance()->getTailwindManager();

        if ( ! $manager ) {
            WP_CLI::error( 'Tailwind manager is not available.' );
            return;
        }

        switch ( $action ) {
            case 'compile':
                $result = $manager->compile();
                if ( ! empty( $result['success'] ) ) {
                    WP_CLI::success( $result['message'] ?? 'Tailwind CSS compiled.' );
                } else {
                    WP_CLI::error( $result['message'] ?? 'Tailwind compilation failed.' );
                }
                break;

            case 'status':
                $settings = $manager->getSettings();
                $last     = $settings['last_compiled'] ?? null;
                $item     = [
                    'enabled'       => ! empty( $settings['enabled'] ),
                    'mode'          => $settings['mode'] ?? 'cached',
                    'engine'        => $settings['engine'] ?? 'auto',
                    'last_compiled' => $last ? gmdate( 'c', (int) $last ) : null,
                ];
                $format = $assoc_args['format'] ?? 'table';

                if ( 'json' === $format ) {
                    WP_CLI::line( wp_json_encode( $item ) );
                } elseif ( 'yaml' === $format ) {
                    WP_CLI\Utils\format_items( 'yaml', [ $item ], array_keys( $item ) );
                } else {
                    $item['enabled']       = $item['enabled'] ? 'yes' : 'no';
                    $item['last_compiled'] = $item['last_compiled'] ?? 'never';
                    WP_CLI\Utils\format_items( 'table', [ $item ], array_keys( $item ) );
                }
                break;

            case 'enable':
                $manager->updateSettings( [ 'enabled' => true ] );
                WP_CLI::success( 'Tailwind CSS enabled. Run `wp proto-blocks tailwind compile` to build the stylesheet.' );
                break;

            default:
                WP_CLI::error( "Unknown tailwind action: {$action}. Use compile, status, or enable." );
        }
    }

    /**
     * Export a block to a standalone directory.
     *
     * ## OPTIONS
     *
     * <name>
     * : The block name to export.
     *
     * [--output=<path>]
     * : Output directory path.
     *
     * ## EXAMPLES
     *
     *     wp proto-blocks export card
     *     wp proto-blocks export testimonial --output=/path/to/export
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function export( array $args, array $assoc_args ): void {
        $name = (string) ( $args[0] ?? '' );

        $plugin    = Plugin::getInstance();
        $discovery = $plugin->getDiscovery();

        if ( ! $discovery ) {
            WP_CLI::error( 'Proto-Blocks is not properly initialized.' );
            return;
        }

        $blocks = $discovery->discover();
        $source_path = null;

        foreach ( $blocks as $block_path ) {
            if ( basename( $block_path ) === $name ) {
                $source_path = $block_path;
                break;
            }
        }

        if ( ! $source_path ) {
            WP_CLI::error( "Block '" . self::safeArg( $name ) . "' not found." );
            return;
        }

        $output_dir = (string) ( $assoc_args['output'] ?? getcwd() . '/' . $name );

        if ( is_dir( $output_dir ) ) {
            WP_CLI::error( 'Output directory already exists: ' . self::safeArg( $output_dir ) );
            return;
        }

        // Copy directory recursively
        $this->copyDirectory( $source_path, $output_dir );

        WP_CLI::success( "Block '{$name}' exported to: " . self::safeArg( $output_dir ) );
    }

    /**
     * Local variable names the scaffolded template.php (and the engine that
     * includes it) already own. A field with one of these names would be
     * spliced into the template as `$name = $attributes['name'] ?? ...` and
     * shadow the contract variable (or fatal, for `$this`).
     *
     * `content` is deliberately NOT reserved: the engine never defines a
     * `$content` local (nested blocks arrive as `$innerBlocksContent`), and
     * `content` is the default wysiwyg field name.
     */
    public const RESERVED_FIELD_NAMES = [
        // Renderer::executeTemplate() scope.
        'this',
        'attributes',
        'block',
        'innerBlocksContent',
        'templatePath',
        'protoConfig',
        'config',
        'variables',
        'template',
        // Locals the scaffold itself declares.
        'is_preview',
        'wrapper_attributes',
        'wrapper_styles',
        'repeater_items',
        'item',
    ];

    /**
     * Strip control and escape characters from a value before echoing it in a
     * terminal message, so an argument cannot carry ANSI sequences that rewrite
     * the line or forge output in CI log viewers.
     *
     * @param string $value Untrusted argument.
     * @return string Printable text.
     */
    public static function safeArg( string $value ): string {
        return preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '';
    }

    /**
     * Normalise a block slug argument.
     *
     * sanitize_title() contains the value to a single path segment; the regex
     * then enforces the block-name grammar WordPress accepts for the part after
     * the namespace (`^[a-z][a-z0-9-]*$`), which sanitize_title alone does not
     * (it keeps `_`, `%` and leading digits).
     *
     * @param string $raw Positional argument as typed.
     * @return string|null Valid slug, or null when it cannot be used.
     */
    public static function normalizeBlockName( string $raw ): ?string {
        $name = sanitize_title( trim( $raw ) );

        if ( '' === $name || ! preg_match( '/^[a-z][a-z0-9-]*$/', $name ) ) {
            return null;
        }

        return $name;
    }

    /**
     * Validate a `--fields` entry name.
     *
     * The name is spliced into generated PHP as a variable identifier, an
     * array key and a CSS class suffix, so it must be a plain identifier that
     * starts with a lowercase letter, and must not collide with a variable the
     * template contract already defines.
     *
     * @param string $field_name Field name as typed.
     * @return string|null Error message, or null when the name is acceptable.
     */
    public static function validateFieldName( string $field_name ): ?string {
        if ( ! preg_match( '/^[a-z][a-zA-Z0-9_]*$/', $field_name ) ) {
            return "Invalid field name '{$field_name}': use letters, digits and underscores, starting with a lowercase letter.";
        }

        if ( in_array( $field_name, self::RESERVED_FIELD_NAMES, true ) ) {
            return "Invalid field name '{$field_name}': it is reserved by the template contract.";
        }

        return null;
    }

    /**
     * Parse a `--fields="name:type,name:type"` specification.
     *
     * @param string $spec Comma-separated name:type pairs.
     * @return array<string, array> Field configurations keyed by name.
     * @throws \InvalidArgumentException When a field name is invalid or reserved.
     */
    public static function parseFields( string $spec ): array {
        $fields = [];

        foreach ( explode( ',', $spec ) as $pair ) {
            $parts = explode( ':', trim( $pair ) );
            if ( count( $parts ) < 2 ) {
                continue;
            }

            $field_name = trim( $parts[0] );
            $field_type = sanitize_key( $parts[1] );

            $error = self::validateFieldName( $field_name );
            if ( null !== $error ) {
                throw new \InvalidArgumentException( $error );
            }

            $fields[ $field_name ] = self::getFieldConfig( $field_type, $field_name );
        }

        return $fields;
    }

    /**
     * Get field configuration based on type.
     *
     * @param string $type       Field type.
     * @param string $field_name Field name.
     * @return array Field configuration.
     */
    public static function getFieldConfig( string $type, string $field_name ): array {
        $config = [ 'type' => $type ];

        switch ( $type ) {
            case 'color':
            case 'color-palette':
                $config['default'] = '#000000';
                break;

            case 'text':
                if ( in_array( $field_name, [ 'title', 'heading' ], true ) ) {
                    $config['tagName'] = 'h2';
                } else {
                    $config['tagName'] = 'p';
                }
                break;

            case 'image':
                $config['sizes'] = [ 'medium', 'large', 'full' ];
                break;

            case 'link':
                $config['tagName'] = 'a';
                break;

            case 'repeater':
                $config['min'] = 1;
                $config['max'] = 10;
                $config['fields'] = [
                    'text' => [ 'type' => 'text' ],
                ];
                break;
        }

        return $config;
    }

    /**
     * Generate template.php content.
     *
     * @param string $name   Block name.
     * @param array  $fields Block fields.
     * @return string Template content.
     */
    public function generateTemplate( string $name, array $fields ): string {
        $title      = ucwords( str_replace( '-', ' ', $name ) );
        $class_name = 'wp-block-proto-blocks-' . $name;

        $output = "<?php\n";
        $output .= "/**\n";
        $output .= " * Block: {$title}\n";
        $output .= " *\n";
        $output .= " * Every value in \$attributes is contributor-controlled JSON that WordPress\n";
        $output .= " * only type-checks; nothing escapes it for you. Escape every echo for its\n";
        $output .= " * context (esc_html / esc_attr / esc_url / wp_kses_post) and validate anything\n";
        $output .= " * that lands inside style=\"\" (sanitize_hex_color, casts, allowlists).\n";
        $output .= " *\n";
        $output .= " * @var array    \$attributes Block attributes.\n";
        $output .= " * @var string   \$innerBlocksContent Nested blocks HTML, already rendered by core (inner-blocks field only).\n";
        $output .= " * @var WP_Block \$block      Block instance.\n";
        $output .= " */\n\n";

        $output .= "defined( 'ABSPATH' ) || exit;\n\n";

        // Add $is_preview detection for editor rendering
        $output .= "// Check if we're in editor preview mode (no block instance = preview)\n";
        $output .= "\$is_preview = ! isset( \$block ) || \$block === null;\n\n";

        // Extract field variables
        $style_fields = [];
        foreach ( $fields as $field_name => $config ) {
            $type = $config['type'] ?? 'text';

            if ( 'inner-blocks' === $type ) {
                // Provided by the engine as $innerBlocksContent; never a local of its own,
                // so it can never shadow (or be shadowed by) a field named `content`.
                continue;
            }

            if ( in_array( $type, [ 'image', 'link', 'repeater', 'gallery', 'file', 'multiselect' ], true ) ) {
                $output .= "\${$field_name} = \$attributes['{$field_name}'] ?? [];\n";
            } elseif ( in_array( $type, [ 'color', 'color-palette' ], true ) ) {
                $default = (string) ( $config['default'] ?? '' );
                if ( ! preg_match( '/^#(?:[0-9a-fA-F]{3}){1,2}$/', $default ) ) {
                    $default = '#000000';
                }
                $output .= "// Colour: validated as a hex value, not merely escaped, because it lands inside style=\"\".\n";
                $output .= "\${$field_name} = sanitize_hex_color( \$attributes['{$field_name}'] ?? '' ) ?: '{$default}';\n";
                $style_fields[] = $field_name;
            } elseif ( in_array( $type, [ 'number', 'range' ], true ) ) {
                $output .= "\${$field_name} = (int) ( \$attributes['{$field_name}'] ?? 0 );\n";
            } elseif ( in_array( $type, [ 'toggle', 'checkbox' ], true ) ) {
                $output .= "\${$field_name} = ! empty( \$attributes['{$field_name}'] );\n";
            } else {
                $output .= "\${$field_name} = \$attributes['{$field_name}'] ?? '';\n";
            }
        }

        $output .= "\n";

        // Use get_block_wrapper_attributes for proper block support
        if ( ! empty( $style_fields ) ) {
            $output .= "// Colours are exposed as CSS custom properties; style.css reads them with var().\n";
            $output .= "\$wrapper_styles = [];\n";
            foreach ( $style_fields as $field_name ) {
                $output .= "\$wrapper_styles[] = '--{$class_name}-{$field_name}: ' . \${$field_name};\n";
            }
            $output .= "\n";
            $output .= "\$wrapper_attributes = get_block_wrapper_attributes( [\n";
            $output .= "    'class' => '{$class_name}',\n";
            $output .= "    'style' => implode( '; ', \$wrapper_styles ),\n";
            $output .= "] );\n";
        } else {
            $output .= "\$wrapper_attributes = get_block_wrapper_attributes( [\n";
            $output .= "    'class' => '{$class_name}',\n";
            $output .= "] );\n";
        }
        $output .= "?>\n\n";

        $output .= "<div <?php echo \$wrapper_attributes; ?>>\n";

        foreach ( $fields as $field_name => $config ) {
            $type = $config['type'] ?? 'text';
            $tag  = $config['tagName'] ?? 'div';

            switch ( $type ) {
                case 'text':
                    $output .= "    <{$tag} class=\"{$class_name}__{$field_name}\" data-proto-field=\"{$field_name}\"><?php\n";
                    $output .= "        if ( ! empty( \${$field_name} ) ) {\n";
                    $output .= "            echo esc_html( \${$field_name} );\n";
                    $output .= "        }\n";
                    $output .= "    ?></{$tag}>\n\n";
                    break;

                case 'wysiwyg':
                    $output .= "    <{$tag} class=\"{$class_name}__{$field_name}\" data-proto-field=\"{$field_name}\"><?php\n";
                    $output .= "        if ( ! empty( \${$field_name} ) ) {\n";
                    $output .= "            echo wp_kses_post( \${$field_name} );\n";
                    $output .= "        }\n";
                    $output .= "    ?></{$tag}>\n\n";
                    break;

                case 'image':
                    // Always show container in editor mode for editing capability
                    $output .= "    <?php if ( ! empty( \${$field_name}['url'] ) || \$is_preview ) : ?>\n";
                    $output .= "    <figure class=\"{$class_name}__{$field_name}\" data-proto-field=\"{$field_name}\">\n";
                    $output .= "        <?php if ( ! empty( \${$field_name}['url'] ) ) : ?>\n";
                    $output .= "            <img\n";
                    $output .= "                src=\"<?php echo esc_url( \${$field_name}['url'] ); ?>\"\n";
                    $output .= "                alt=\"<?php echo esc_attr( \${$field_name}['alt'] ?? '' ); ?>\"\n";
                    $output .= "                loading=\"lazy\"\n";
                    $output .= "            />\n";
                    $output .= "        <?php endif; ?>\n";
                    $output .= "    </figure>\n";
                    $output .= "    <?php endif; ?>\n\n";
                    break;

                case 'link':
                    // Always show in editor mode for editing capability
                    $output .= "    <?php if ( ! empty( \${$field_name}['url'] ) || \$is_preview ) : ?>\n";
                    $output .= "    <a\n";
                    $output .= "        class=\"{$class_name}__{$field_name}\"\n";
                    $output .= "        href=\"<?php echo esc_url( \${$field_name}['url'] ?? '#' ); ?>\"\n";
                    $output .= "        data-proto-field=\"{$field_name}\"\n";
                    $output .= "        <?php echo proto_blocks_link_attrs( \${$field_name} ); // target=\"_blank\" always gets rel=\"noopener\" ?>\n";
                    $output .= "    ><?php echo esc_html( \${$field_name}['text'] ?? __( 'Link', 'proto-blocks' ) ); ?></a>\n";
                    $output .= "    <?php endif; ?>\n\n";
                    break;

                case 'repeater':
                    $sub_fields = $config['fields'] ?? [];
                    $output .= "    <?php if ( ! empty( \${$field_name} ) || \$is_preview ) : ?>\n";
                    $output .= "    <?php\n";
                    $output .= "    // For preview, show placeholder if no items\n";
                    $output .= "    \$repeater_items = \${$field_name};\n";
                    $output .= "    if ( empty( \$repeater_items ) && \$is_preview ) {\n";
                    $output .= "        \$repeater_items = [\n";
                    $output .= "            [ 'id' => 'preview-1'" . $this->generateRepeaterPreviewDefaults( $sub_fields ) . " ],\n";
                    $output .= "            [ 'id' => 'preview-2'" . $this->generateRepeaterPreviewDefaults( $sub_fields ) . " ],\n";
                    $output .= "        ];\n";
                    $output .= "    }\n";
                    $output .= "    ?>\n";
                    $output .= "    <div class=\"{$class_name}__{$field_name}\" data-proto-repeater=\"{$field_name}\">\n";
                    $output .= "        <?php foreach ( \$repeater_items as \$item ) : ?>\n";
                    $output .= "        <div class=\"{$class_name}__{$field_name}-item\" data-proto-repeater-item>\n";
                    $output .= $this->generateRepeaterItemFields( $sub_fields, $class_name, $field_name );
                    $output .= "        </div>\n";
                    $output .= "        <?php endforeach; ?>\n";
                    $output .= "    </div>\n";
                    $output .= "    <?php endif; ?>\n\n";
                    break;

                case 'inner-blocks':
                    $output .= "    <div class=\"{$class_name}__inner-blocks\" data-proto-inner-blocks>\n";
                    $output .= "        <?php\n";
                    $output .= "        // \$innerBlocksContent is the nested-block HTML WordPress core has already\n";
                    $output .= "        // rendered and escaped. Echo it raw: wp_kses_post() would strip core block markup.\n";
                    $output .= "        echo \$innerBlocksContent ?? '';\n";
                    $output .= "        ?>\n";
                    $output .= "    </div>\n\n";
                    break;
            }
        }

        $output .= "</div>\n";

        return $output;
    }

    /**
     * Generate repeater preview default values.
     *
     * @param array $sub_fields Repeater sub-fields.
     * @return string PHP code for default values.
     */
    private function generateRepeaterPreviewDefaults( array $sub_fields ): string {
        $defaults = [];
        foreach ( $sub_fields as $field_name => $config ) {
            $type = $config['type'] ?? 'text';
            switch ( $type ) {
                case 'text':
                case 'wysiwyg':
                    $defaults[] = "'{$field_name}' => 'Sample " . ucfirst( $field_name ) . "'";
                    break;
                case 'image':
                    $defaults[] = "'{$field_name}' => []";
                    break;
                case 'link':
                    $defaults[] = "'{$field_name}' => [ 'url' => '#', 'text' => 'Link' ]";
                    break;
            }
        }
        if ( empty( $defaults ) ) {
            return '';
        }
        return ', ' . implode( ', ', $defaults );
    }

    /**
     * Generate repeater item field output.
     *
     * @param array  $sub_fields  Repeater sub-fields.
     * @param string $class_name  Block class name.
     * @param string $repeater_name Repeater field name.
     * @return string PHP/HTML code for repeater item fields.
     */
    private function generateRepeaterItemFields( array $sub_fields, string $class_name, string $repeater_name ): string {
        $output = '';

        foreach ( $sub_fields as $field_name => $config ) {
            $type = $config['type'] ?? 'text';
            $tag  = $config['tagName'] ?? 'div';

            switch ( $type ) {
                case 'text':
                    $output .= "            <{$tag} class=\"{$class_name}__{$repeater_name}-{$field_name}\" data-proto-field=\"{$field_name}\"><?php echo esc_html( \$item['{$field_name}'] ?? '' ); ?></{$tag}>\n";
                    break;

                case 'wysiwyg':
                    $output .= "            <{$tag} class=\"{$class_name}__{$repeater_name}-{$field_name}\" data-proto-field=\"{$field_name}\"><?php echo wp_kses_post( \$item['{$field_name}'] ?? '' ); ?></{$tag}>\n";
                    break;

                case 'image':
                    $output .= "            <?php if ( ! empty( \$item['{$field_name}']['url'] ) ) : ?>\n";
                    $output .= "            <figure class=\"{$class_name}__{$repeater_name}-{$field_name}\" data-proto-field=\"{$field_name}\">\n";
                    $output .= "                <img src=\"<?php echo esc_url( \$item['{$field_name}']['url'] ); ?>\" alt=\"<?php echo esc_attr( \$item['{$field_name}']['alt'] ?? '' ); ?>\" loading=\"lazy\" />\n";
                    $output .= "            </figure>\n";
                    $output .= "            <?php endif; ?>\n";
                    break;

                case 'link':
                    $output .= "            <?php if ( ! empty( \$item['{$field_name}']['url'] ) ) : ?>\n";
                    $output .= "            <a class=\"{$class_name}__{$repeater_name}-{$field_name}\" href=\"<?php echo esc_url( \$item['{$field_name}']['url'] ); ?>\" data-proto-field=\"{$field_name}\"<?php echo proto_blocks_link_attrs( (array) \$item['{$field_name}'] ); ?>><?php echo esc_html( \$item['{$field_name}']['text'] ?? '' ); ?></a>\n";
                    $output .= "            <?php endif; ?>\n";
                    break;
            }
        }

        if ( empty( $output ) ) {
            $output = "            <!-- Add your repeater item content here -->\n";
        }

        return $output;
    }

    /**
     * Generate basic styles.
     *
     * @param string $name   Block name.
     * @param array  $fields Block fields.
     * @return string CSS content.
     */
    private function generateStyles( string $name, array $fields ): string {
        $title      = ucwords( str_replace( '-', ' ', $name ) );
        $class_name = 'wp-block-proto-blocks-' . $name;

        $output = "/**\n";
        $output .= " * Styles for {$title} block\n";
        $output .= " */\n\n";

        // Main container styles
        $output .= ".{$class_name} {\n";
        $output .= "    display: block;\n";
        $output .= "}\n\n";

        // Generate styles for each field type
        foreach ( $fields as $field_name => $config ) {
            $type = $config['type'] ?? 'text';

            switch ( $type ) {
                case 'text':
                case 'wysiwyg':
                    $output .= ".{$class_name}__{$field_name} {\n";
                    $output .= "    /* Styles for {$field_name} field */\n";
                    $output .= "}\n\n";
                    break;

                case 'image':
                    $output .= ".{$class_name}__{$field_name} {\n";
                    $output .= "    margin: 0;\n";
                    $output .= "}\n\n";
                    $output .= ".{$class_name}__{$field_name} img {\n";
                    $output .= "    display: block;\n";
                    $output .= "    width: 100%;\n";
                    $output .= "    height: auto;\n";
                    $output .= "}\n\n";
                    break;

                case 'link':
                    $output .= ".{$class_name}__{$field_name} {\n";
                    $output .= "    display: inline-block;\n";
                    $output .= "    text-decoration: none;\n";
                    $output .= "}\n\n";
                    $output .= ".{$class_name}__{$field_name}:hover {\n";
                    $output .= "    text-decoration: underline;\n";
                    $output .= "}\n\n";
                    break;

                case 'repeater':
                    $output .= ".{$class_name}__{$field_name} {\n";
                    $output .= "    display: flex;\n";
                    $output .= "    flex-direction: column;\n";
                    $output .= "    gap: 1rem;\n";
                    $output .= "}\n\n";
                    $output .= ".{$class_name}__{$field_name}-item {\n";
                    $output .= "    /* Styles for each repeater item */\n";
                    $output .= "}\n\n";
                    break;

                case 'inner-blocks':
                    $output .= ".{$class_name}__inner-blocks {\n";
                    $output .= "    /* Styles for inner blocks container */\n";
                    $output .= "}\n\n";
                    break;

                case 'color':
                case 'color-palette':
                    $output .= ".{$class_name} {\n";
                    $output .= "    /* {$field_name} is set on the wrapper as var(--{$class_name}-{$field_name}) */\n";
                    $output .= "}\n\n";
                    break;
            }
        }

        return $output;
    }

    /**
     * Copy a directory recursively.
     *
     * @param string $source      Source directory.
     * @param string $destination Destination directory.
     */
    private function copyDirectory( string $source, string $destination ): void {
        if ( ! is_dir( $destination ) ) {
            wp_mkdir_p( $destination );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $target = $destination . '/' . $iterator->getSubPathName();

            if ( $item->isDir() ) {
                wp_mkdir_p( $target );
            } else {
                copy( $item->getPathname(), $target );
            }
        }
    }
}

/**
 * Register WP-CLI commands if available.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'proto-blocks', Commands::class );

    // Declared post types and taxonomies.
    WP_CLI::add_command( 'proto-blocks type', function ( $args, $assoc_args ) {
        ( new ContentModelCommands() )->type( $args, $assoc_args );
    } );
    WP_CLI::add_command( 'proto-blocks taxonomy', function ( $args, $assoc_args ) {
        ( new ContentModelCommands() )->taxonomy( $args, $assoc_args );
    } );

    // Settings pages.
    WP_CLI::add_command( 'proto-blocks options', OptionsCommand::class );
    // Field groups.
    WP_CLI::add_command( 'proto-blocks fields', FieldsCommand::class );

    // Add cache subcommand
    WP_CLI::add_command( 'proto-blocks cache', function( $args, $assoc_args ) {
        $commands = new Commands();

        if ( empty( $args[0] ) || $args[0] === 'clear' ) {
            $commands->cache_clear( $args, $assoc_args );
        } elseif ( $args[0] === 'stats' ) {
            $commands->cache_stats( $args, $assoc_args );
        } else {
            WP_CLI::error( 'Unknown cache command: ' . Commands::safeArg( (string) $args[0] ) );
        }
    } );
}
