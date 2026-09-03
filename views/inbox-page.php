<?php
/**
 * Admin inbox template — editorial dashboard (queue + workspace).
 *
 * @package ForWP\Drive
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap forwp-drive-wrap forwp-drive-admin-page forwp-drive-inbox-dashboard">
	<h1 class="forwp-drive-admin-chrome"><?php esc_html_e( '4WP Drive — Inbox', '4wp-drive' ); ?></h1>

	<div
		id="forwp-drive-inbox-chrome"
		class="forwp-drive-inbox-chrome forwp-drive-admin-chrome"
	>
		<div
			id="forwp-drive-inbox-source-tabs"
			class="forwp-drive-source-tabs"
			role="tablist"
			aria-label="<?php esc_attr_e( 'Storage sources', '4wp-drive' ); ?>"
		>
			<!-- Filled by admin.js from Source_Registry rows -->
		</div>

		<div
			id="forwp-drive-inbox-statusbar"
			class="forwp-drive-statusbar forwp-drive-statusbar--in-chrome"
			aria-label="<?php esc_attr_e( 'Inbox status', '4wp-drive' ); ?>"
		>
			<div class="forwp-drive-statusbar__chips" id="forwp-drive-inbox-chips">
				<span class="forwp-drive-chip forwp-drive-chip--muted" data-chip="connection">
					<?php esc_html_e( 'Checking connection…', '4wp-drive' ); ?>
				</span>
				<span class="forwp-drive-chip forwp-drive-chip--muted" data-chip="sync">
					<?php esc_html_e( 'Last sync: —', '4wp-drive' ); ?>
				</span>
				<span class="forwp-drive-chip" data-chip="ready">
					<?php esc_html_e( 'Ready: —', '4wp-drive' ); ?>
				</span>
				<span class="forwp-drive-chip forwp-drive-chip--muted" data-chip="errors" hidden>
					<?php esc_html_e( 'Export errors: 0', '4wp-drive' ); ?>
				</span>
			</div>
			<div class="forwp-drive-statusbar__actions">
				<a
					id="forwp-drive-inbox-open-incoming"
					class="button forwp-drive-statusbar__link"
					href="#"
					target="_blank"
					rel="noopener noreferrer"
					hidden
				><?php esc_html_e( 'Open folder', '4wp-drive' ); ?></a>
				<button type="button" class="button button-primary" id="forwp-drive-inbox-sync">
					<?php esc_html_e( 'Sync', '4wp-drive' ); ?>
				</button>
			</div>
		</div>
	</div>

	<div id="forwp-drive-inbox-connection-alert" class="forwp-drive-connection-alert forwp-drive-admin-chrome" hidden></div>

	<div
		id="forwp-drive-inbox-status"
		class="forwp-drive-status forwp-drive-admin-chrome"
		role="status"
		aria-live="polite"
		hidden
	></div>

	<div class="forwp-drive-inbox-layout forwp-drive-admin-chrome">
		<section
			class="forwp-drive-inbox-queue"
			aria-labelledby="forwp-drive-inbox-queue-heading"
		>
			<div class="forwp-drive-inbox-pane__header">
				<h2 id="forwp-drive-inbox-queue-heading" class="forwp-drive-inbox-pane__title">
					<?php esc_html_e( 'Queue', '4wp-drive' ); ?>
				</h2>
				<span id="forwp-drive-inbox-queue-count" class="forwp-drive-inbox-pane__count" hidden></span>
			</div>
			<div id="forwp-drive-inbox-list" class="forwp-drive-inbox-list"></div>
		</section>

		<section
			class="forwp-drive-inbox-workspace"
			aria-labelledby="forwp-drive-inbox-workspace-heading"
		>
			<div class="forwp-drive-inbox-pane__header">
				<h2 id="forwp-drive-inbox-workspace-heading" class="forwp-drive-inbox-pane__title">
					<?php esc_html_e( 'Workspace', '4wp-drive' ); ?>
				</h2>
			</div>

			<div
				id="forwp-drive-workspace-placeholder"
				class="forwp-drive-workspace-placeholder"
			>
				<p class="forwp-drive-workspace-placeholder__lead">
					<?php esc_html_e( 'Select a document from the queue to preview and import.', '4wp-drive' ); ?>
				</p>
			</div>

			<div id="forwp-drive-preview" class="forwp-drive-preview" hidden>
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
				<div id="forwp-drive-import-options" class="forwp-drive-import-options forwp-drive-admin-chrome">
					<p id="forwp-drive-import-options-label" class="forwp-drive-import-options__label">
						<?php esc_html_e( 'Import destination', '4wp-drive' ); ?>
					</p>
					<div id="forwp-drive-import-language-wrap" class="forwp-drive-import-language-wrap" hidden>
						<label class="forwp-drive-import-language-wrap__label" for="forwp-drive-import-language"><?php esc_html_e( 'Content language', '4wp-drive' ); ?></label>
						<select id="forwp-drive-import-language" class="forwp-drive-import-language"></select>
						<p class="description forwp-drive-import-language-wrap__hint">
							<?php esc_html_e( 'Required on multilingual sites. Update mode lists only posts in this language.', '4wp-drive' ); ?>
						</p>
					</div>
					<div class="forwp-drive-import-options__choices" role="radiogroup" aria-labelledby="forwp-drive-import-options-label">
						<label class="forwp-drive-import-options__choice">
							<input type="radio" name="forwp-drive-import-mode" value="create" checked />
							<span class="forwp-drive-import-options__choice-text">
								<span class="forwp-drive-import-options__choice-title"><?php esc_html_e( 'Create new draft', '4wp-drive' ); ?></span>
								<span class="forwp-drive-import-options__choice-hint"><?php esc_html_e( 'Adds a new post from this document.', '4wp-drive' ); ?></span>
							</span>
						</label>
						<label class="forwp-drive-import-options__choice">
							<input type="radio" name="forwp-drive-import-mode" value="update" />
							<span class="forwp-drive-import-options__choice-text">
								<span class="forwp-drive-import-options__choice-title"><?php esc_html_e( 'Update existing post', '4wp-drive' ); ?></span>
								<span class="forwp-drive-import-options__choice-hint"><?php esc_html_e( 'Replace content in a post you select below.', '4wp-drive' ); ?></span>
							</span>
						</label>
					</div>
					<div id="forwp-drive-import-target-wrap" class="forwp-drive-import-target-wrap" hidden>
						<label class="forwp-drive-import-target-wrap__label" for="forwp-drive-import-target"><?php esc_html_e( 'Target post', '4wp-drive' ); ?></label>
						<select id="forwp-drive-import-target" class="forwp-drive-import-target"></select>
						<p class="description forwp-drive-import-target-wrap__hint">
							<?php esc_html_e( 'Matches by slug or title when possible. Only posts in the selected language are listed.', '4wp-drive' ); ?>
						</p>
					</div>
				</div>
				<div class="forwp-drive-preview__actions forwp-drive-admin-chrome">
					<button type="button" class="button button-primary" id="forwp-drive-preview-import">
						<?php esc_html_e( 'Import', '4wp-drive' ); ?>
					</button>
					<button type="button" class="button" id="forwp-drive-preview-reject">
						<?php esc_html_e( 'Reject', '4wp-drive' ); ?>
					</button>
					<button type="button" class="button-link" id="forwp-drive-preview-close">
						<?php esc_html_e( 'Close', '4wp-drive' ); ?>
					</button>
				</div>
			</div>
		</section>
	</div>
</div>
