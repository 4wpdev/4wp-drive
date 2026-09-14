<?php
/**
 * GitHub provider setup steps (Storage sources detail + Documentation).
 *
 * @package ForWP\Drive
 * @var bool $setup_steps_show_title Whether to render the section title.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Partial template locals.

$setup_steps_show_title = ! empty( $setup_steps_show_title );

$tokens_url = 'https://github.com/settings/tokens';
$docs_url   = 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens';
$scopes_url = 'https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/scopes-for-oauth-apps#available-scopes';
?>
<div class="forwp-drive-setup-steps forwp-drive-setup-steps--github">
	<?php if ( $setup_steps_show_title ) : ?>
		<p class="forwp-drive-setup-steps__title">
			<strong><?php esc_html_e( 'GitHub — create a personal access token', '4wp-drive' ); ?></strong>
		</p>
	<?php endif; ?>
	<ol class="forwp-drive-steps">
		<li>
			<?php
			printf(
				wp_kses_post(
					/* translators: %s: URL to GitHub token settings */
					__( 'Open <a href="%s" target="_blank" rel="noopener noreferrer">GitHub → Settings → Developer settings → Personal access tokens</a>.', '4wp-drive' )
				),
				esc_url( $tokens_url )
			);
			?>
		</li>
		<li>
			<?php
			printf(
				wp_kses_post(
					/* translators: %s: URL to GitHub PAT docs */
					__( 'Create a <strong>classic</strong> token (or fine-grained with Contents read/write on the target repo). See <a href="%s" target="_blank" rel="noopener noreferrer">GitHub’s PAT guide</a>.', '4wp-drive' )
				),
				esc_url( $docs_url )
			);
			?>
		</li>
		<li>
			<?php
			printf(
				wp_kses_post(
					/* translators: %s: URL to GitHub scopes docs */
					__( 'Grant the <code>repo</code> scope so 4WP Drive can read/write the Incoming path (repo root by default) plus <code>published/</code> and <code>failed/</code>. Scope reference: <a href="%s" target="_blank" rel="noopener noreferrer">GitHub scopes</a>.', '4wp-drive' )
				),
				esc_url( $scopes_url )
			);
			?>
		</li>
		<li><?php esc_html_e( 'Copy the token once (GitHub shows it only at creation), then paste Owner, Repository, Branch, Incoming path, and the token below.', '4wp-drive' ); ?></li>
		<li><?php esc_html_e( 'Save GitHub settings. The Source registry card turns green (On) when owner, repo, and token are set.', '4wp-drive' ); ?></li>
	</ol>
</div>
