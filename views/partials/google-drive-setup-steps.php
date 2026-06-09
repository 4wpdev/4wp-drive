<?php
/**
 * Google Drive provider setup steps (shared by Documentation and Storage sources hint).
 *
 * @package ForWP\Drive
 * @var string               $redirect_uri         OAuth redirect URI for Google Cloud.
 * @var array<string,string> $setup_links          Google Cloud / OAuth doc URLs.
 * @var bool                 $setup_steps_show_title Whether to render the section title (embedded hint).
 */

defined( 'ABSPATH' ) || exit;

$redirect_uri         = isset( $redirect_uri ) ? $redirect_uri : '';
$setup_links          = isset( $setup_links ) && is_array( $setup_links ) ? $setup_links : array();
$setup_steps_show_title = ! empty( $setup_steps_show_title );
?>
<div class="forwp-drive-setup-steps">
	<?php if ( $setup_steps_show_title ) : ?>
		<p class="forwp-drive-setup-steps__title">
			<strong><?php esc_html_e( 'Google Drive — create API credentials', '4wp-drive' ); ?></strong>
		</p>
	<?php endif; ?>
	<ol class="forwp-drive-steps">
		<li>
			<?php
			printf(
				wp_kses_post(
					/* translators: %s: URL to Google Cloud Console */
					__( 'Open the <a href="%s" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> and select (or create) a project.', '4wp-drive' )
				),
				esc_url( $setup_links['console'] ?? '' )
			);
			?>
		</li>
		<li>
			<?php
			printf(
				wp_kses_post(
					/* translators: %s: URL to enable Google Drive API in Cloud Console */
					__( 'Enable the <a href="%s" target="_blank" rel="noopener noreferrer">Google Drive API</a> for this project.', '4wp-drive' )
				),
				esc_url( $setup_links['drive_api'] ?? '' )
			);
			?>
		</li>
		<li>
			<?php
			printf(
				wp_kses_post(
					/* translators: %s: URL to OAuth credentials page in Cloud Console */
					__( 'Go to <a href="%s" target="_blank" rel="noopener noreferrer">Credentials</a> → Create credentials → OAuth client ID.', '4wp-drive' )
				),
				esc_url( $setup_links['credentials'] ?? '' )
			);
			?>
		</li>
		<li><?php esc_html_e( 'Application type: Web application.', '4wp-drive' ); ?></li>
		<li>
			<?php esc_html_e( 'Authorized redirect URI — copy the value below (if Google rejects your domain, use the suggested loopback URI on the Storage sources tab):', '4wp-drive' ); ?>
			<code class="forwp-drive-redirect-uri"><?php echo esc_html( $redirect_uri ); ?></code>
			<button type="button" class="button button-small forwp-drive-copy-redirect"><?php esc_html_e( 'Copy', '4wp-drive' ); ?></button>
		</li>
		<li class="forwp-drive-local-dev-note" hidden>
			<strong><?php esc_html_e( 'Local development:', '4wp-drive' ); ?></strong>
			<?php
			printf(
				' %s',
				esc_html(
					sprintf(
						/* translators: %s: wp-admin URL of this site */
						__( 'Use %s for settings and Connect. Google may redirect through a loopback address briefly, then you return here automatically.', '4wp-drive' ),
						admin_url()
					)
				)
			);
			?>
		</li>
		<li><?php esc_html_e( 'Paste the Client ID and Client Secret into Storage sources → Google Drive, then connect and set folder IDs.', '4wp-drive' ); ?></li>
	</ol>
	<p class="description">
		<a href="<?php echo esc_url( $setup_links['oauth_doc'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google OAuth documentation', '4wp-drive' ); ?></a>
	</p>
</div>
