<?php
/**
 * Dashboard setup widget markup.
 *
 * @package ForWP\Drive
 * @var array{current: int, total: int, progress: float, message: string, cta_label: string, cta_url: string, dismiss_url: string} $progress
 */

defined( 'ABSPATH' ) || exit;

$ring_radius  = 8;
$ring_center  = 10;
$ring_length  = 2 * M_PI * $ring_radius;
$ring_offset  = $ring_length * ( 1 - $progress['progress'] );
?>
<div class="forwp-drive-setup-widget">
	<div class="forwp-drive-setup-widget__content">
		<div class="forwp-drive-setup-widget__step">
			<svg class="forwp-drive-setup-widget__ring" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
				<circle
					class="forwp-drive-setup-widget__ring-track"
					cx="<?php echo esc_attr( (string) $ring_center ); ?>"
					cy="<?php echo esc_attr( (string) $ring_center ); ?>"
					r="<?php echo esc_attr( (string) $ring_radius ); ?>"
					fill="none"
					stroke-width="2"
				/>
				<circle
					class="forwp-drive-setup-widget__ring-progress"
					cx="<?php echo esc_attr( (string) $ring_center ); ?>"
					cy="<?php echo esc_attr( (string) $ring_center ); ?>"
					r="<?php echo esc_attr( (string) $ring_radius ); ?>"
					fill="none"
					stroke-width="2"
					stroke-dasharray="<?php echo esc_attr( (string) $ring_length ); ?>"
					stroke-dashoffset="<?php echo esc_attr( (string) $ring_offset ); ?>"
					transform="rotate(-90 <?php echo esc_attr( (string) $ring_center ); ?> <?php echo esc_attr( (string) $ring_center ); ?>)"
				/>
			</svg>
			<span class="forwp-drive-setup-widget__step-label">
				<?php
				printf(
					/* translators: 1: current setup step number, 2: total setup steps. */
					esc_html__( 'Step %1$d of %2$d', '4wp-drive' ),
					(int) $progress['current'],
					(int) $progress['total']
				);
				?>
			</span>
		</div>

		<p class="forwp-drive-setup-widget__message">
			<?php echo esc_html( $progress['message'] ); ?>
		</p>

		<p class="forwp-drive-setup-widget__actions">
			<a href="<?php echo esc_url( $progress['cta_url'] ); ?>" class="button button-primary">
				<?php echo esc_html( $progress['cta_label'] ); ?>
			</a>
		</p>

		<p class="forwp-drive-setup-widget__dismiss">
			<a href="<?php echo esc_url( $progress['dismiss_url'] ); ?>">
				<?php esc_html_e( 'Dismiss this notice', '4wp-drive' ); ?>
			</a>
		</p>
	</div>

	<div class="forwp-drive-setup-widget__illustration" aria-hidden="true">
		<svg width="160" height="120" viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
			<rect x="24" y="58" width="72" height="44" rx="4" fill="#f0f6fc" stroke="#2271b1" stroke-width="1.5"/>
			<rect x="32" y="66" width="56" height="28" rx="2" fill="#fff" stroke="#c3c4c7" stroke-width="1"/>
			<path d="M40 78h32M40 84h20" stroke="#c3c4c7" stroke-width="2" stroke-linecap="round"/>
			<path d="M88 40c0-8.837 7.163-16 16-16s16 7.163 16 16" stroke="#2271b1" stroke-width="2" stroke-linecap="round"/>
			<path d="M104 24v8M96 32h16" stroke="#2271b1" stroke-width="2" stroke-linecap="round"/>
			<ellipse cx="104" cy="40" rx="20" ry="8" fill="#2271b1" opacity="0.15"/>
			<path d="M112 52l12 8-12 8" stroke="#2271b1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			<circle cx="128" cy="68" r="14" fill="#edfaef" stroke="#00a32a" stroke-width="1.5"/>
			<path d="M122 68l4 4 8-8" stroke="#00a32a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
		</svg>
	</div>
</div>
