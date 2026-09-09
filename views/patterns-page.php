<?php
/**
 * Admin Patterns screen — automatic core blocks vs extra H2 patterns.
 *
 * @package ForWP\Drive
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap forwp-drive-admin-shell forwp-drive-patterns-page">
	<h1 class="forwp-drive-admin-heading">
		<span class="forwp-drive-admin-heading__text"><?php esc_html_e( '4WP Drive — Patterns', '4wp-drive' ); ?></span>
	</h1>

	<div id="forwp-drive-patterns-status" class="forwp-drive-status forwp-drive-status--global" aria-live="polite"></div>

	<div class="forwp-drive-admin-app">
		<div class="forwp-drive-patterns-panel">
			<p class="forwp-drive-patterns__lead">
				<?php esc_html_e( 'Import always turns normal document structure into core Gutenberg blocks. Patterns below are extra: they match a Heading 2 in the Doc and wrap that section (FAQ, accordion). You do not create a pattern for headings, paragraphs, lists, quotes, or code.', '4wp-drive' ); ?>
			</p>

			<section class="forwp-drive-patterns-section" aria-labelledby="forwp-drive-auto-heading">
				<p class="forwp-drive-patterns__kicker"><?php esc_html_e( 'Always on import', '4wp-drive' ); ?></p>
				<h2 id="forwp-drive-auto-heading" class="forwp-drive-patterns__heading"><?php esc_html_e( 'Core blocks (no pattern needed)', '4wp-drive' ); ?></h2>
				<p class="description forwp-drive-patterns__hint">
					<?php esc_html_e( 'Google Doc styles, Markdown, and Word all go through this map. Cover and custom === blocks are not in this release.', '4wp-drive' ); ?>
				</p>

				<table class="widefat forwp-drive-patterns__map-table forwp-drive-auto-map">
					<thead>
						<tr>
							<th><?php esc_html_e( 'In the document', '4wp-drive' ); ?></th>
							<th><?php esc_html_e( 'Gutenberg block', '4wp-drive' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Heading 1–6', '4wp-drive' ); ?></td>
							<td><code>core/heading</code></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Normal paragraph', '4wp-drive' ); ?></td>
							<td><code>core/paragraph</code></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Bulleted / numbered list', '4wp-drive' ); ?></td>
							<td><code>core/list</code> + <code>core/list-item</code></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Quote', '4wp-drive' ); ?></td>
							<td><code>core/quote</code></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Code / Courier / fenced Markdown', '4wp-drive' ); ?></td>
							<td><code>core/code</code></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Bold, italic, underline', '4wp-drive' ); ?></td>
							<td><code>strong</code> / <code>em</code> / <code>u</code> inside the block</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Horizontal rule', '4wp-drive' ); ?></td>
							<td><code>core/separator</code></td>
						</tr>
						<tr>
							<td>
								<code>[image:file.jpg]</code>
								<?php esc_html_e( 'or', '4wp-drive' ); ?>
								<code>[image:file.jpg left]</code>
							</td>
							<td><code>core/image</code> <?php esc_html_e( '(left / right wrap, center on its own)', '4wp-drive' ); ?></td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="forwp-drive-patterns-section" aria-labelledby="forwp-drive-pin-heading">
				<h2 id="forwp-drive-pin-heading" class="forwp-drive-patterns__heading"><?php esc_html_e( 'Images and pin', '4wp-drive' ); ?></h2>
				<p class="description forwp-drive-patterns__hint">
					<?php esc_html_e( 'Package folder: article file + png/jpg. Featured image is chosen on Incoming. Inline images need a marker in the body — pin writes that marker for you.', '4wp-drive' ); ?>
				</p>
				<ol class="forwp-drive-patterns__steps">
					<li><?php esc_html_e( 'Open Incoming, select the article, wait for preview.', '4wp-drive' ); ?></li>
					<li><?php esc_html_e( 'On the selected queue card: pick a package image, then Left / Center / Right. Each file shows where import will send it (Article, core/image, Featured image).', '4wp-drive' ); ?></li>
					<li><?php esc_html_e( 'Click a paragraph (or heading) in the preview. Drive inserts a marker after it, for example [image:hero.png left], and saves it on the inbox row. Click Remove on the marker to delete it without reloading.', '4wp-drive' ); ?></li>
					<li><?php esc_html_e( 'Import. The marker becomes core/image: left/right wrap text, center sits full-width.', '4wp-drive' ); ?></li>
				</ol>
				<p class="description">
					<?php esc_html_e( 'You can still type the marker by hand. In Markdown, ![alt](hero.png) in the same folder becomes [image:hero.png] automatically. Pin is the same idea as 4WP TODO: choose the asset, then click where it belongs — you do not click into the Google Doc to place the file.', '4wp-drive' ); ?>
				</p>
			</section>

			<hr class="forwp-drive-patterns__divider" />

			<section class="forwp-drive-patterns-section" aria-labelledby="forwp-drive-patterns-rules-heading">
				<p class="forwp-drive-patterns__kicker"><?php esc_html_e( 'Optional extras', '4wp-drive' ); ?></p>
				<h2 id="forwp-drive-patterns-rules-heading" class="forwp-drive-patterns__heading"><?php esc_html_e( 'Your patterns', '4wp-drive' ); ?></h2>
				<p class="description forwp-drive-patterns__hint">
					<?php esc_html_e( 'Only for sections that are not core blocks: match a Heading 2 (FAQ, Accordion). Core Image is already on — add that template here only if you need to turn markers off.', '4wp-drive' ); ?>
				</p>

				<table class="widefat forwp-drive-block-mapping-table" id="forwp-drive-block-mapping-table">
					<thead>
						<tr>
							<th class="forwp-drive-block-mapping-table__on"><?php esc_html_e( 'Enabled', '4wp-drive' ); ?></th>
							<th><?php esc_html_e( 'Block template', '4wp-drive' ); ?></th>
							<th><?php esc_html_e( 'Section heading (H2)', '4wp-drive' ); ?></th>
							<th class="forwp-drive-block-mapping-table__keep"><?php esc_html_e( 'Keep H2', '4wp-drive' ); ?></th>
							<th class="forwp-drive-block-mapping-table__actions"><?php esc_html_e( 'Delete', '4wp-drive' ); ?></th>
						</tr>
					</thead>
					<tbody id="forwp-drive-block-mapping-rows"></tbody>
				</table>

				<p id="forwp-drive-patterns-empty" class="forwp-drive-patterns-empty description" hidden>
					<?php esc_html_e( 'No extra patterns. Import still converts the core blocks above.', '4wp-drive' ); ?>
				</p>

				<div class="forwp-drive-patterns-actions">
					<button type="button" class="button button-primary" id="forwp-drive-block-mapping-add-row"><?php esc_html_e( 'Add pattern', '4wp-drive' ); ?></button>
					<button type="button" class="button" id="forwp-drive-save-patterns"><?php esc_html_e( 'Save patterns', '4wp-drive' ); ?></button>
				</div>
			</section>

			<hr class="forwp-drive-patterns__divider" />

			<section class="forwp-drive-patterns-section" aria-labelledby="forwp-drive-patterns-docs-heading">
				<h2 id="forwp-drive-patterns-docs-heading" class="forwp-drive-patterns__heading"><?php esc_html_e( 'FAQ / Accordion in the Doc', '4wp-drive' ); ?></h2>
				<p class="description forwp-drive-patterns__hint">
					<?php esc_html_e( 'Use real Heading styles, not bold fake headings. 4WP FAQ needs the 4WP FAQ plugin; Core Accordion does not.', '4wp-drive' ); ?>
				</p>

				<table class="widefat forwp-drive-patterns__map-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Google Docs style', '4wp-drive' ); ?></th>
							<th><?php esc_html_e( 'What you type', '4wp-drive' ); ?></th>
							<th><?php esc_html_e( 'What Drive does', '4wp-drive' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code><?php esc_html_e( 'Heading 2', '4wp-drive' ); ?></code></td>
							<td><code>FAQ</code></td>
							<td><?php esc_html_e( 'Starts the section (must match the pattern H2)', '4wp-drive' ); ?></td>
						</tr>
						<tr>
							<td><code><?php esc_html_e( 'Heading 3', '4wp-drive' ); ?></code></td>
							<td><?php esc_html_e( 'The question', '4wp-drive' ); ?></td>
							<td><?php esc_html_e( 'Accordion / FAQ item title', '4wp-drive' ); ?></td>
						</tr>
						<tr>
							<td><code><?php esc_html_e( 'Normal text', '4wp-drive' ); ?></code></td>
							<td><?php esc_html_e( 'The answer', '4wp-drive' ); ?></td>
							<td><?php esc_html_e( 'Item body', '4wp-drive' ); ?></td>
						</tr>
					</tbody>
				</table>
			</section>
		</div>
	</div>
</div>
