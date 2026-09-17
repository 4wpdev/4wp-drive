<?php
/**
 * Admin inbox template — editorial dashboard (queue + workspace).
 *
 * @package ForWP\Drive
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap forwp-drive-wrap forwp-drive-admin-page forwp-drive-inbox-dashboard">
	<h1 class="forwp-drive-admin-chrome"><?php esc_html_e( '4WP Drive — Incoming', '4wp-drive' ); ?></h1>

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
				<div id="forwp-drive-import-top" class="forwp-drive-import-top forwp-drive-admin-chrome">
					<div id="forwp-drive-import-featured-wrap" class="forwp-drive-import-featured-wrap" hidden>
						<label class="forwp-drive-import-featured-wrap__label" for="forwp-drive-import-featured">
							<?php esc_html_e( 'Featured image', '4wp-drive' ); ?>
						</label>
						<select id="forwp-drive-import-featured" class="forwp-drive-import-featured"></select>
						<p class="description forwp-drive-import-featured-wrap__hint">
							<?php esc_html_e( 'Post thumbnail. Cover, hero, or the first image is suggested.', '4wp-drive' ); ?>
						</p>
					</div>
					<div
						id="forwp-drive-import-media-row"
						class="forwp-drive-import-media-row"
					>
						<div id="forwp-drive-import-language-wrap" class="forwp-drive-import-language-wrap" hidden>
							<label class="forwp-drive-import-language-wrap__label" for="forwp-drive-import-language">
								<?php esc_html_e( 'Content language', '4wp-drive' ); ?>
								<span class="forwp-drive-field-required" aria-hidden="true">*</span>
							</label>
							<select id="forwp-drive-import-language" class="forwp-drive-import-language" aria-describedby="forwp-drive-import-language-error forwp-drive-import-language-hint"></select>
							<p id="forwp-drive-import-language-error" class="forwp-drive-field-error" hidden></p>
							<p id="forwp-drive-import-language-hint" class="description forwp-drive-import-language-wrap__hint">
								<?php esc_html_e( 'Required when the site has more than one language. Update mode lists only posts in this language.', '4wp-drive' ); ?>
							</p>
						</div>
						<div id="forwp-drive-import-source-wrap" class="forwp-drive-import-featured-wrap" hidden>
							<p id="forwp-drive-import-source-label" class="forwp-drive-import-featured-wrap__label">
								<?php esc_html_e( 'File to import', '4wp-drive' ); ?>
							</p>
							<select id="forwp-drive-import-source-file" class="forwp-drive-import-source-file" aria-labelledby="forwp-drive-import-source-label"></select>
							<p class="description forwp-drive-import-featured-wrap__hint">
								<?php esc_html_e( 'This folder has more than one document. Markdown is preferred when present.', '4wp-drive' ); ?>
							</p>
						</div>
					</div>
				</div>

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
					<div id="forwp-drive-import-post-type-wrap" class="forwp-drive-import-post-type-wrap">
						<label class="forwp-drive-import-post-type-wrap__label" for="forwp-drive-inbox-import-post-type">
							<?php esc_html_e( 'Post type', '4wp-drive' ); ?>
						</label>
						<select id="forwp-drive-inbox-import-post-type" class="forwp-drive-inbox-import-post-type"></select>
						<p class="description forwp-drive-import-post-type-wrap__hint">
							<?php esc_html_e( 'Choose where this document goes: Post, Page, Hook, or another type registered on this site.', '4wp-drive' ); ?>
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
						<label class="forwp-drive-import-target-wrap__label" for="forwp-drive-import-target">
							<?php esc_html_e( 'Target post', '4wp-drive' ); ?>
							<span class="forwp-drive-field-required" aria-hidden="true">*</span>
						</label>
						<select id="forwp-drive-import-target" class="forwp-drive-import-target" aria-describedby="forwp-drive-import-target-error forwp-drive-import-target-hint"></select>
						<p id="forwp-drive-import-target-error" class="forwp-drive-field-error" hidden></p>
						<p id="forwp-drive-import-target-hint" class="description forwp-drive-import-target-wrap__hint">
							<?php esc_html_e( 'Each option: post type · slug · title. Best match by slug (then title) is selected first. Only posts in the selected language are listed.', '4wp-drive' ); ?>
						</p>
					</div>
					<div id="forwp-drive-import-fonts-wrap" class="forwp-drive-import-fonts-wrap">
						<label class="forwp-drive-import-fonts-wrap__label">
							<input type="checkbox" id="forwp-drive-import-keep-fonts" value="1" />
							<span class="forwp-drive-import-fonts-wrap__title"><?php esc_html_e( 'Keep fonts from the document', '4wp-drive' ); ?></span>
						</label>
						<p class="description forwp-drive-import-fonts-wrap__hint">
							<?php esc_html_e( 'Off (default): use the site fonts. On: keep Google Docs typeface and size in the imported post.', '4wp-drive' ); ?>
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
	<div id="forwp-drive-busy" class="forwp-drive-busy" hidden>
		<div class="forwp-drive-busy__panel" role="alertdialog" aria-modal="true" aria-labelledby="forwp-drive-busy-message" tabindex="-1">
			<span class="spinner is-active" aria-hidden="true"></span>
			<p id="forwp-drive-busy-message"></p>
		</div>
	</div>
</div>
