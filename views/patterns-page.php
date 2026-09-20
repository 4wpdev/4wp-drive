<?php
/**
 * Admin Patterns screen — overview, then Core / Integrations / Custom.
 *
 * @package ForWP\Drive
 */

use ForWP\Drive\Blocks\Wrap_Capability_Registry;
use ForWP\Drive\Parse\Template_Config;
use ForWP\Drive\Patterns\Pattern_Library;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'forwp_drive_patterns_inline_html' ) ) {
	/**
	 * @param string $html Trusted markup with code/br/strong.
	 */
	function forwp_drive_patterns_inline_html( string $html ): string {
		return wp_kses(
			$html,
			array(
				'code'   => array(),
				'br'     => array(),
				'strong' => array(),
			)
		);
	}
}

Pattern_Library::ensure_editable_library();

$family_groups = Wrap_Capability_Registry::family_groups();
$request_url   = 'https://4wp.dev/plugin/4wp-drive/';
$rules_by_slug = array();
foreach ( Pattern_Library::get_for_rest()['rules'] as $rule ) {
	if ( ! is_array( $rule ) ) {
		continue;
	}
	$slug = sanitize_key( (string) ( $rule['preset_slug'] ?? '' ) );
	if ( '' === $slug ) {
		$slug = sanitize_key( (string) ( $rule['template'] ?? '' ) );
	}
	if ( '' !== $slug ) {
		$rules_by_slug[ $slug ] = $rule;
	}
}

$core_map = array(
	array(
		'in'  => __( 'Heading', '4wp-drive' ),
		'doc' => __( 'Heading 1–6 styles', '4wp-drive' ),
		'md'  => '<code>#</code> … <code>######</code>',
		'out' => __( 'Heading', '4wp-drive' ),
	),
	array(
		'in'   => __( 'Paragraph', '4wp-drive' ),
		'same' => __( 'Normal text', '4wp-drive' ),
		'out'  => __( 'Paragraph', '4wp-drive' ),
	),
	array(
		'in'  => __( 'List', '4wp-drive' ),
		'doc' => __( 'Bulleted or numbered list', '4wp-drive' ),
		'md'  => '<code>-</code> / <code>1.</code>',
		'out' => __( 'List', '4wp-drive' ),
	),
	array(
		'in'  => __( 'Table', '4wp-drive' ),
		'doc' => __( 'Insert table', '4wp-drive' ),
		'md'  => __( 'Pipe table', '4wp-drive' ) . ' <code>| Col | Col |</code>',
		'out' => __( 'Table', '4wp-drive' ),
	),
	array(
		'in'  => __( 'Quote', '4wp-drive' ),
		'doc' => __( 'Quote style', '4wp-drive' ),
		'md'  => '<code>&gt;</code>',
		'out' => __( 'Quote', '4wp-drive' ),
	),
	array(
		'in'   => __( 'Insert image', '4wp-drive' ),
		'doc'  => '<code>[image:hero.png left|right|center]</code>',
		'md'   => '<code>![alt](hero.png)</code><br /><code>[image:hero.png left]</code>',
		'out'  => __( 'Image', '4wp-drive' ),
		'more' => 'images',
	),
	array(
		'in'   => __( 'Code block', '4wp-drive' ),
		'doc'  => __( 'Courier New or Consolas, consecutive lines', '4wp-drive' ),
		'md'   => '<code>```php</code> … <code>```</code>',
		'out'  => __( 'Code', '4wp-drive' ),
		'more' => 'code',
	),
	array(
		'in'  => __( 'Inline code', '4wp-drive' ),
		'doc' => __( 'Courier New on the word', '4wp-drive' ),
		'md'  => '<code>`save_post`</code>',
		'out' => __( 'Code', '4wp-drive' ),
	),
	array(
		'in'   => __( 'Bold, italic, underline', '4wp-drive' ),
		'same' => __( 'The same formatting', '4wp-drive' ),
		'out'  => __( 'The same formatting', '4wp-drive' ),
	),
	array(
		'in'  => __( 'Horizontal line', '4wp-drive' ),
		'doc' => __( 'Insert → Horizontal line', '4wp-drive' ),
		'md'  => '<code>---</code>',
		'out' => __( 'Separator', '4wp-drive' ),
	),
);

