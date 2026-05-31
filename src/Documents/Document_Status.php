<?php
/**
 * Document pipeline statuses.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Documents;

defined( 'ABSPATH' ) || exit;

/**
 * Status constants for forwp_drive_documents rows.
 */
final class Document_Status {

	public const DETECTED  = 'detected';
	public const READY     = 'ready';
	public const IMPORTING = 'importing';
	public const IMPORTED  = 'imported';
	public const FAILED    = 'failed';
	public const REJECTED  = 'rejected';
	public const REMOVED   = 'removed';

	/**
	 * Statuses shown in the admin inbox.
	 *
	 * @return string[]
	 */
	public static function inbox_statuses(): array {
		return array( self::READY );
	}

	/**
	 * Whether a transition to importing is allowed.
	 *
	 * @param string $status Current status.
	 */
	public static function can_import( string $status ): bool {
		return self::READY === $status;
	}
}
