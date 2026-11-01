<?php
/**
 * WordPress Abilities API: Incoming queue for AI agents (MCP via 4wp-mcp-connector).
 *
 * Abilities reuse the same code paths and permissions as the Incoming screen and its REST routes.
 *
 * @package ForWP\Drive
 */

namespace ForWP\Drive\Abilities;

use ForWP\Drive\Admin\Settings;
use ForWP\Drive\Database\Document_Repository;
use ForWP\Drive\Documents\Document_Status;
use ForWP\Drive\Import\Import_Runner;
use ForWP\Drive\Import\Import_Target_Resolver;
use ForWP\Drive\Import\Post_Date_Parser;
use ForWP\Drive\Parse\Template_Config;
use ForWP\Drive\Rest\Rest_Documents;
use ForWP\Drive\Rest\Rest_Settings;
use ForWP\Drive\Source_Registry;
use ForWP\Drive\Sync\Incoming_Scanner;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the forwp-drive/* abilities.
 */
final class Drive_Abilities {

	public const CATEGORY = 'forwp-drive';

	private const MAX_ROWS      = 500;
	private const DEFAULT_CHARS = 20000;
	private const MAX_CHARS     = 200000;

	/**
	 * Hooks into the Abilities API (WordPress 6.9+). No-op on older WordPress.
	 */
	public static function boot(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( '4WP Drive', '4wp-drive' ),
				'description' => __( 'Incoming documents from Google Drive and GitHub: sources, queue, preview, import into drafts, calendar.', '4wp-drive' ),
			)
		);
	}

	/**
	 * Registers abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$readonly = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);

		wp_register_ability(
			'forwp-drive/list-sources',
			array(
				'label'               => __( 'List connected sources', '4wp-drive' ),
				'description'         => __( 'Storage sources of 4WP Drive (Google Drive, GitHub, planned ones): whether each is connected, its status and how many documents wait in Incoming. Also the last sync. Never returns tokens or keys.', '4wp-drive' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'list_sources' ),
				'permission_callback' => array( Rest_Documents::class, 'can_view_inbox' ),
				'meta'                => array( 'annotations' => $readonly ),
			)
		);

		wp_register_ability(
			'forwp-drive/sync',
			array(
				'label'               => __( 'Sync sources', '4wp-drive' ),
				'description'         => __( 'Scans the incoming folders of connected sources and refreshes the Incoming queue (same as the Sync button). At most once a minute. Does not import anything.', '4wp-drive' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'sync' ),
				'permission_callback' => array( Rest_Settings::class, 'can_manage' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		wp_register_ability(
			'forwp-drive/list-incoming',
			array(
				'label'               => __( 'List incoming documents', '4wp-drive' ),
				'description'         => __( 'Documents in the Incoming queue (ready to import by default): id, title, slug, date from front-matter, source, status, package folder. Use the id with get-document and import.', '4wp-drive' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'source'   => array(
							'type'        => 'string',
							'description' => __( 'Source slug, e.g. google_drive or github. Empty = all connected sources.', '4wp-drive' ),
						),
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'inbox', 'ready', 'detected', 'failed', 'imported' ),
							'description' => __( 'inbox (default) = everything waiting in Incoming.', '4wp-drive' ),
						),
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Part of the title, slug or file name.', '4wp-drive' ),
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'list_incoming' ),
				'permission_callback' => array( Rest_Documents::class, 'can_view_inbox' ),
				'meta'                => array( 'annotations' => $readonly ),
			)
		);

		wp_register_ability(
			'forwp-drive/get-document',
			array(
				'label'               => __( 'Preview an incoming document', '4wp-drive' ),
				'description'         => __( 'One incoming document before import: front-matter (title, slug, date, category, tags, map), body as cleaned text, heading outline, package files (images, video) and suggested posts to update.', '4wp-drive' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'max_chars' => array(
							'type'    => 'integer',
							'minimum' => 100,
							'maximum' => self::MAX_CHARS,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'get_document' ),
				'permission_callback' => array( Rest_Documents::class, 'can_view_inbox' ),
				'meta'                => array( 'annotations' => $readonly ),
			)
		);

		wp_register_ability(
			'forwp-drive/find-targets',
			array(
				'label'               => __( 'Find posts to update', '4wp-drive' ),
				'description'         => __( 'Existing posts that an incoming document can update, matched by slug, title or search text. Use the id as target_post_id in import-update.', '4wp-drive' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'      => array( 'type' => 'string' ),
						'title'     => array( 'type' => 'string' ),
						'search'    => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string' ),
						'lang'      => array(
							'type'        => 'string',
							'description' => __( 'Content language slug on multilingual sites.', '4wp-drive' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'find_targets' ),
				'permission_callback' => array( Rest_Documents::class, 'can_import' ),
				'meta'                => array( 'annotations' => $readonly ),
			)
		);

		$import_props = array(
			'id'                     => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Incoming document id (list-incoming).', '4wp-drive' ),
			),
			'post_type'              => array(
				'type'        => 'string',
				'description' => __( 'Destination post type. Empty = Drive settings.', '4wp-drive' ),
			),
			'language'               => array(
				'type'        => 'string',
				'description' => __( 'Content language slug (Polylang / WPML). Empty = default.', '4wp-drive' ),
			),
			'featured_image_file_id' => array(
				'type'        => 'string',
				'description' => __( 'Package image id from get-document. Empty = automatic (cover / hero / first image).', '4wp-drive' ),
			),
			'keep_document_fonts'    => array(
				'type'        => 'boolean',
				'description' => __( 'Keep font styles from Google Docs. Default false.', '4wp-drive' ),
			),
		);

		wp_register_ability(
			'forwp-drive/import',
			array(
				'label'               => __( 'Import as a new draft', '4wp-drive' ),
				'description'         => __( 'Imports an incoming document into a NEW draft post (never published). The source file moves to the published folder; the import is listed in Drive Analytics.', '4wp-drive' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $import_props,
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'import_new' ),
				'permission_callback' => array( Rest_Documents::class, 'can_import' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);

		wp_register_ability(
			'forwp-drive/import-update',
			array(
				'label'               => __( 'Import into an existing post', '4wp-drive' ),
				'description'         => __( 'Replaces the content of an existing post with an incoming document (find the post with find-targets). The current version is saved as a revision first; its id is returned to restore it. The source file moves to the published folder.', '4wp-drive' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						$import_props,
						array(
							'target_post_id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						)
					),
					'required'             => array( 'id', 'target_post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'import_update' ),
				'permission_callback' => array( Rest_Documents::class, 'can_import' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);

		wp_register_ability(
			'forwp-drive/calendar',
			array(
				'label'               => __( 'Materials calendar', '4wp-drive' ),
				'description'         => __( 'Drive materials by date in a range: incoming documents by the Date front-matter field, imported ones by their post date and status (draft, scheduled, published).', '4wp-drive' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'from'    => array(
							'type'        => 'string',
							'description' => __( 'Y-m-d. Default: first day of the current month.', '4wp-drive' ),
						),
						'to'      => array(
							'type'        => 'string',
							'description' => __( 'Y-m-d. Default: last day of the month of from.', '4wp-drive' ),
						),
						'include' => array(
							'type' => 'string',
							'enum' => array( 'all', 'incoming', 'imported' ),
						),
						'source'  => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'calendar' ),
				'permission_callback' => array( Rest_Documents::class, 'can_view_inbox' ),
				'meta'                => array( 'annotations' => $readonly ),
			)
		);
	}

	/**
	 * Ability: list-sources.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_sources(): array {
		$repo   = new Document_Repository();
		$items  = array();
		$counts = array_count_values(
			array_map(
				static function ( $row ) {
					return (string) $row->source;
				},
				self::rows( $repo, Document_Status::inbox_statuses(), self::ready_sources() )
			)
		);

		foreach ( Source_Registry::get_admin_status_rows() as $row ) {
			$ready   = ! empty( $row['ready'] );
			$items[] = array(
				'slug'           => (string) $row['slug'],
				'label'          => (string) $row['label'],
				'implemented'    => ! empty( $row['implemented'] ),
				'ready'          => $ready,
				'status'         => (string) $row['status'],
				'incoming_count' => $ready ? (int) ( $counts[ (string) $row['slug'] ] ?? 0 ) : 0,
			);
		}

		return array(
			'items'     => $items,
			'last_sync' => Settings::instance()->get_last_sync(),
		);
	}

	/**
	 * Ability: sync.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function sync() {
		if ( get_transient( 'forwp_drive_sync_lock' ) ) {
			return new WP_Error( 'forwp_drive_sync_locked', __( 'Sync already ran recently. Please wait a minute.', '4wp-drive' ) );
		}

		set_transient( 'forwp_drive_sync_lock', 1, 60 );

		$result = ( new Incoming_Scanner() )->run();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary'   => $result,
			'last_sync' => Settings::instance()->get_last_sync(),
		);
	}

	/**
	 * Ability: list-incoming.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function list_incoming( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$status   = sanitize_key( (string) ( $input['status'] ?? 'inbox' ) );
		$statuses = 'inbox' === $status || '' === $status ? Document_Status::inbox_statuses() : array( $status );
		$sources  = self::sources_filter( (string) ( $input['source'] ?? '' ) );
		$search   = strtolower( sanitize_text_field( (string) ( $input['search'] ?? '' ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );

		$repo  = new Document_Repository();
		$rows  = array() === $sources ? array() : self::rows( $repo, $statuses, $sources );
		$items = array();

		foreach ( $rows as $row ) {
			$item = self::summary( $repo, $row );
			if ( '' !== $search && false === strpos( strtolower( $item['title'] . ' ' . $item['slug'] . ' ' . $item['file_name'] ), $search ) ) {
				continue;
			}
			$items[] = $item;
		}

		return array(
			'items'     => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total'     => count( $items ),
			'page'      => $page,
			'per_page'  => $per_page,
			'last_sync' => Settings::instance()->get_last_sync(),
		);
	}

	/**
	 * Ability: get-document.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_document( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$repo  = new Document_Repository();
		$row   = $repo->find( (int) ( $input['id'] ?? 0 ) );

		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Document not found.', '4wp-drive' ) );
		}

		$meta      = $repo->decode_metadata( $row );
		$html      = (string) ( $meta['body_html'] ?? '' );
		$text      = '' !== $html ? self::html_to_text( $html ) : trim( (string) ( $meta['body'] ?? '' ) );
		$max_chars = min( self::MAX_CHARS, max( 100, (int) ( $input['max_chars'] ?? self::DEFAULT_CHARS ) ) );
		$chars     = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		$truncated = $chars > $max_chars;

		if ( $truncated ) {
			$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_chars ) : substr( $text, 0, $max_chars );
		}

		$files = array();
		foreach ( (array) ( $meta['package_files'] ?? array() ) as $file ) {
			if ( ! is_array( $file ) || '' === (string) ( $file['name'] ?? '' ) ) {
				continue;
			}
			$files[] = array(
				'id'   => (string) ( $file['id'] ?? '' ),
				'name' => (string) $file['name'],
				'kind' => (string) ( $file['kind'] ?? 'file' ),
			);
		}

		$targets = Import_Target_Resolver::suggest(
			( new Template_Config() )->get_import_post_type(),
			(string) ( $meta['slug'] ?? '' ),
			(string) ( $meta['title'] ?? '' ),
			'',
			5,
			''
		);

		return array_merge(
			self::summary( $repo, $row ),
			array(
				'front_matter'     => array(
					'title'    => (string) ( $meta['title'] ?? '' ),
					'slug'     => (string) ( $meta['slug'] ?? '' ),
					'date'     => (string) ( $meta['date'] ?? '' ),
					'author'   => (string) ( $meta['author'] ?? '' ),
					'category' => (string) ( $meta['category'] ?? '' ),
					'tags'     => $meta['tags'] ?? array(),
					'map'      => (string) ( $meta['map'] ?? '' ),
				),
				'content'          => $text,
				'chars'            => $chars,
				'truncated'        => $truncated,
				'outline'          => self::outline( $html ),
				'package_files'    => $files,
				'suggested_target' => $targets['suggested_id'] ? ( self::targets_payload( array( array( 'id' => (int) $targets['suggested_id'] ) ) )[0] ?? null ) : null,
				'can_import'       => Document_Status::can_import( (string) $row->status ),
			)
		);
	}

	/**
	 * Ability: find-targets.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function find_targets( $input = array() ): array {
		$input     = is_array( $input ) ? $input : array();
		$post_type = ( new Template_Config() )->resolve_import_post_type( sanitize_key( (string) ( $input['post_type'] ?? '' ) ) );
		$result    = Import_Target_Resolver::suggest(
			$post_type,
			sanitize_title( (string) ( $input['slug'] ?? '' ) ),
			sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
			sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
			50,
			sanitize_key( (string) ( $input['lang'] ?? '' ) )
		);

		return array(
			'post_type'    => $post_type,
			'items'        => self::targets_payload( $result['targets'] ),
			'suggested_id' => $result['suggested_id'],
		);
	}

	/**
	 * Ability: import (new draft).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import_new( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::run_import( $input, array( 'mode' => 'create' ) );
	}

	/**
	 * Ability: import-update (replace content of an existing post).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import_update( $input = array() ) {
		$input     = is_array( $input ) ? $input : array();
		$target_id = (int) ( $input['target_post_id'] ?? 0 );
		$target    = get_post( $target_id );

		if ( ! $target ) {
			return new WP_Error( 'not_found', __( 'Target post not found.', '4wp-drive' ) );
		}
		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit the target post.', '4wp-drive' ) );
		}

		// Snapshot of the current version so the agent / admin can restore it.
		$revision_id = wp_save_post_revision( $target_id );
		if ( ! $revision_id ) {
			$revisions   = wp_get_post_revisions(
				$target_id,
				array(
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			$revision_id = $revisions ? (int) reset( $revisions ) : 0;
		}

		$result = self::run_import(
			$input,
			array(
				'mode'           => 'update',
				'target_post_id' => $target_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['before'] = array(
			'title'         => html_entity_decode( get_the_title( $target ), ENT_QUOTES, 'UTF-8' ),
			'modified'      => (string) $target->post_modified,
			'revision_id'   => (int) $revision_id,
			'revisions_url' => $revision_id ? admin_url( 'revision.php?revision=' . (int) $revision_id ) : '',
		);

		return $result;
	}

	/**
	 * Ability: calendar.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function calendar( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$from    = self::ymd( (string) ( $input['from'] ?? '' ), wp_date( 'Y-m-01' ) );
		$to      = self::ymd( (string) ( $input['to'] ?? '' ), gmdate( 'Y-m-t', (int) strtotime( $from ) ) );
		$include = sanitize_key( (string) ( $input['include'] ?? 'all' ) );
		$sources = self::sources_filter( (string) ( $input['source'] ?? '' ) );

		if ( $to < $from ) {
			return new WP_Error( 'invalid_range', __( 'to is before from.', '4wp-drive' ) );
		}

		$repo    = new Document_Repository();
		$days    = array();
		$undated = 0;

		if ( 'imported' !== $include && array() !== $sources ) {
			foreach ( self::rows( $repo, Document_Status::inbox_statuses(), $sources ) as $row ) {
				$meta = $repo->decode_metadata( $row );
				$date = substr( Post_Date_Parser::to_post_date( (string) ( $meta['date'] ?? '' ) ), 0, 10 );
				if ( '' === $date ) {
					++$undated;
					continue;
				}
				if ( $date < $from || $date > $to ) {
					continue;
				}
				$days[ $date ][] = array(
					'kind'   => 'incoming',
					'id'     => (int) $row->id,
					'title'  => (string) ( $meta['title'] ?? $row->file_name ),
					'source' => (string) $row->source,
					'status' => (string) $row->status,
				);
			}
		}

		if ( 'incoming' !== $include && array() !== $sources ) {
			foreach ( self::rows( $repo, array( Document_Status::IMPORTED ), $sources ) as $row ) {
				$post = empty( $row->wp_post_id ) ? null : get_post( (int) $row->wp_post_id );
				if ( ! $post || 'trash' === $post->post_status ) {
					continue;
				}
				$date = substr( (string) $post->post_date, 0, 10 );
				if ( $date < $from || $date > $to ) {
					continue;
				}
				$kinds           = array(
					'future'  => 'scheduled',
					'publish' => 'published',
				);
				$days[ $date ][] = array(
					'kind'        => $kinds[ $post->post_status ] ?? 'draft',
					'id'          => (int) $row->id,
					'post_id'     => (int) $post->ID,
					'title'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'source'      => (string) $row->source,
					'post_status' => (string) $post->post_status,
					'edit_url'    => (string) get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}

		ksort( $days );
		$out = array();
		foreach ( $days as $date => $items ) {
			$out[] = array(
				'date'  => $date,
				'items' => $items,
			);
		}

		return array(
			'from'             => $from,
			'to'               => $to,
			'days'             => $out,
			'incoming_undated' => $undated,
		);
	}

	/**
	 * Runs Import_Runner with options from the ability input.
	 *
	 * @param array<string, mixed> $input   Input.
	 * @param array<string, mixed> $options Mode options.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function run_import( array $input, array $options ) {
		if ( isset( $input['post_type'] ) && '' !== $input['post_type'] ) {
			$options['post_type'] = sanitize_key( (string) $input['post_type'] );
		}
		if ( isset( $input['language'] ) && '' !== $input['language'] ) {
			$options['language'] = sanitize_key( (string) $input['language'] );
		}
		if ( isset( $input['featured_image_file_id'] ) && '' !== $input['featured_image_file_id'] ) {
			$options['featured_image_file_id'] = sanitize_text_field( (string) $input['featured_image_file_id'] );
		}
		$options['keep_document_fonts'] = ! empty( $input['keep_document_fonts'] );

		$result = ( new Import_Runner() )->import( (int) ( $input['id'] ?? 0 ), $options );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) ( $result['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		return array(
			'post_id'     => $post_id,
			'post_type'   => $post ? (string) $post->post_type : '',
			'post_status' => $post ? (string) $post->post_status : '',
			'title'       => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '',
			'mode'        => (string) ( $result['mode'] ?? $options['mode'] ),
			'edit_url'    => (string) ( $result['edit_url'] ?? '' ),
			'preview_url' => $post ? (string) get_preview_post_link( $post ) : '',
			'warning'     => (string) ( $result['warning'] ?? '' ),
			'requeued'    => $result['requeued'] ?? null,
		);
	}

	/**
	 * Short row for lists.
	 *
	 * @param Document_Repository $repo Repository.
	 * @param object              $row  Document row.
	 * @return array<string, mixed>
	 */
	private static function summary( Document_Repository $repo, $row ): array {
		$meta = $repo->decode_metadata( $row );

		return array(
			'id'          => (int) $row->id,
			'title'       => (string) ( $meta['title'] ?? '' ),
			'slug'        => (string) ( $meta['slug'] ?? '' ),
			'date'        => (string) ( $meta['date'] ?? '' ),
			'source'      => (string) $row->source,
			'status'      => (string) $row->status,
			'file_name'   => (string) $row->file_name,
			'package'     => (string) ( $meta['package_folder_name'] ?? '' ),
			'detected_at' => (string) $row->detected_at,
			'wp_post_id'  => $row->wp_post_id ? (int) $row->wp_post_id : null,
			'error'       => (string) ( $meta['scan_error'] ?? $row->error_message ?? '' ),
		);
	}

	/**
	 * Connected source slugs, optionally narrowed to one.
	 *
	 * @param string $source Requested slug.
	 * @return string[]
	 */
	private static function sources_filter( string $source ): array {
		$ready  = self::ready_sources();
		$source = sanitize_key( $source );

		if ( '' === $source ) {
			return $ready;
		}

		return in_array( $source, $ready, true ) ? array( $source ) : array();
	}

	/**
	 * Slugs of connected sources.
	 *
	 * @return string[]
	 */
	private static function ready_sources(): array {
		$slugs = array();
		foreach ( Source_Registry::all() as $slug => $source ) {
			if ( $source->is_ready() ) {
				$slugs[] = (string) $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Document rows by status, limited to the given sources.
	 *
	 * @param Document_Repository $repo     Repository.
	 * @param string[]            $statuses Statuses.
	 * @param string[]            $sources  Source slugs.
	 * @return object[]
	 */
	private static function rows( Document_Repository $repo, array $statuses, array $sources ): array {
		if ( array() === $sources ) {
			return array();
		}

		return array_values(
			array_filter(
				$repo->list_by_statuses( $statuses, self::MAX_ROWS ),
				static function ( $row ) use ( $sources ) {
					return in_array( (string) $row->source, $sources, true );
				}
			)
		);
	}

	/**
	 * Target rows without internal fields.
	 *
	 * @param array<int, array<string, mixed>> $targets Resolver rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function targets_payload( array $targets ): array {
		$out = array();
		foreach ( $targets as $target ) {
			$id = (int) ( $target['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$out[] = array(
				'id'        => $id,
				'title'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'slug'      => (string) get_post_field( 'post_name', $id ),
				'post_type' => (string) get_post_type( $id ),
				'status'    => (string) get_post_status( $id ),
				'url'       => (string) get_permalink( $id ),
				'edit_url'  => (string) get_edit_post_link( $id, 'raw' ),
			);
		}

		return $out;
	}

	/**
	 * Body HTML to readable text (one block per line).
	 *
	 * @param string $html HTML.
	 */
	private static function html_to_text( string $html ): string {
		$html = preg_replace( '#<(br|/p|/h[1-6]|/li|/tr|/blockquote|/pre|/figure)[^>]*>#i', "$0\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n\s*\n+/", "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Headings H1–H6.
	 *
	 * @param string $html HTML.
	 * @return array<int, array{level: int, text: string}>
	 */
	private static function outline( string $html ): array {
		$out = array();
		if ( preg_match_all( '#<h([1-6])[^>]*>(.*?)</h\1>#is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$text = trim( html_entity_decode( wp_strip_all_tags( $match[2] ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $text ) {
					$out[] = array(
						'level' => (int) $match[1],
						'text'  => $text,
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Y-m-d or fallback.
	 *
	 * @param string $value    Input.
	 * @param string $fallback Default.
	 */
	private static function ymd( string $value, string $fallback ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : $fallback;
	}
}