$family_wrap_count    = 0;
$family_enabled_count = 0;
$family_active_count  = 0;
foreach ( $family_groups as $group ) {
	if ( ! empty( $group['active'] ) ) {
		++$family_active_count;
	}
	foreach ( $group['items'] as $item ) {
		++$family_wrap_count;
		$cap_id = sanitize_key( (string) ( $item['id'] ?? '' ) );
		$rule   = $rules_by_slug[ $cap_id ] ?? array();
		if ( ! empty( $rule['enabled'] ) ) {
			++$family_enabled_count;
		}
	}
}

$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';
if ( ! in_array( $current_tab, array( 'overview', 'core', 'integrations', 'custom' ), true ) ) {
	$current_tab = 'overview';
}
$patterns_base = admin_url( 'admin.php?page=forwp-drive-patterns' );
$tab_url       = static function ( string $tab ) use ( $patterns_base ): string {
	return add_query_arg( 'tab', $tab, $patterns_base );
};

$front_matter_sample = ( new Template_Config() )->build_sample_document();
$front_matter_url    = admin_url( 'admin.php?page=forwp-drive-settings&tab=documentation#forwp-drive-document-template' );
?>
<div class="wrap forwp-drive-admin-shell forwp-drive-patterns-page">
	<h1 class="forwp-drive-admin-heading">
		<span class="forwp-drive-admin-heading__text"><?php esc_html_e( '4WP Drive — Patterns', '4wp-drive' ); ?></span>
	</h1>

	<p class="forwp-drive-patterns__lead">
		<?php esc_html_e( 'What the document keeps when it becomes a post. Overview first, then each type.', '4wp-drive' ); ?>
	</p>

	<div class="forwp-drive-admin-app">
		<div class="forwp-drive-tab-panel components-tab-panel">
			<div class="components-tab-panel__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Pattern groups', '4wp-drive' ); ?>">
				<?php
				$tabs = array(
					'overview'     => __( 'Overview', '4wp-drive' ),
					'core'         => __( 'Core Block', '4wp-drive' ),
					'integrations' => __( 'Integrations Plugins', '4wp-drive' ),
					'custom'       => __( 'Custom Blocks', '4wp-drive' ),
				);
				foreach ( $tabs as $tab_id => $tab_label ) :
					$active = $current_tab === $tab_id;
					?>
					<a
						href="<?php echo esc_url( $tab_url( $tab_id ) ); ?>"
						role="tab"
						id="forwp-drive-tab-<?php echo esc_attr( $tab_id ); ?>"
						class="components-button components-tab-panel__tabs-item forwp-drive-tab<?php echo $active ? ' is-active' : ''; ?>"
						aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
						aria-controls="forwp-drive-panel-<?php echo esc_attr( $tab_id ); ?>"
						<?php echo $active ? '' : ' tabindex="-1"'; ?>
					><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</div>

			<div id="forwp-drive-panel-overview" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-drive-tab-overview"<?php echo 'overview' === $current_tab ? '' : ' hidden'; ?>>
				<p class="description forwp-drive-patterns__hint">
					<?php esc_html_e( 'What this site already imports. Open a type for the full list.', '4wp-drive' ); ?>
				</p>

				<section class="forwp-drive-patterns-frontmatter" aria-labelledby="forwp-drive-patterns-frontmatter-title">
					<header class="forwp-drive-patterns-frontmatter__head">
						<h2 id="forwp-drive-patterns-frontmatter-title" class="forwp-drive-patterns-frontmatter__title">
							<?php esc_html_e( 'Front-matter fields', '4wp-drive' ); ?>
						</h2>
						<a class="button button-secondary" href="<?php echo esc_url( $front_matter_url ); ?>">
							<?php esc_html_e( 'Edit template in Settings', '4wp-drive' ); ?>
						</a>
					</header>
					<p class="description">
						<?php esc_html_e( 'Use plain Label: value lines at the top of each source document, then its own paragraph with only equals signs (three or more, e.g. ===== or ======) or ---, then the post body. Author matches WordPress display name or nickname. Match existing taxonomy term names.', '4wp-drive' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'This sample follows the live field map for this site — not a stub. Change labels and mappings in Settings → Documentation.', '4wp-drive' ); ?>
					</p>
					<pre class="forwp-drive-code" id="forwp-drive-patterns-sample-template"><?php echo esc_html( $front_matter_sample ); ?></pre>
				</section>

				<div class="forwp-drive-patterns-overview">
					<article class="forwp-drive-patterns-summary">
						<header class="forwp-drive-patterns-summary__head">
							<h2 class="forwp-drive-patterns-summary__title"><?php esc_html_e( 'Core Block', '4wp-drive' ); ?></h2>
							<span class="forwp-drive-family-card__badge is-active"><?php echo esc_html( (string) count( $core_map ) ); ?></span>
						</header>
						<p class="forwp-drive-patterns-summary__text">
							<?php esc_html_e( 'Always imported. Headings, lists, tables, and images from the article folder (see Core Block for how to place them).', '4wp-drive' ); ?>
						</p>
						<ul class="forwp-drive-patterns-summary__list">
							<?php foreach ( $core_map as $row ) : ?>
								<li><?php echo esc_html( (string) $row['in'] ); ?></li>
							<?php endforeach; ?>
						</ul>
						<a class="button button-secondary" href="<?php echo esc_url( $tab_url( 'core' ) ); ?>"><?php esc_html_e( 'Details', '4wp-drive' ); ?></a>
					</article>

					<article class="forwp-drive-patterns-summary">
						<header class="forwp-drive-patterns-summary__head">
							<h2 class="forwp-drive-patterns-summary__title"><?php esc_html_e( 'Integrations Plugins', '4wp-drive' ); ?></h2>
							<span class="forwp-drive-family-card__badge<?php echo $family_active_count ? ' is-active' : ''; ?>"><?php echo esc_html( (string) count( $family_groups ) ); ?></span>
						</header>
						<p class="forwp-drive-patterns-summary__text">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: plugins in catalog, 2: active plugins, 3: wraps, 4: enabled wraps */
									__( '%1$d plugin(s) in the family list, %2$d active, %3$d wraps, %4$d enabled for auto-match.', '4wp-drive' ),
									count( $family_groups ),
									$family_active_count,
									$family_wrap_count,
									$family_enabled_count
								)
							);
							?>
						</p>
						<?php if ( empty( $family_groups ) ) : ?>
							<p class="description"><?php esc_html_e( 'No family plugins in the Drive catalog yet.', '4wp-drive' ); ?></p>
						<?php else : ?>
							<ul class="forwp-drive-patterns-summary__list">
								<?php foreach ( $family_groups as $group ) : ?>
									<li>
										<?php echo esc_html( (string) $group['label'] ); ?>
										—
										<?php echo esc_html( (string) ( $group['status'] ?? '' ) ); ?>
										<?php if ( ! empty( $group['action_url'] ) && 'active' !== ( $group['action_kind'] ?? '' ) ) : ?>
											—
											<a href="<?php echo esc_url( (string) $group['action_url'] ); ?>"><?php echo esc_html( (string) $group['action_label'] ); ?></a>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<a class="button button-secondary" href="<?php echo esc_url( $tab_url( 'integrations' ) ); ?>"><?php esc_html_e( 'Details', '4wp-drive' ); ?></a>
					</article>

					<article class="forwp-drive-patterns-summary">
						<header class="forwp-drive-patterns-summary__head">
							<h2 class="forwp-drive-patterns-summary__title"><?php esc_html_e( 'Custom Blocks', '4wp-drive' ); ?></h2>
							<span class="forwp-drive-family-card__badge"><?php esc_html_e( 'Soon', '4wp-drive' ); ?></span>
						</header>
						<p class="forwp-drive-patterns-summary__text">
							<?php esc_html_e( 'Site-owned H2 → wrap rules. Not editable in this release.', '4wp-drive' ); ?>
						</p>
						<a class="button button-secondary" href="<?php echo esc_url( $tab_url( 'custom' ) ); ?>"><?php esc_html_e( 'Details', '4wp-drive' ); ?></a>
					</article>
				</div>
			</div>

			<div id="forwp-drive-panel-core" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-drive-tab-core"<?php echo 'core' === $current_tab ? '' : ' hidden'; ?>>
				<p class="description forwp-drive-patterns__hint">
					<?php esc_html_e( 'Google Doc and Markdown are not the same write-up. The table shows a working example for each. Details under How.', '4wp-drive' ); ?>
				</p>

				<table class="widefat forwp-drive-patterns__map-table forwp-drive-auto-map">
					<thead>
						<tr>
							<th><?php esc_html_e( 'What', '4wp-drive' ); ?></th>
							<th><?php esc_html_e( 'Write it', '4wp-drive' ); ?></th>
							<th><?php esc_html_e( 'In the post', '4wp-drive' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $core_map as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row['in'] ); ?></td>
								<td>
									<?php if ( ! empty( $row['same'] ) ) : ?>
										<?php echo forwp_drive_patterns_inline_html( (string) $row['same'] ); ?>
									<?php else : ?>
										<div class="forwp-drive-patterns__map-write">
											<div class="forwp-drive-patterns__src">
												<span class="forwp-drive-patterns__src-k"><?php esc_html_e( 'Doc', '4wp-drive' ); ?></span>
												<span><?php echo forwp_drive_patterns_inline_html( (string) ( $row['doc'] ?? '' ) ); ?></span>
											</div>
											<div class="forwp-drive-patterns__src">
												<span class="forwp-drive-patterns__src-k"><?php esc_html_e( 'MD', '4wp-drive' ); ?></span>
												<span><?php echo forwp_drive_patterns_inline_html( (string) ( $row['md'] ?? '' ) ); ?></span>
											</div>
										</div>
									<?php endif; ?>
									<?php if ( ! empty( $row['more'] ) ) : ?>
										<a class="forwp-drive-patterns__more" href="#forwp-drive-howto-<?php echo esc_attr( (string) $row['more'] ); ?>">
											<?php esc_html_e( 'How', '4wp-drive' ); ?>
										</a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $row['out'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div id="forwp-drive-howto-images" class="forwp-drive-patterns-howto">
					<h2 class="forwp-drive-patterns-howto__title"><?php esc_html_e( 'Insert image', '4wp-drive' ); ?></h2>
					<p><?php esc_html_e( 'File sits in the same folder as the article. The name in the marker must match the file (hero.png, not a pasted picture).', '4wp-drive' ); ?></p>
					<p><strong><?php esc_html_e( 'Google Doc', '4wp-drive' ); ?></strong> — <?php esc_html_e( 'one paragraph, only the marker. Align is optional: left, right, or center.', '4wp-drive' ); ?></p>
					<pre class="forwp-drive-code">[image:hero.png]
[image:diagram.jpg left]</pre>
					<p><strong><?php esc_html_e( 'Markdown', '4wp-drive' ); ?></strong> — <?php esc_html_e( 'normal Markdown image, or the same marker.', '4wp-drive' ); ?></p>
					<pre class="forwp-drive-code">![Hero](hero.png)
[image:diagram.jpg left]</pre>
					<p><?php esc_html_e( 'Does not work: paste into the Doc, https:// URLs, [IMAGE 2] prompts. Featured image is Incoming (cover / hero / first file) — not this marker.', '4wp-drive' ); ?></p>
				</div>

				<div id="forwp-drive-howto-code" class="forwp-drive-patterns-howto">
					<h2 class="forwp-drive-patterns-howto__title"><?php esc_html_e( 'Code block', '4wp-drive' ); ?></h2>
					<p><strong><?php esc_html_e( 'Google Doc', '4wp-drive' ); ?></strong> — <?php esc_html_e( 'select the lines and set the font to Courier New or Consolas. Consecutive monospace lines become one code block. Do not mix with normal text in the same paragraph. There is no ``` fence in Docs.', '4wp-drive' ); ?></p>
					<p><strong><?php esc_html_e( 'Markdown', '4wp-drive' ); ?></strong> — <?php esc_html_e( 'fenced block. The language tag is for you; Drive still imports it as a code block.', '4wp-drive' ); ?></p>
					<pre class="forwp-drive-code">```php
add_filter( 'cron_schedules', function( $schedules ) {
	return $schedules;
} );
```</pre>
					<p><?php esc_html_e( 'Inline: Markdown uses `code`. In a Google Doc, apply Courier New to that word only.', '4wp-drive' ); ?></p>
				</div>

				<p class="description forwp-drive-patterns__hint">
					<?php esc_html_e( 'Need another document type imported? Send a request — we can add it.', '4wp-drive' ); ?>
				</p>
				<p class="forwp-drive-patterns-actions">
					<a class="button" href="<?php echo esc_url( $request_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Request another type', '4wp-drive' ); ?>
					</a>
				</p>
			</div>

			<div id="forwp-drive-panel-integrations" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-drive-tab-integrations"<?php echo 'integrations' === $current_tab ? '' : ' hidden'; ?>>
				<p class="description forwp-drive-patterns__hint">
					<?php esc_html_e( 'Drive keeps this plugin list in code so you can add another sibling later. Activate a plugin to edit its H2 wraps. Install or activate from the card if it is not running yet.', '4wp-drive' ); ?>
				</p>
				<?php if ( empty( $family_groups ) ) : ?>
					<p class="description"><?php esc_html_e( 'The family catalog is empty.', '4wp-drive' ); ?></p>
				<?php else : ?>
					<div class="forwp-drive-family-grid">
						<?php foreach ( $family_groups as $group ) : ?>
							<section class="forwp-drive-family-card">
								<header class="forwp-drive-family-card__head">
									<h2 class="forwp-drive-family-card__title"><?php echo esc_html( (string) $group['label'] ); ?></h2>
									<span class="forwp-drive-family-card__badge<?php echo ! empty( $group['active'] ) ? ' is-active' : ''; ?>">
										<?php echo esc_html( (string) ( $group['status'] ?? '' ) ); ?>
									</span>
								</header>
								<?php if ( ! empty( $group['description'] ) ) : ?>
									<p class="forwp-drive-family-card__text"><?php echo esc_html( (string) $group['description'] ); ?></p>
								<?php endif; ?>
								<p class="forwp-drive-family-card__actions">
									<?php if ( ! empty( $group['action_url'] ) ) : ?>
										<a
											class="button <?php echo 'activate' === ( $group['action_kind'] ?? '' ) || 'install' === ( $group['action_kind'] ?? '' ) ? 'button-primary' : 'button-secondary'; ?>"
											href="<?php echo esc_url( (string) $group['action_url'] ); ?>"
											<?php echo in_array( ( $group['action_kind'] ?? '' ), array( 'get', 'install' ), true ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
										><?php echo esc_html( (string) $group['action_label'] ); ?></a>
									<?php endif; ?>
									<?php if ( ! empty( $group['uri'] ) && 'get' !== ( $group['action_kind'] ?? '' ) ) : ?>
										<a class="button button-link" href="<?php echo esc_url( (string) $group['uri'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Plugin page', '4wp-drive' ); ?></a>
									<?php endif; ?>
								</p>
								<?php if ( ! empty( $group['items'] ) ) : ?>
								<ul class="forwp-drive-family-card__list">
									<?php foreach ( $group['items'] as $item ) : ?>
										<?php
										$cap_id = sanitize_key( (string) ( $item['id'] ?? '' ) );
										$rule   = $rules_by_slug[ $cap_id ] ?? array();
										$heads  = (string) ( $rule['section_headings'] ?? $item['heading_seeds'] ?? '' );
										$on     = ! empty( $rule['enabled'] );
										$keep   = array_key_exists( 'keep_section_heading', $rule ) ? ! empty( $rule['keep_section_heading'] ) : true;
										$pid    = (int) ( $rule['post_id'] ?? 0 );
										?>
										<li class="forwp-drive-family-rule<?php echo $on ? '' : ' is-off'; ?>" data-cap-id="<?php echo esc_attr( $cap_id ); ?>">
											<input type="hidden" class="forwp-drive-family-rule__post-id" value="<?php echo esc_attr( (string) $pid ); ?>" />
											<input type="hidden" class="forwp-drive-family-rule__template" value="<?php echo esc_attr( $cap_id ); ?>" />
											<label class="forwp-drive-family-rule__on">
												<input type="checkbox" class="forwp-drive-family-rule__enabled" <?php checked( $on ); ?> <?php disabled( empty( $group['active'] ) ); ?> />
												<span>
													<strong><?php echo esc_html( (string) ( $item['label'] ?? $cap_id ) ); ?></strong>
												</span>
											</label>
											<div class="forwp-drive-family-rule__row">
												<label class="forwp-drive-family-rule__h2">
													<span><?php esc_html_e( 'Titles in the document (comma-separated)', '4wp-drive' ); ?></span>
													<input type="text" class="regular-text forwp-drive-family-rule__headings" value="<?php echo esc_attr( $heads ); ?>" placeholder="<?php echo esc_attr( (string) ( $item['heading_seeds'] ?? '' ) ); ?>" <?php disabled( ! $on ); ?> />
												</label>
												<label class="forwp-drive-family-rule__keep">
													<input type="checkbox" class="forwp-drive-family-rule__keep-heading" <?php checked( $keep ); ?> <?php disabled( ! $on ); ?> />
													<?php esc_html_e( 'Keep the title in the post', '4wp-drive' ); ?>
												</label>
											</div>
										</li>
									<?php endforeach; ?>
								</ul>
								<?php elseif ( ! empty( $group['wrap_labels'] ) ) : ?>
									<ul class="forwp-drive-family-card__preview">
										<?php foreach ( $group['wrap_labels'] as $wrap_label ) : ?>
											<li><?php echo esc_html( (string) $wrap_label ); ?></li>
										<?php endforeach; ?>
									</ul>
									<p class="description"><?php esc_html_e( 'Activate the plugin to map these headings on import.', '4wp-drive' ); ?></p>
								<?php endif; ?>
							</section>
						<?php endforeach; ?>
					</div>
					<?php
					$can_save_family = false;
					foreach ( $family_groups as $group ) {
						if ( ! empty( $group['active'] ) && ! empty( $group['items'] ) ) {
							$can_save_family = true;
							break;
						}
					}
					?>
					<?php if ( $can_save_family ) : ?>
					<p class="forwp-drive-patterns-actions">
						<button type="button" class="button button-primary" id="forwp-drive-save-patterns"><?php esc_html_e( 'Save', '4wp-drive' ); ?></button>
					</p>
					<p id="forwp-drive-patterns-status" class="forwp-drive-status" aria-live="polite"></p>
					<?php endif; ?>
				<?php endif; ?>

				<div class="forwp-drive-patterns-howto">
					<h2 class="forwp-drive-patterns-howto__title"><?php esc_html_e( 'How to use', '4wp-drive' ); ?></h2>
					<ol class="forwp-drive-patterns-howto__list">
						<li><?php esc_html_e( 'Each card is a plugin from Drive’s family catalog. Active = it can import its sections.', '4wp-drive' ); ?></li>
						<li><?php esc_html_e( 'Not installed or inactive: use Get plugin / Activate on the card.', '4wp-drive' ); ?></li>
						<li><?php esc_html_e( 'The checkbox turns that section on. Off = skip it on import.', '4wp-drive' ); ?></li>
						<li><?php esc_html_e( 'The title field is the Heading 2 text in the document (any language). Several titles: comma-separated.', '4wp-drive' ); ?></li>
						<li><?php esc_html_e( 'Keep the title in the post = leave that heading visible. Uncheck to hide it after wrapping.', '4wp-drive' ); ?></li>
						<li><?php esc_html_e( 'You can still wrap a section by hand in Incoming, without matching the title.', '4wp-drive' ); ?></li>
					</ol>
				</div>

				<div class="forwp-drive-intro-card forwp-drive-patterns-request">
					<h2 class="forwp-drive-intro-card__title"><?php esc_html_e( 'Another plugin?', '4wp-drive' ); ?></h2>
					<p class="forwp-drive-intro-card__text">
						<?php esc_html_e( 'Need a third-party or 4WP plugin imported the same way? Send a request.', '4wp-drive' ); ?>
					</p>
					<p class="forwp-drive-intro-card__cta">
						<a class="button button-secondary" href="<?php echo esc_url( $request_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Request a plugin', '4wp-drive' ); ?>
						</a>
					</p>
				</div>
			</div>

			<div id="forwp-drive-panel-custom" role="tabpanel" class="components-tab-panel__tab-content" aria-labelledby="forwp-drive-tab-custom"<?php echo 'custom' === $current_tab ? '' : ' hidden'; ?>>
				<div class="forwp-drive-intro-card">
					<h2 class="forwp-drive-intro-card__title"><?php esc_html_e( 'Coming soon', '4wp-drive' ); ?></h2>
					<p class="forwp-drive-intro-card__text">
						<?php esc_html_e( 'Site-owned patterns (your own H2 → wrap rules) are not ready to edit here yet. Until then, use Core import and Integrations from 4WP plugins.', '4wp-drive' ); ?>
					</p>
				</div>
			</div>
		</div>
	</div>
</div>
