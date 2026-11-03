<?php
/**
 * WP-CLI: `wp proto-blocks fields list|validate`.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\CLI;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Definition\Report;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Sources\AcfBridge;
use WP_CLI;

/**
 * Inspect and validate field groups.
 *
 * The static helpers hold the logic and take their collaborators as
 * arguments, so they run without WP-CLI.
 */
final class FieldsCommand {

	public const STATUS_ACTIVE       = 'active';
	public const STATUS_UNRENDERABLE = 'unrenderable';
	public const STATUS_SKIPPED_ACF  = 'skipped-acf';

	private const LIST_COLUMNS = array( 'key', 'title', 'source', 'path', 'fields', 'location', 'status' );

	/** Location params that confine a group to some posts. */
	private const NARROWING_PARAMS = array( 'post_type', 'post', 'page_template', 'post_taxonomy' );

	/** @var \Closure(): ?FieldsModule */
	private \Closure $resolve;

	/**
	 * @param (callable(): ?FieldsModule)|null $resolve The enabled Fields module, or null when it is disabled (default: the plugin's module manager).
	 */
	public function __construct( ?callable $resolve = null ) {
		$this->resolve = null !== $resolve
			? \Closure::fromCallable( $resolve )
			: static fn(): ?FieldsModule => \ProtoBlocks\Core\Plugin::getInstance()->getModules()->fields();
	}

	/**
	 * Strips control characters. A copy of Commands::safeArg(): that class
	 * extends WP_CLI_Command, so it cannot be loaded by a REST request.
	 *
	 * @param string $value Untrusted text.
	 */
	public static function safeArg( string $value ): string {
		return preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '';
	}

	/**
	 * One row per group, then one per group skipped because ACF registers its key.
	 *
	 * @param list<FieldGroup>                                        $groups
	 * @param list<array{key: string, title: string, source: string}> $skipped
	 * @return list<array{key: string, title: string, source: string, path: string, fields: int, location: string, status: string}>
	 */
	public static function rows( array $groups, array $skipped ): array {
		$rows = array();
		foreach ( $groups as $group ) {
			$rows[] = array(
				'key'      => self::safeArg( $group->key ),
				'title'    => self::safeArg( $group->title ),
				'source'   => self::safeArg( $group->source ),
				'path'     => self::safeArg( (string) ( $group->path ?? '' ) ),
				'fields'   => count( $group->fields ),
				'location' => self::safeArg( AcfBridge::locationSummary( $group->location->or ) ),
				'status'   => $group->location->isRenderable() ? self::STATUS_ACTIVE : self::STATUS_UNRENDERABLE,
			);
		}
		foreach ( $skipped as $one ) {
			$rows[] = array(
				'key'      => self::safeArg( $one['key'] ),
				'title'    => self::safeArg( $one['title'] ),
				'source'   => 'acf',
				'path'     => self::safeArg( $one['source'] ),
				'fields'   => 0,
				'location' => '',
				'status'   => self::STATUS_SKIPPED_ACF,
			);
		}

		return $rows;
	}

