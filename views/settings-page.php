<?php
/**
 * Admin settings template (shell + tabs + source registry, aligned with 4WP Weather).
 *
 * @package ForWP\Drive
 */

use ForWP\Drive\Auth\Google_OAuth;

defined( 'ABSPATH' ) || exit;

$oauth         = Google_OAuth::instance();
$redirect_uri  = $oauth->get_redirect_uri();
$setup_links   = Google_OAuth::get_setup_links();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$connected     = isset( $_GET['connected'] ) && '1' === $_GET['connected'];
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$oauth_error   = isset( $_GET['oauth_error'] ) ? sanitize_key( wp_unslash( $_GET['oauth_error'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$oauth_message = isset( $_GET['oauth_message'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_message'] ) ) : '';

$heading_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" focusable="false" aria-hidden="true"><path d="M7 18a4.5 4.5 0 0 1 0-9 5.5 5.5 0 0 1 10.9-1.1A4 4 0 0 1 19 18H7Z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/></svg>';
?>
<div class="wrap forwp-drive-admin-shell">
	<?php if ( $connected ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Google Drive connected successfully.', '4wp-drive' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $oauth_error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p>
				<?php
				if ( 'access_denied' === $oauth_error ) {
					esc_html_e( 'Google authorization was cancelled.', '4wp-drive' );
				} elseif ( '' !== $oauth_message ) {
					echo esc_html( rawurldecode( $oauth_message ) );
				} else {
					esc_html_e( 'Google authorization failed. Check credentials and redirect URI.', '4wp-drive' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<h1 class="forwp-drive-admin-heading">
		<span class="forwp-drive-admin-heading__icon" aria-hidden="true">
			<?php echo wp_kses( $heading_svg, array( 'svg' => array( 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'focusable' => true, 'aria-hidden' => true ), 'path' => array( 'd' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linejoin' => true ) ) ); ?>
		</span>
		<span class="forwp-drive-admin-heading__text"><?php esc_html_e( '4WP Drive', '4wp-drive' ); ?></span>
	</h1>

	<div id="forwp-drive-settings-status" class="forwp-drive-status forwp-drive-status--global" aria-live="polite"></div>

	<div class="forwp-drive-admin-app">
		<div class="forwp-drive-tab-panel components-tab-panel">
			<div class="components-tab-panel__tabs" role="tablist" aria-label="<?php esc_attr_e( '4WP Drive settings', '4wp-drive' ); ?>">
				<button type="button" role="tab" id="forwp-drive-tab-sources" class="components-button components-tab-panel__tabs-item forwp-drive-tab is-active" aria-selected="true" aria-controls="forwp-drive-panel-sources" data-tab="sources">
					<?php esc_html_e( 'Storage sources', '4wp-drive' ); ?>
				</button>
				<button type="button" role="tab" id="forwp-drive-tab-documentation" class="components-button components-tab-panel__tabs-item forwp-drive-tab" aria-selected="false" aria-controls="forwp-drive-panel-documentation" data-tab="documentation" tabindex="-1">
					<?php esc_html_e( 'Documentation', '4wp-drive' ); ?>
				</button>
			</div>

			<div id="forwp-drive-panel-sources" role="tabpanel" aria-labelledby="forwp-drive-tab-sources" class="components-tab-panel__tab-content">
				<div id="forwp-drive-sources-intro" class="forwp-drive-intro-card">
					<div class="forwp-drive-intro-card__body">
						<h3 class="forwp-drive-intro-card__title"><?php esc_html_e( 'About storage sources', '4wp-drive' ); ?></h3>
						<p class="forwp-drive-intro-card__text">
							<?php esc_html_e( 'Import documents from cloud storage into WordPress drafts. Live: Google Drive. Planned: GitHub, OneDrive, Dropbox.', '4wp-drive' ); ?>
						</p>
						<p class="forwp-drive-intro-card__text">
							<?php esc_html_e( 'Open a card below to configure Google Drive or view roadmap status.', '4wp-drive' ); ?>
						</p>
						<p class="forwp-drive-intro-card__cta">
							<?php esc_html_e( 'Pick a provider below, then open Documentation for the shared document template.', '4wp-drive' ); ?>
						</p>
					</div>
				</div>

				<div id="forwp-drive-source-detail-wrap" class="forwp-drive-source-detail-wrap" hidden>
					<div class="forwp-drive-provider-detail-head">
						<h3 class="forwp-drive-admin-section-title forwp-drive-provider-detail-head__title" id="forwp-drive-source-detail-title"><?php esc_html_e( 'Source', '4wp-drive' ); ?></h3>
						<button type="button" class="button" id="forwp-drive-source-back">
							<?php esc_html_e( '← Back to overview', '4wp-drive' ); ?>
						</button>
					</div>

					<div id="forwp-drive-planned-detail" class="forwp-drive-planned-detail" hidden>
						<div class="notice notice-info inline">
							<p id="forwp-drive-planned-detail-text"></p>
						</div>
					</div>

					<div id="forwp-drive-google-split" class="forwp-drive-google-layout">
						<div class="forwp-drive-admin-panel forwp-drive-admin-panel--embedded" id="forwp-drive-google-detail">
							<div class="forwp-drive-google-row forwp-drive-google-row--two">
								<div class="forwp-drive-panel forwp-drive-panel--nested forwp-drive-panel--compact">
									<h2><?php esc_html_e( 'API credentials', '4wp-drive' ); ?></h2>
									<p id="forwp-drive-credentials-locked" class="forwp-drive-notice-inline" hidden>
										<?php esc_html_e( 'Credentials are locked via wp-config.php constants and cannot be edited here.', '4wp-drive' ); ?>
									</p>
									<table class="form-table" role="presentation">
										<tr>
											<th scope="row"><label for="forwp-drive-client-id"><?php esc_html_e( 'Client ID', '4wp-drive' ); ?></label></th>
											<td><input type="text" class="large-text code" id="forwp-drive-client-id" autocomplete="off" /></td>
										</tr>
										<tr>
											<th scope="row"><label for="forwp-drive-client-secret"><?php esc_html_e( 'Client Secret', '4wp-drive' ); ?></label></th>
											<td>
												<input type="password" class="large-text code" id="forwp-drive-client-secret" autocomplete="new-password" />
												<p class="description" id="forwp-drive-secret-hint"></p>
											</td>
										</tr>
									</table>
									<p class="forwp-drive-inline-actions">
										<button type="button" class="button button-primary" id="forwp-drive-save-credentials"><?php esc_html_e( 'Save credentials', '4wp-drive' ); ?></button>
									</p>
								</div>
								<div class="forwp-drive-panel forwp-drive-panel--nested forwp-drive-panel--compact">
									<h2><?php esc_html_e( 'Connect', '4wp-drive' ); ?></h2>
									<p id="forwp-drive-connection-line" class="description forwp-drive-connection-line"></p>
									<p class="forwp-drive-inline-actions">
										<a href="#" class="button button-primary" id="forwp-drive-connect" hidden><?php esc_html_e( 'Connect Google Drive', '4wp-drive' ); ?></a>
										<button type="button" class="button" id="forwp-drive-disconnect" hidden><?php esc_html_e( 'Disconnect', '4wp-drive' ); ?></button>
									</p>
								</div>
							</div>

							<div class="forwp-drive-panel forwp-drive-panel--nested forwp-drive-panel--compact forwp-drive-oauth-row" id="forwp-drive-oauth-redirect-panel">
								<h2><?php esc_html_e( 'OAuth redirect (local)', '4wp-drive' ); ?></h2>
								<p class="description forwp-drive-oauth-desc" id="forwp-drive-oauth-redirect-desc">
									<?php esc_html_e( 'For .localhost hosts use 127.0.0.1:PORT with the same path as in Local.', '4wp-drive' ); ?>
								</p>
								<p class="forwp-drive-notice-inline" id="forwp-drive-wpconfig-redirect-note" hidden>
									<?php esc_html_e( 'Redirect URI is locked in wp-config.php (FORWP_DRIVE_OAUTH_REDIRECT_URI). Register that exact value in Google Cloud; the field below is ignored.', '4wp-drive' ); ?>
								</p>
								<div class="forwp-drive-oauth-inline">
									<input type="url" class="large-text code" id="forwp-drive-oauth-redirect" placeholder="http://127.0.0.1:10033/wp-admin/admin.php?page=forwp-drive-oauth" />
									<span class="forwp-drive-oauth-inline__btns">
										<button type="button" class="button button-small" id="forwp-drive-use-suggested-redirect" hidden><?php esc_html_e( 'Use suggested', '4wp-drive' ); ?></button>
										<button type="button" class="button button-primary" id="forwp-drive-save-oauth-redirect"><?php esc_html_e( 'Save', '4wp-drive' ); ?></button>
									</span>
								</div>
								<p class="description" id="forwp-drive-oauth-redirect-suggested" hidden></p>
							</div>

							<div class="forwp-drive-google-row forwp-drive-google-row--two forwp-drive-google-row--status-folders">
								<div class="forwp-drive-panel forwp-drive-panel--nested forwp-drive-panel--compact forwp-drive-panel--status">
									<h2 class="forwp-drive-preview__caption forwp-drive-preview__caption--inline"><?php esc_html_e( 'Connection status', '4wp-drive' ); ?></h2>
									<div class="forwp-drive-preview__frame forwp-drive-preview__frame--compact">
										<div class="forwp-drive-preview__chrome">
											<span class="forwp-drive-preview__dot"></span>
											<span class="forwp-drive-preview__dot"></span>
											<span class="forwp-drive-preview__dot"></span>
											<span class="forwp-drive-preview__chrome-label"><?php esc_html_e( 'Google Drive', '4wp-drive' ); ?></span>
										</div>
										<div class="forwp-drive-preview__card">
											<div id="forwp-drive-folder-ids" class="forwp-drive-folder-ids forwp-drive-folder-ids--card" hidden></div>
											<p class="forwp-drive-preview__hint description" id="forwp-drive-status-preview-hint"></p>
										</div>
									</div>
									<div
										id="forwp-drive-drive-actions-status"
										class="forwp-drive-status forwp-drive-status--drive-actions"
										role="status"
										aria-live="polite"
										hidden
									></div>
								</div>
								<div class="forwp-drive-panel forwp-drive-panel--nested forwp-drive-panel--compact">
									<h2><?php esc_html_e( 'Drive folders', '4wp-drive' ); ?></h2>
									<p class="description"><?php esc_html_e( 'Root folder ID — incoming / published / failed are created under it.', '4wp-drive' ); ?></p>
									<table class="form-table" role="presentation">
										<tr>
											<th scope="row"><label for="forwp-drive-root-folder"><?php esc_html_e( 'Root folder ID', '4wp-drive' ); ?></label></th>
											<td>
												<input type="text" class="regular-text" id="forwp-drive-root-folder" />
												<p class="description"><?php esc_html_e( 'drive.google.com/.../folders/FOLDER_ID', '4wp-drive' ); ?></p>
											</td>
										</tr>
									</table>
									<div class="forwp-drive-actions">
										<button type="button" class="button button-primary" id="forwp-drive-save-folders" disabled><?php esc_html_e( 'Save & create subfolders', '4wp-drive' ); ?></button>
										<button type="button" class="button" id="forwp-drive-run-sync" disabled><?php esc_html_e( 'Run sync now', '4wp-drive' ); ?></button>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<h3 class="forwp-drive-admin-section-title forwp-drive-registry-title"><?php esc_html_e( 'Source registry', '4wp-drive' ); ?></h3>
				<p class="forwp-drive-admin-muted forwp-drive-registry-hint"><?php esc_html_e( 'Click a card to open provider settings or roadmap details.', '4wp-drive' ); ?></p>
				<div id="forwp-drive-source-registry-grid" class="forwp-drive-provider-grid" aria-live="polite"></div>
			</div>

			<div id="forwp-drive-panel-documentation" role="tabpanel" aria-labelledby="forwp-drive-tab-documentation" class="components-tab-panel__tab-content" hidden>
				<div class="forwp-drive-docs-layout">
					<div class="forwp-drive-docs-layout__main">
						<div class="forwp-drive-docs-intro">
							<p class="forwp-drive-admin-muted">
								<?php esc_html_e( 'Setup depends on which storage source you use. Configure providers under Storage sources, then map how each document becomes a WordPress draft below.', '4wp-drive' ); ?>
							</p>
						</div>

						<div class="forwp-drive-accordion" id="forwp-drive-docs-accordion">
							<details class="forwp-drive-accordion__item">
								<summary class="forwp-drive-accordion__summary">
									<span class="forwp-drive-accordion__title"><?php esc_html_e( 'Connect a storage source', '4wp-drive' ); ?></span>
									<span class="forwp-drive-accordion__hint"><?php esc_html_e( 'Provider-specific steps', '4wp-drive' ); ?></span>
								</summary>
								<div class="forwp-drive-accordion__panel">
									<p class="description">
										<?php esc_html_e( 'Google Drive is available today. GitHub (Markdown/MDX), OneDrive, and Dropbox are on the roadmap — see Source registry on the Storage sources tab.', '4wp-drive' ); ?>
									</p>

									<details class="forwp-drive-accordion__item forwp-drive-accordion__item--nested">
										<summary class="forwp-drive-accordion__summary">
											<span class="forwp-drive-accordion__title"><?php esc_html_e( 'Google Drive — create API credentials', '4wp-drive' ); ?></span>
										</summary>
										<div class="forwp-drive-accordion__panel">
											<ol class="forwp-drive-steps">
												<li>
													<?php
													printf(
														wp_kses_post( __( 'Open the <a href="%s" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> and select (or create) a project.', '4wp-drive' ) ),
														esc_url( $setup_links['console'] )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														wp_kses_post( __( 'Enable the <a href="%s" target="_blank" rel="noopener noreferrer">Google Drive API</a> for this project.', '4wp-drive' ) ),
														esc_url( $setup_links['drive_api'] )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														wp_kses_post( __( 'Go to <a href="%s" target="_blank" rel="noopener noreferrer">Credentials</a> → Create credentials → OAuth client ID.', '4wp-drive' ) ),
														esc_url( $setup_links['credentials'] )
													);
													?>
												</li>
												<li><?php esc_html_e( 'Application type: Web application.', '4wp-drive' ); ?></li>
												<li>
													<?php esc_html_e( 'Authorized redirect URI — copy the value below (use 127.0.0.1:PORT if Google rejects .localhost):', '4wp-drive' ); ?>
													<code class="forwp-drive-redirect-uri" id="forwp-drive-redirect-uri"><?php echo esc_html( $redirect_uri ); ?></code>
													<button type="button" class="button button-small" id="forwp-drive-copy-redirect"><?php esc_html_e( 'Copy', '4wp-drive' ); ?></button>
												</li>
												<li class="forwp-drive-local-dev-note" id="forwp-drive-local-dev-note" hidden>
													<strong><?php esc_html_e( 'Local (taxspoc):', '4wp-drive' ); ?></strong>
													<?php esc_html_e( 'Use http://taxspoc.localhost/wp-admin/ for settings and Connect. Google redirects to 127.0.0.1:PORT briefly, then you return here automatically.', '4wp-drive' ); ?>
												</li>
												<li><?php esc_html_e( 'Paste the Client ID and Client Secret into Storage sources → Google Drive, then connect and set folder IDs.', '4wp-drive' ); ?></li>
											</ol>
											<p class="description">
												<a href="<?php echo esc_url( $setup_links['oauth_doc'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google OAuth documentation', '4wp-drive' ); ?></a>
											</p>
										</div>
									</details>
								</div>
							</details>

							<details class="forwp-drive-accordion__item" open>
								<summary class="forwp-drive-accordion__summary">
									<span class="forwp-drive-accordion__title"><?php esc_html_e( 'Document template & import mapping', '4wp-drive' ); ?></span>
									<span class="forwp-drive-accordion__hint"><?php esc_html_e( 'Same format for every source', '4wp-drive' ); ?></span>
								</summary>
								<div class="forwp-drive-accordion__panel">
									<table class="form-table" role="presentation">
										<tr>
											<th scope="row"><label for="forwp-drive-import-post-type"><?php esc_html_e( 'Import into post type', '4wp-drive' ); ?></label></th>
											<td>
												<select id="forwp-drive-import-post-type"></select>
												<p class="description"><?php esc_html_e( 'Draft posts are created in this post type. Taxonomy fields below depend on the selected type.', '4wp-drive' ); ?></p>
											</td>
										</tr>
									</table>

									<h3><?php esc_html_e( 'Front-matter fields', '4wp-drive' ); ?></h3>
									<p class="description"><?php esc_html_e( 'Use plain Label: value lines at the top of each source document, then its own paragraph with only equals signs (three or more, e.g. ===== or ======) or ---, then the post body. Author matches WordPress display name or nickname. Match existing taxonomy term names.', '4wp-drive' ); ?></p>
									<table class="widefat forwp-drive-template-table" id="forwp-drive-template-table">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Label in document', '4wp-drive' ); ?></th>
												<th><?php esc_html_e( 'Maps to', '4wp-drive' ); ?></th>
												<th></th>
											</tr>
										</thead>
										<tbody id="forwp-drive-template-rows"></tbody>
									</table>
									<p>
										<button type="button" class="button" id="forwp-drive-template-add-row"><?php esc_html_e( 'Add field', '4wp-drive' ); ?></button>
										<button type="button" class="button button-primary" id="forwp-drive-save-import-template"><?php esc_html_e( 'Save import settings', '4wp-drive' ); ?></button>
									</p>

									<h3><?php esc_html_e( 'Example document header', '4wp-drive' ); ?></h3>
									<pre class="forwp-drive-code" id="forwp-drive-sample-template"></pre>
									<p class="description"><?php esc_html_e( 'Place files with this header in your provider’s incoming folder (e.g. Google Drive incoming).', '4wp-drive' ); ?></p>
								</div>
							</details>

							<details class="forwp-drive-accordion__item">
								<summary class="forwp-drive-accordion__summary">
									<span class="forwp-drive-accordion__title"><?php esc_html_e( 'WP-CLI', '4wp-drive' ); ?></span>
									<span class="forwp-drive-accordion__hint"><?php esc_html_e( 'Manual sync', '4wp-drive' ); ?></span>
								</summary>
								<div class="forwp-drive-accordion__panel" id="forwp-drive-docs-cli">
									<p><?php esc_html_e( 'Run a manual sync from the terminal:', '4wp-drive' ); ?></p>
									<pre class="forwp-drive-cli-snippet"><code>wp forwp-drive sync</code></pre>
								</div>
							</details>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
