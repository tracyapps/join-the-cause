<?php
/**
 * Registers the [jtc_petition id="123"] shortcode.
 *
 * Shortcode attributes:
 *   id         (int)  — post ID of the petition. Required.
 *   show_title (0|1)  — whether to render the petition <h1>. Default 0 (hidden)
 *                       because the shortcode is typically embedded inside a page
 *                       that already carries its own title. Set to 1 on the
 *                       standalone single-petition page template.
 *
 * Sticky sidebar note:
 *   Stickiness is handled entirely in CSS (position: sticky).
 *   The .jtc-sign-panel-col wrapper stretches to the full grid-row height
 *   (matching the content column), giving the sticky panel its full "runway"
 *   without any JavaScript scroll-position hacks.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shortcode WordPress component. */
class JTC_Shortcode {

	/**
	 * Rendering.
	 *
	 * @var array
	 */
	private static array $rendering = array();
	/**
	 * Register WordPress hooks for this component.
	 */
	public function register(): void {
		add_shortcode( 'jtc_petition', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// ─── Asset registration ───────────────────────────────────────────────────
	/**
	 * Enqueue assets.
	 */
	public function enqueue_assets(): void {
		wp_register_style(
			'jtc-public',
			JTC_PLUGIN_URL . 'assets/css/public.css',
			array(),
			JTC_VERSION
		);

		wp_register_script(
			'jtc-public',
			JTC_PLUGIN_URL . 'public/js/jtc-public.js',
			array( 'jquery' ),
			JTC_VERSION,
			true
		);
	}

	// ─── Shortcode renderer ───────────────────────────────────────────────────

	/**
	 * Render or process render.
	 *
	 * @param array|string $atts  Shortcode attributes (WordPress passes '' when none given).
	 * @return string Result value.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'         => 0,
				'show_title' => 0, // Hidden by default — page already has its own title.
			),
			is_array( $atts ) ? $atts : array(),
			'jtc_petition'
		);

		$petition_id = absint( $atts['id'] );
		$show_title  = (bool) $atts['show_title'];

		if ( ! $petition_id ) {
			return '<!-- JTC: no petition id provided -->';
		}

		$petition = get_post( $petition_id );
		if ( ! $petition || JTC_CPT !== $petition->post_type || 'publish' !== $petition->post_status ) {
			return '<!-- JTC: petition not found -->';
		}

		if ( post_password_required( $petition ) ) {
			return get_the_password_form( $petition );
		}

		if ( isset( self::$rendering[ $petition_id ] ) ) {
			return '<!-- Join the Cause: recursive petition embed skipped -->';
		}
		self::$rendering[ $petition_id ] = true;
		try {

			// Merge global defaults with per-petition overrides.
			$defaults = get_option( 'jtc_petition_defaults', array() );
			$defaults = is_array( $defaults ) ? $defaults : array();
			$override = get_post_meta( $petition_id, '_jtc_petition_settings', true );
			$s        = is_array( $override ) ? array_merge( $defaults, $override ) : $defaults;

			// Signature count.
			global $wpdb;
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}jtc_supporters WHERE petition_id = %d",
					$petition_id
				)
			);

			// Recent public signers.
			$recent = array();
			if ( ! empty( $s['show_recent'] ) ) {
				$recent = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT first_name, last_name, signed_at
					 FROM {$wpdb->prefix}jtc_supporters
					 WHERE petition_id = %d AND display_consent = 1
					 ORDER BY signed_at DESC LIMIT 5",
						$petition_id
					),
					ARRAY_A
				);
				$recent = is_array( $recent ) ? $recent : array();
			}

			// Custom form fields.
			$raw_fields = get_post_meta( $petition_id, '_jtc_form_fields', true );
			$fields     = is_string( $raw_fields ) ? json_decode( $raw_fields, true ) : array();
			if ( ! is_array( $fields ) ) {
				$fields = array();
			}

			// Enqueue assets and pass SHARED strings/settings to JS. Per-instance
			// config (petition id, nonce, share URL) travels on each .jtc-petition
			// wrapper as data attributes so multiple petitions per page never collide.
			wp_enqueue_style( 'jtc-public' );
			wp_enqueue_script( 'jtc-public' );
			wp_localize_script(
				'jtc-public',
				'jtcData',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'i18n'    => array(
						'signing'        => __( 'Signing…', 'join-the-cause' ),
						'sign'           => __( 'Sign the Petition', 'join-the-cause' ),
						'errorGeneric'   => __( 'Something went wrong. Please try again.', 'join-the-cause' ),
						'shareLabel'     => __( 'Share this petition:', 'join-the-cause' ),
						'copyLink'       => __( 'Copy link', 'join-the-cause' ),
						'copied'         => __( 'Copied!', 'join-the-cause' ),
						'emailInvalid'   => __( 'Please enter a valid email address.', 'join-the-cause' ),
						'fieldRequired'  => __( 'This field is required.', 'join-the-cause' ),
						/* translators: %d: percentage of goal reached */
						'goalProgress'   => __( '%d%% of goal reached', 'join-the-cause' ),
						/* translators: %s: signature count, e.g. "1,248" */
						'signedCount'    => __( '— %s signed', 'join-the-cause' ),
						'thanksFallback' => __( 'Thank you for signing!', 'join-the-cause' ),
						'embedPrompt'    => __( 'Copy this shortcode to embed the petition:', 'join-the-cause' ),
						'printQrTitle'   => __( 'Print QR', 'join-the-cause' ),
						'qrAlt'          => __( 'QR code', 'join-the-cause' ),
						'copySuccess'    => __( 'Link copied to clipboard.', 'join-the-cause' ),
						'copyManual'     => __( 'Text selected. Press Ctrl+C (or Cmd+C) to copy.', 'join-the-cause' ),
					),
				)
			);

			// Safety net: attach the CSS vars inline when the head output was
			// skipped (late renders, page builders) — no-op when already printed.
			jtc_maybe_add_inline_css_vars();

			$config = array(
				'instance_id' => wp_unique_id( $petition_id . '-' ),
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'jtc_sign_petition_' . $petition_id ),
				'share_url'   => jtc_get_petition_share_url( $petition_id, false ),
			);

			ob_start();
			$this->render_petition( $petition, $s, $count, $recent, $fields, $show_title, $config );
			return ob_get_clean();
		} finally {
			unset( self::$rendering[ $petition_id ] );
		}
	}

	// ─── Full petition layout ─────────────────────────────────────────────────
	/**
	 * Render petition.
	 *
	 * @param WP_Post $petition Petition post object.
	 * @param array   $s S.
	 * @param int     $count Count.
	 * @param array   $recent Recent.
	 * @param array   $fields Fields.
	 * @param bool    $show_title Show title.
	 * @param array   $config Config.
	 */
	private function render_petition(
		WP_Post $petition,
		array $s,
		int $count,
		array $recent,
		array $fields,
		bool $show_title,
		array $config
	): void {
		$style       = jtc_get_style_options();
		$hero_style  = (string) ( jtc_fallback( $style['hero_style'], 'gradient' ) );
		$goal        = (int) ( $s['goal'] ?? 0 );
		$show_count  = ! empty( $s['show_count'] );
		$show_recent = ! empty( $s['show_recent'] );
		$show_dek    = isset( $s['show_dek'] ) ? ! empty( $s['show_dek'] ) : ! empty( $style['show_dek'] );
		$show_share  = isset( $s['show_share'] ) ? ! empty( $s['show_share'] ) : ! empty( $style['show_share'] );
		$show_qr     = isset( $s['show_qr'] ) ? ! empty( $s['show_qr'] ) : ! empty( $style['show_qr'] );
		$show_image  = isset( $s['show_featured_image'] ) ? ! empty( $s['show_featured_image'] ) : ! empty( $style['show_featured_image'] );
		$has_image   = $show_image && has_post_thumbnail( $petition->ID );
		$privacy     = get_option( 'jtc_privacy_notice', '' );
		$terms       = get_option( 'jtc_terms_of_service', '' );
		$shares      = (array) ( $s['share_buttons'] ?? array() );
		$qr_url      = jtc_get_petition_qr_url( $petition->ID );
		$short_url   = jtc_get_petition_short_url( $petition->ID );
		$pid         = esc_attr( $config['instance_id'] );
		$dek         = $show_dek ? ( has_excerpt( $petition )
			? get_the_excerpt( $petition )
			: wp_trim_words( wp_strip_all_tags( $petition->post_content ), 28, '…' ) ) : '';
		?>
		<div class="jtc-petition<?php echo $has_image ? '' : ' no-featured-image'; ?>"
			id="jtc-petition-<?php echo esc_attr( $pid ); ?>"
			data-petition-id="<?php echo esc_attr( $petition->ID ); ?>"
			data-instance-id="<?php echo esc_attr( $pid ); ?>"
			data-jtc-hero="<?php echo esc_attr( $hero_style ); ?>"
			data-jtc-ajax-url="<?php echo esc_url( $config['ajax_url'] ); ?>"
			data-jtc-nonce="<?php echo esc_attr( $config['nonce'] ); ?>"
			data-jtc-share-url="<?php echo esc_url( $config['share_url'] ); ?>">

			<!-- ── Header ─────────────────────────────────────────────── -->
			<header class="jtc-petition__header">

				<div class="jtc-petition__intro">
				<?php if ( $show_title ) : ?>
				<h1 class="jtc-petition__title">
					<?php echo esc_html( $petition->post_title ); ?>
				</h1>
				<?php endif; ?>

				<?php if ( $dek ) : ?>
				<p class="jtc-petition__dek">
					<?php echo esc_html( $dek ); ?>
				</p>
				<?php endif; ?>
				</div>

				<?php if ( $has_image ) : ?>
				<figure class="jtc-petition__hero"
						aria-label="<?php esc_attr_e( 'Petition image', 'join-the-cause' ); ?>">
					<?php
					echo get_the_post_thumbnail(
						$petition->ID,
						'large',
						array(
							'loading' => 'lazy',
							'class'   => 'jtc-petition__hero-img',
						)
					);
					?>
				</figure>
				<?php endif; ?>

			</header>

			<!-- ── Two-column body ────────────────────────────────────── -->
			<!--
				.jtc-petition__body is a CSS Grid (no align-items override, so
				both columns default to "stretch"). .jtc-sign-panel-col therefore
				grows to the same height as the content column, giving
				position:sticky on .jtc-sign-panel its full scroll runway without
				any JavaScript involvement.
			-->
			<div class="jtc-petition__body">

				<!-- Left: petition content -->
				<div class="jtc-petition__content" role="region" aria-label="<?php echo esc_attr( $petition->post_title ); ?>"
						id="jtc-content-<?php echo esc_attr( $pid ); ?>">

					<div class="jtc-petition__text">
						<?php echo wp_kses_post( apply_filters( 'the_content', $petition->post_content ) ); ?>
					</div>

					<?php if ( $show_recent && $recent ) : ?>
					<section class="jtc-recent-signers"
							aria-label="<?php esc_attr_e( 'Recent supporters', 'join-the-cause' ); ?>">
						<h2 class="jtc-recent-signers__heading">
							<?php esc_html_e( 'Recent supporters', 'join-the-cause' ); ?>
						</h2>
						<ul class="jtc-recent-signers__list"
							id="jtc-recent-list-<?php echo esc_attr( $pid ); ?>">
							<?php foreach ( $recent as $signer ) : ?>
							<li class="jtc-recent-signers__item">
								<span class="jtc-recent-signers__name">
									<?php
									echo esc_html(
										$signer['first_name'] . ' ' .
										jtc_name_initial( $signer['last_name'] ) . '.'
									);
									?>
								</span>
								<time class="jtc-recent-signers__time"
										datetime="<?php echo esc_attr( $signer['signed_at'] ); ?>">
									<?php
									echo esc_html(
										human_time_diff( (int) get_gmt_from_date( $signer['signed_at'], 'U' ), time() ) .
										' ' . __( 'ago', 'join-the-cause' )
									);
									?>
								</time>
							</li>
							<?php endforeach; ?>
						</ul>
					</section>
					<?php endif; ?>

					<?php if ( $show_share && ( $shares || ( $show_qr && $qr_url && $short_url ) ) ) : ?>
					<section class="jtc-share"
							aria-label="<?php esc_attr_e( 'Share this petition', 'join-the-cause' ); ?>">
						<h2 class="jtc-share__heading">
							<?php esc_html_e( 'Share this petition', 'join-the-cause' ); ?>
						</h2>
						<?php if ( $shares ) : ?>
						<div class="jtc-share__buttons">
							<?php $this->render_share_buttons( $petition, $shares ); ?>
						</div>
						<?php endif; ?>
						<?php if ( $show_qr && $qr_url && $short_url ) : ?>
						<div class="jtc-share__qr">
							<img src="<?php echo esc_url( $qr_url ); ?>" alt="<?php esc_attr_e( 'Petition short link QR code', 'join-the-cause' ); ?>">
							<div class="jtc-share__qr-actions">
								<a class="jtc-share__qr-btn" href="<?php echo esc_url( $qr_url ); ?>" download>
									<?php esc_html_e( 'Download QR', 'join-the-cause' ); ?>
								</a>
								<button type="button" class="jtc-share__qr-btn" data-action="print-qr" data-qr-url="<?php echo esc_url( $qr_url ); ?>">
									<?php esc_html_e( 'Print QR', 'join-the-cause' ); ?>
								</button>
							</div>
						</div>
						<?php endif; ?>
					</section>
					<?php endif; ?>

				</div>

				<!--
					Right column wrapper — stretches to full grid-row height so
					the sticky panel has a proper containing block. No extra CSS
					needed; grid "stretch" alignment handles it.
				-->
				<div class="jtc-sign-panel-col">
					<aside class="jtc-sign-panel"
							id="jtc-sign-panel-<?php echo esc_attr( $pid ); ?>"
							aria-label="<?php esc_attr_e( 'Sign the petition', 'join-the-cause' ); ?>">

						<?php if ( $show_count ) : ?>
						<div class="jtc-count" aria-live="polite" aria-atomic="true">
							<span class="jtc-count__number"
									id="jtc-count-<?php echo esc_attr( $pid ); ?>">
								<?php echo esc_html( number_format_i18n( $count ) ); ?>
							</span>
							<span class="jtc-count__label">
								<?php esc_html_e( 'have signed', 'join-the-cause' ); ?>
							</span>

							<?php
							if ( $goal > 0 ) :
								$pct            = min( 100, round( ( $count / $goal ) * 100 ) );
								$progress_label = sprintf(
									/* translators: %d: percentage of goal reached */
									__( '%d%% of goal reached', 'join-the-cause' ),
									$pct
								);
								?>
							<div class="jtc-progress"
								role="progressbar"
								data-jtc-goal="<?php echo esc_attr( $goal ); ?>"
								aria-valuenow="<?php echo esc_attr( $pct ); ?>"
								aria-valuemin="0"
								aria-valuemax="100"
								aria-label="<?php echo esc_attr( $progress_label ); ?>">
								<div class="jtc-progress__bar"
									style="width:<?php echo esc_attr( $pct ); ?>%"></div>
							</div>
							<p class="jtc-count__goal">
								<?php
								printf(
									/* translators: %s = formatted goal number */
									esc_html__( 'Goal: %s', 'join-the-cause' ),
									esc_html( number_format_i18n( $goal ) )
								);
								?>
							</p>
							<?php endif; ?>
						</div>
						<?php endif; ?>

						<div class="jtc-form-wrap"
							id="jtc-form-wrap-<?php echo esc_attr( $pid ); ?>">
							<?php $this->render_sign_form( $config['instance_id'], $s, $fields, $privacy, $terms ); ?>
						</div>

					</aside>
				</div><!-- /.jtc-sign-panel-col -->

			</div><!-- /.jtc-petition__body -->
		</div><!-- /.jtc-petition -->

		<!-- Mobile sticky CTA — fixed to viewport bottom, JS-toggled visibility -->
		<div class="jtc-mobile-cta"
			id="jtc-mobile-cta-<?php echo esc_attr( $pid ); ?>"
			aria-hidden="true">
			<button class="jtc-mobile-cta__button"
					type="button"
					aria-controls="jtc-sign-panel-<?php echo esc_attr( $pid ); ?>">
				<?php esc_html_e( 'Sign the Petition', 'join-the-cause' ); ?>
				<?php if ( $show_count ) : ?>
				<span class="jtc-mobile-cta__count"
						id="jtc-mobile-count-<?php echo esc_attr( $pid ); ?>">
					<?php
					printf(
						/* translators: %s: signature count, e.g. "1,248" */
						esc_html__( '— %s signed', 'join-the-cause' ),
						esc_html( number_format_i18n( $count ) )
					);
					?>
				</span>
				<?php endif; ?>
			</button>
		</div>
		<?php
	}

	// ─── Sign form ────────────────────────────────────────────────────────────
	/**
	 * Render sign form.
	 *
	 * @param string $instance_id Instance id.
	 * @param array  $s S.
	 * @param array  $fields Fields.
	 * @param string $privacy Privacy.
	 * @param string $terms Terms.
	 */
	private function render_sign_form(
		string $instance_id,
		array $s,
		array $fields,
		string $privacy,
		string $terms
	): void {
		$show_recent = ! empty( $s['show_recent'] );
		$pid         = esc_attr( $instance_id );
		?>
		<form class="jtc-form"
				id="jtc-form-<?php echo esc_attr( $pid ); ?>"
				novalidate
				aria-label="<?php esc_attr_e( 'Signature form', 'join-the-cause' ); ?>">

			<noscript>
				<p class="jtc-form__noscript">
					<?php esc_html_e( 'This form needs JavaScript to submit. Please enable JavaScript, or contact the site to sign in another way.', 'join-the-cause' ); ?>
				</p>
			</noscript>

			<h2 class="jtc-form__heading">
				<?php esc_html_e( 'Sign the Petition', 'join-the-cause' ); ?>
			</h2>

			<!-- First name -->
			<div class="jtc-field">
				<label class="jtc-field__label"
						for="jtc-first-<?php echo esc_attr( $pid ); ?>">
					<?php esc_html_e( 'First name', 'join-the-cause' ); ?>
					<span aria-hidden="true" class="jtc-required">*</span>
				</label>
				<input class="jtc-field__input"
						type="text"
						id="jtc-first-<?php echo esc_attr( $pid ); ?>"
						name="jtc_first_name"
						autocomplete="given-name" maxlength="100"
						required
						aria-required="true"
						aria-describedby="jtc-first-error-<?php echo esc_attr( $pid ); ?>">
				<span class="jtc-field__error"
						id="jtc-first-error-<?php echo esc_attr( $pid ); ?>"
						role="alert"
						hidden></span>
			</div>

			<!-- Last name -->
			<div class="jtc-field">
				<label class="jtc-field__label"
						for="jtc-last-<?php echo esc_attr( $pid ); ?>">
					<?php esc_html_e( 'Last name', 'join-the-cause' ); ?>
					<span aria-hidden="true" class="jtc-required">*</span>
				</label>
				<input class="jtc-field__input"
						type="text"
						id="jtc-last-<?php echo esc_attr( $pid ); ?>"
						name="jtc_last_name"
						autocomplete="family-name" maxlength="100"
						required
						aria-required="true"
						aria-describedby="jtc-last-error-<?php echo esc_attr( $pid ); ?>">
				<span class="jtc-field__error"
						id="jtc-last-error-<?php echo esc_attr( $pid ); ?>"
						role="alert"
						hidden></span>
			</div>

			<!-- Email -->
			<div class="jtc-field">
				<label class="jtc-field__label"
						for="jtc-email-<?php echo esc_attr( $pid ); ?>">
					<?php esc_html_e( 'Email address', 'join-the-cause' ); ?>
					<span aria-hidden="true" class="jtc-required">*</span>
				</label>
				<input class="jtc-field__input"
						type="email"
						id="jtc-email-<?php echo esc_attr( $pid ); ?>"
						name="jtc_email"
						autocomplete="email" maxlength="191"
						required
						aria-required="true"
						aria-describedby="jtc-email-error-<?php echo esc_attr( $pid ); ?>">
				<span class="jtc-field__error"
						id="jtc-email-error-<?php echo esc_attr( $pid ); ?>"
						role="alert"
						hidden></span>
			</div>

			<!-- Custom fields -->
			<?php
			foreach ( $fields as $field ) :
				$field_id   = sanitize_key( $field['id'] );
				$input_id   = 'jtc-extra-' . $instance_id . '-' . $field_id;
				$error_id   = $input_id . '-error';
				$input_name = 'jtc_extra_' . $field_id;
				$label_text = esc_html( $field['label'] );
				$required   = ! empty( $field['required'] );
				$ph         = esc_attr( $field['placeholder'] ?? '' );
				$type       = $field['type'] ?? 'text';
				?>
			<div class="jtc-field">

				<?php if ( 'checkbox' !== $type ) : ?>
				<label class="jtc-field__label"
						for="<?php echo esc_attr( $input_id ); ?>">
					<?php echo esc_html( $label_text ); ?>
					<?php if ( $required ) : ?>
					<span aria-hidden="true" class="jtc-required">*</span>
					<?php endif; ?>
				</label>
				<?php endif; ?>

				<?php if ( 'textarea' === $type ) : ?>
				<textarea class="jtc-field__input jtc-field__textarea"
							id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( $input_name ); ?>"
							placeholder="<?php echo esc_attr( $ph ); ?>"
							aria-describedby="<?php echo esc_attr( $error_id ); ?>"
							<?php
							if ( $required ) :
								?>
								required aria-required="true"<?php endif; ?>
							rows="3"></textarea>

				<?php elseif ( 'checkbox' === $type ) : ?>
				<label class="jtc-field__checkbox-label">
					<input class="jtc-field__checkbox"
							type="checkbox"
							id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( $input_name ); ?>"
							value="1"
							aria-describedby="<?php echo esc_attr( $error_id ); ?>"
							<?php
							if ( $required ) :
								?>
								required aria-required="true"<?php endif; ?>>
					<?php echo esc_html( $label_text ); ?>
					<?php if ( $required ) : ?>
					<span aria-hidden="true" class="jtc-required">*</span>
					<?php endif; ?>
				</label>

				<?php elseif ( 'select' === $type ) : ?>
				<select class="jtc-field__input jtc-field__select"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $input_name ); ?>"
						aria-describedby="<?php echo esc_attr( $error_id ); ?>"
						<?php
						if ( $required ) :
							?>
							required aria-required="true"<?php endif; ?>>
					<option value="">
						<?php esc_html_e( '— Select —', 'join-the-cause' ); ?>
					</option>
					<?php foreach ( (array) ( $field['options'] ?? array() ) as $opt ) : ?>
					<option value="<?php echo esc_attr( $opt ); ?>">
						<?php echo esc_html( $opt ); ?>
					</option>
					<?php endforeach; ?>
				</select>

				<?php else : // text, email, etc. ?>
				<input class="jtc-field__input"
						type="<?php echo esc_attr( $type ); ?>"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $input_name ); ?>"
						placeholder="<?php echo esc_attr( $ph ); ?>"
						aria-describedby="<?php echo esc_attr( $error_id ); ?>"
						<?php
						if ( $required ) :
							?>
							required aria-required="true"<?php endif; ?>>
				<?php endif; ?>

				<span class="jtc-field__error"
						id="<?php echo esc_attr( $error_id ); ?>"
						role="alert"
						hidden></span>
			</div>
			<?php endforeach; ?>

			<!-- Name-display consent (auto-added when show_recent is on) -->
			<?php if ( $show_recent ) : ?>
			<div class="jtc-field">
				<label class="jtc-field__checkbox-label">
					<input class="jtc-field__checkbox"
							type="checkbox"
							name="jtc_display_consent"
							id="jtc-display-consent-<?php echo esc_attr( $pid ); ?>"
							value="1">
					<?php esc_html_e( 'Show my name in the list of recent supporters', 'join-the-cause' ); ?>
				</label>
			</div>
			<?php endif; ?>

			<div class="jtc-field">
				<label class="jtc-field__checkbox-label">
					<input class="jtc-field__checkbox" type="checkbox" name="jtc_newsletter_consent" value="1">
					<?php esc_html_e( 'Email me newsletters from this site. I can unsubscribe at any time.', 'join-the-cause' ); ?>
				</label>
			</div>
			<?php if ( $privacy ) : ?>
			<p class="jtc-form__privacy"><?php echo wp_kses_post( $privacy ); ?></p>
			<?php endif; ?>

			<?php if ( $terms ) : ?>
			<p class="jtc-form__terms"><?php echo wp_kses_post( $terms ); ?></p>
			<?php endif; ?>

			<!-- Bot mitigation: off-screen honeypot (never shown or announced) -->
			<div class="jtc-field jtc-field--hp" aria-hidden="true">
				<label class="jtc-field__label"
						for="jtc-hp-<?php echo esc_attr( $pid ); ?>">
					<?php esc_html_e( 'Website', 'join-the-cause' ); ?>
				</label>
				<input class="jtc-field__input"
						type="text"
						id="jtc-hp-<?php echo esc_attr( $pid ); ?>"
						name="jtc_website"
						value=""
						tabindex="-1"
						autocomplete="off">
			</div>
			<input type="hidden" name="jtc_form_time" value="<?php echo esc_attr( time() ); ?>">

			<div class="jtc-form__status"
				id="jtc-status-<?php echo esc_attr( $pid ); ?>"
				role="alert"
							tabindex="-1"></div>

			<button class="jtc-form__submit"
					type="submit"
					id="jtc-submit-<?php echo esc_attr( $pid ); ?>">
				<?php esc_html_e( 'Sign the Petition', 'join-the-cause' ); ?>
			</button>

		</form>
		<?php
	}

	// ─── Share buttons ────────────────────────────────────────────────────────
	/**
	 * Render share buttons.
	 *
	 * @param WP_Post $petition Petition post object.
	 * @param array   $services Services.
	 */
	private function render_share_buttons( WP_Post $petition, array $services ): void {
		$share_url   = jtc_get_petition_share_url( $petition->ID, false );
		$encoded_url = rawurlencode( $share_url );
		$title       = rawurlencode( $petition->post_title );

		$buttons = array(
			'facebook' => array(
				'label' => __( 'Share on Facebook', 'join-the-cause' ),
				'href'  => "https://www.facebook.com/sharer/sharer.php?u={$encoded_url}",
				'icon'  => 'f',
			),
			'twitter'  => array(
				'label' => __( 'Share on X (Twitter)', 'join-the-cause' ),
				'href'  => "https://twitter.com/intent/tweet?url={$encoded_url}&text={$title}",
				'icon'  => '𝕏',
			),
			'copy'     => array(
				'label' => __( 'Copy link', 'join-the-cause' ),
				'href'  => $share_url,
				'icon'  => '🔗',
			),
			'embed'    => array(
				'label' => __( 'Embed', 'join-the-cause' ),
				'href'  => '#embed',
				'icon'  => '</>',
			),
		);

		foreach ( $services as $svc ) {
			if ( ! isset( $buttons[ $svc ] ) ) {
				continue;
			}
			$b      = $buttons[ $svc ];
			$is_ext = in_array( $svc, array( 'facebook', 'twitter' ), true );
			printf(
				'<a class="jtc-share__btn jtc-share__btn--%1$s" href="%2$s" %3$s aria-label="%4$s" rel="noopener noreferrer"><span aria-hidden="true">%5$s</span></a>',
				esc_attr( $svc ),
				esc_url( $b['href'] ),
				$is_ext ? 'target="_blank"' : 'data-action="' . esc_attr( $svc ) . '"',
				esc_attr( $b['label'] ),
				esc_html( $b['icon'] )
			);
		}
	}
}
