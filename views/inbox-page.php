<?php
/**
 * Admin inbox template.
 *
 * @package ForWP\Drive
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap forwp-drive-wrap forwp-drive-admin-page">
	<h1 class="forwp-drive-admin-chrome"><?php esc_html_e( '4WP Drive — Inbox', '4wp-drive' ); ?></h1>
	<p class="description forwp-drive-admin-chrome">
		<?php esc_html_e( 'After sync, ready items appear here. Put each article in its own subfolder inside incoming/ (Google Doc + featured image), or drop a single doc directly in incoming/.', '4wp-drive' ); ?>
	</p>

	<div class="forwp-drive-actions forwp-drive-admin-chrome">
		<button type="button" class="button button-primary" id="forwp-drive-inbox-sync">
			<?php esc_html_e( 'Sync from Drive', '4wp-drive' ); ?>
		</button>
	</div>

	<div id="forwp-drive-inbox-connection-alert" class="forwp-drive-connection-alert forwp-drive-admin-chrome" hidden></div>

	<div
		id="forwp-drive-inbox-status"
		class="forwp-drive-status forwp-drive-admin-chrome"
		role="status"
		aria-live="polite"
		hidden
	></div>
	<div id="forwp-drive-inbox-list" class="forwp-drive-inbox-list forwp-drive-admin-chrome"></div>

	<div id="forwp-drive-preview" class="forwp-drive-preview forwp-drive-admin-chrome" hidden>
		<div class="forwp-drive-preview__header">
			<p class="forwp-drive-preview__label"><?php esc_html_e( 'Preview', '4wp-drive' ); ?></p>
			<div id="forwp-drive-preview-meta"></div>
		</div>
		<div id="forwp-drive-preview-body" class="forwp-drive-preview-body">
			<main class="wp-block-group single-post-main forwp-drive-preview-single">
				<div class="wp-block-group alignfull single-post-entry-content">
					<div id="forwp-drive-preview-post-content" class="wp-block-post-content entry-content"></div>
				</div>
			</main>
		</div>
		<fieldset id="forwp-drive-import-options" class="forwp-drive-import-options">
			<legend><?php esc_html_e( 'Import destination', '4wp-drive' ); ?></legend>
			<label class="forwp-drive-import-options__choice">
				<input type="radio" name="forwp-drive-import-mode" value="create" checked />
				<?php esc_html_e( 'Create new draft', '4wp-drive' ); ?>
			</label>
			<label class="forwp-drive-import-options__choice">
				<input type="radio" name="forwp-drive-import-mode" value="update" />
				<?php esc_html_e( 'Update existing post', '4wp-drive' ); ?>
			</label>
			<div id="forwp-drive-import-target-wrap" class="forwp-drive-import-target-wrap" hidden>
				<label for="forwp-drive-import-target"><?php esc_html_e( 'Target post', '4wp-drive' ); ?></label>
				<select id="forwp-drive-import-target" class="forwp-drive-import-target"></select>
				<p class="description">
					<?php esc_html_e( 'Matches by slug or title when possible. Status and permalink are preserved.', '4wp-drive' ); ?>
				</p>
			</div>
		</fieldset>
		<p>
			<button type="button" class="button button-primary" id="forwp-drive-preview-import">
				<?php esc_html_e( 'Import', '4wp-drive' ); ?>
			</button>
			<button type="button" class="button" id="forwp-drive-preview-reject">
				<?php esc_html_e( 'Reject', '4wp-drive' ); ?>
			</button>
			<button type="button" class="button-link" id="forwp-drive-preview-close">
				<?php esc_html_e( 'Close', '4wp-drive' ); ?>
			</button>
		</p>
	</div>
</div>