	/**
	 * True when a renderable group has a post branch that no post_type, post,
	 * page_template or post_taxonomy `==` rule narrows, so it matches every
	 * post type (a group confined to a declared type is never wide).
	 */
	public static function isWide( FieldGroup $group ): bool {
		$location = $group->location;
		if ( $location->implied || ! $location->isRenderable() ) {
			return false;
		}
		foreach ( $location->or as $branch ) {
			$narrowed = false;
			foreach ( $branch as $rule ) {
				if ( 'post' !== ( GroupNormalizer::PARAM_KINDS[ $rule['param'] ] ?? '' ) ) {
					continue 2;
				}
				if ( '==' === $rule['operator'] && in_array( $rule['param'], self::NARROWING_PARAMS, true ) ) {
					$narrowed = true;
				}
			}
			if ( ! $narrowed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate group files, plus an optional module report (the findings of
	 * the discovered groups and declarations).
	 *
	 * @param list<string>            $paths        Group files (one group or an ACF export).
	 * @param callable(string): array $decode       Throws on invalid input.
	 * @param Report|null             $moduleReport
	 * @return array{errors: int, warnings: int, lines: list<array{level: string, file: string, path: string, code: string, message: string}>}
	 */
	public static function validatePaths( array $paths, callable $decode, ?Report $moduleReport = null ): array {
		$lines = array();
		$add   = static function ( Report $report, string $file, bool $split ) use ( &$lines ): void {
			foreach ( array(
				'ERROR'   => $report->errors,
				'WARNING' => $report->warnings,
			) as $level => $entries ) {
				foreach ( $entries as $e ) {
					$lines[] = array(
						'level'   => $level,
						'file'    => $split ? $file : $e['path'],
						'path'    => $split ? $e['path'] : '',
						'code'    => $e['code'],
						'message' => $e['message'],
					);
				}
			}
		};

		foreach ( $paths as $path ) {
			$one  = new Report();
			$text = is_file( $path ) && is_readable( $path ) ? file_get_contents( $path ) : false;
			if ( false === $text ) {
				$one->addError( '', 'unreadable', 'The file cannot be read.' );
				$add( $one, $path, true );
				continue;
			}
			try {
				$data = $decode( $text );
			} catch ( \Throwable $e ) {
				$one->addError( '', 'invalid_json', $e->getMessage() );
				$add( $one, $path, true );
				continue;
			}
			$result = GroupNormalizer::normalizeMany( $data, 'file:' . pathinfo( $path, PATHINFO_FILENAME ), $path );
			foreach ( $result['groups'] as $group ) {
				if ( self::isWide( $group ) ) {
					$result['report']->addWarning( 'location', 'wide_location', self::wideMessage( $group ) );
				}
			}
			$add( $result['report'], $path, true );
		}
		if ( null !== $moduleReport ) {
			$add( $moduleReport, '', false );
		}

		$errors   = 0;
		$warnings = 0;
		foreach ( $lines as $line ) {
			'ERROR' === $line['level'] ? ++$errors : ++$warnings;
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'lines'    => $lines,
		);
	}

	private static function wideMessage( FieldGroup $group ): string {
		return sprintf( 'The field group "%s" matches every post type; add a post_type == rule to confine it.', $group->key );
	}

	/**
	 * `wp proto-blocks fields list [--format=<format>]`
	 *
	 * @param list<string>         $args  Unused.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function list( $args, $assoc ): void {
		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( 'Unknown format: ' . self::safeArg( $format ) );
		}
		$module = ( $this->resolve )();
		if ( null === $module ) {
			WP_CLI::error( 'The fields module is disabled.' );
		}
		$groups = $module->index()->all();
		$rows   = self::rows( $groups, $module->acf()->skipped() );
		if ( array() === $rows && 'table' === $format ) {
			WP_CLI::log( 'No field groups.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, self::LIST_COLUMNS );
	}

	/**
	 * `wp proto-blocks fields validate [<path>...]`
	 *
	 * With no path, every discovered group file, declaration and PHP group.
	 * Exits 1 on any error; warnings do not fail.
	 *
	 * @param list<string>         $args  File paths.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function validate( $args, $assoc ): void {
		$module = ( $this->resolve )();
		$paths  = array_values( array_map( 'strval', (array) $args ) );
		$decode = array( FieldsModule::class, 'decodeFile' );
		if ( null === $module && array() === $paths ) {
			WP_CLI::error( 'The fields module is disabled. Pass file paths to validate files anyway.' );
		}
		if ( null === $module ) {
			WP_CLI::warning( 'The fields module is disabled; validating the given files only.' );
		}
		if ( null !== $module && array() === $paths ) {
			$groups = $module->index()->all();
			$report = $module->report();
			foreach ( $groups as $group ) {
				if ( self::isWide( $group ) ) {
					$report->addWarning( (string) ( $group->path ?? $group->source ), 'wide_location', self::wideMessage( $group ) );
				}
			}
			$result = self::validatePaths( array(), $decode, $report );
		} else {
			$result = self::validatePaths( $paths, $decode );
		}

		foreach ( $result['lines'] as $line ) {
			WP_CLI::line( sprintf( '%s %s %s %s: %s', $line['level'], self::safeArg( $line['file'] ), '' === $line['path'] ? '-' : self::safeArg( $line['path'] ), self::safeArg( $line['code'] ), self::safeArg( $line['message'] ) ) );
		}
		if ( $result['errors'] > 0 ) {
			WP_CLI::error( sprintf( '%d error(s), %d warning(s).', $result['errors'], $result['warnings'] ) );
		}
		WP_CLI::success( sprintf( 'No errors, %d warning(s).', $result['warnings'] ) );
	}
}
