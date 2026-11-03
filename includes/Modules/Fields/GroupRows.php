<?php
/**
 * Row building for the field-group list, shared by WP-CLI and the admin REST
 * routes. Must not depend on any WP-CLI class.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Sources\AcfBridge;

final class GroupRows {

	public const STATUS_ACTIVE       = 'active';
	public const STATUS_UNRENDERABLE = 'unrenderable';
	public const STATUS_SKIPPED_ACF  = 'skipped-acf';

	/**
	 * Strip control characters (ANSI escapes etc.) from untrusted text.
	 */
	public static function safeArg( string $value ): string {
		return preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '';
	}

	/**
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
}
