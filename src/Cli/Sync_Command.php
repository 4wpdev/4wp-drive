<?php
/**
 * WP-CLI sync command.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Cli;

use ForWP\Drive\Sync\Incoming_Scanner;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * CLI commands.
 */
final class Sync_Command {

	/**
	 * Scan incoming Drive folder.
	 *
	 * ## EXAMPLES
	 *
	 *     wp forwp-drive sync
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 */
	public static function sync( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$result = ( new Incoming_Scanner() )->run();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'Scanned %d file(s); %d new ready; %d total ready.',
				(int) ( $result['scanned'] ?? 0 ),
				(int) ( $result['new_ready'] ?? 0 ),
				(int) ( $result['ready_total'] ?? 0 )
			)
		);
	}
}
