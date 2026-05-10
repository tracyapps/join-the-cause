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

class JTC_Shortcode {

	public function register(): void {
		add_shortcode( 'jtc_petition', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// ─── Asset registration ───────────────────────────────────────────────────

	public function enqueue_assets(): void {
		wp_register_style(
			'jtc-public',
			JTC_PLUGIN_URL . 'assets/css/public.css',
			[],
			JTC_VERSION
		);

		wp_register_script(
			'jtc-public',
			JTC_PLUGIN_URL . 'public/js/jtc-public.js',
			[ 'jquery' ],
			JTC_VERSION,
			true
		);
	}

	// ─── Shortcode renderer ───────────────────────────────────────────────────

	/**
	 * @param array|string $atts  Shortcode attributes (WordPress passes '' when none given).
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'id'         => 0,
				'show_title' => 0, // Hidden by default — page already has its own title.
			],
			is_array( $atts ) ? $atts : [],
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

		// Merge global defaults with per-petition overrides.
		$defaults = get_option( 'jtc_petition_defaults', [] );
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
		$recent = [];
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
			) ?: [];
		}

		// Custom form fields.
		$raw_fields = get_post_meta( $petition_id, '_jtc_form_fields', true );
		$fields     = $raw_fields ? json_decode( $raw_fields, true ) : [];
		if ( ! is_array( $fields ) ) {
			$fields = [];
		}

		// Enqueue assets and pass runtime data to JS.
		wp_enqueue_style( 'jtc-public' );
		wp_enqueue_script( 'jtc-public' );
		wp_localize_script( 'jtc-public', 'jtcData', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'jtc_sign_petition' ),
			'petitionId' => $petition_id,
			'i18n'       => [
				'signing'      => __( 'Signing…',                          'join-the-cause' ),
				'sign'         => __( 'Sign the Petition',                 'join-the-cause' ),
				'errorGeneric' => __( 'Something went wrong. Please try again.', 'join-the-cause' ),
			],
		] );

		ob_start();
		$this->render_petition( $petition, $s, $count, $recent, $fields, $show_title );
		return ob_get_clean();
	}

	// ─── Full petition layout ─────────────────────────────────────────────────

	private function render_petition(
		WP_Post $petition,
		array   $s,
		int     $count,
		array   $recent,
		array   $fields,
		bool    $show_title
	): void {
		$has_image   = has_post_thumbnail( $petition->ID );
		$goal        = (int) ( $s['goal'] ?? 0 );
		$show_count  = ! empty( $s['show_count'] );
		$show_recent = ! empty( $s['show_recent'] );
		$privacy     = get_option( 'jtc_privacy_notice', '' );
		$terms       = get_option( 'jtc_terms_of_service', '' );
		$shares      = (array) ( $s['share_buttons'] ?? [] );
		$pid         = esc_attr( $petition->ID );
		$dek         = has_excerpt( $petition )
			? get_the_excerpt( $petition )
			: wp_trim_words( wp_strip_all_tags( $petition->post_content ), 28, '…' );
		?>
		<div class="jtc-petition<?php echo $has_image ? '' : ' no-featured-image'; ?>"
		     id="jtc-petition-<?php echo $pid; ?>"
		     data-petition-id="<?php echo $pid; ?>">

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
					<?php echo get_the_post_thumbnail(
						$petition->ID,
						'large',
						[ 'loading' => 'lazy', 'class' => 'jtc-petition__hero-img' ]
					); ?>
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
				<main class="jtc-petition__content"
				      id="jtc-content-<?php echo $pid; ?>">

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
						    id="jtc-recent-list-<?php echo $pid; ?>">
							<?php foreach ( $recent as $signer ) : ?>
							<li class="jtc-recent-signers__item">
								<span class="jtc-recent-signers__name">
									<?php echo esc_html(
										$signer['first_name'] . ' ' .
										substr( $signer['last_name'], 0, 1 ) . '.'
									); ?>
								</span>
								<time class="jtc-recent-signers__time"
								      datetime="<?php echo esc_attr( $signer['signed_at'] ); ?>">
									<?php echo esc_html(
										human_time_diff( strtotime( $signer['signed_at'] ), time() ) .
										' ' . __( 'ago', 'join-the-cause' )
									); ?>
								</time>
							</li>
							<?php endforeach; ?>
						</ul>
					</section>
					<?php endif; ?>

					<?php if ( $shares ) : ?>
					<section class="jtc-share"
					         aria-label="<?php esc_attr_e( 'Share this petition', 'join-the-cause' ); ?>">
						<h2 class="jtc-share__heading">
							<?php esc_html_e( 'Share this petition', 'join-the-cause' ); ?>
						</h2>
						<div class="jtc-share__buttons">
							<?php $this->render_share_buttons( $petition, $shares ); ?>
						</div>
					</section>
					<?php endif; ?>

				</main>

				<!--
				  Right column wrapper — stretches to full grid-row height so
				  the sticky panel has a proper containing block. No extra CSS
				  needed; grid "stretch" alignment handles it.
				-->
				<div class="jtc-sign-panel-col">
					<aside class="jtc-sign-panel"
					       id="jtc-sign-panel-<?php echo $pid; ?>"
					       aria-label="<?php esc_attr_e( 'Sign the petition', 'join-the-cause' ); ?>">

						<?php if ( $show_count ) : ?>
						<div class="jtc-count" aria-live="polite" aria-atomic="true">
							<span class="jtc-count__number"
							      id="jtc-count-<?php echo $pid; ?>">
								<?php echo esc_html( number_format_i18n( $count ) ); ?>
							</span>
							<span class="jtc-count__label">
								<?php esc_html_e( 'have signed', 'join-the-cause' ); ?>
							</span>

							<?php if ( $goal > 0 ) :
								$pct = min( 100, round( ( $count / $goal ) * 100 ) );
							?>
							<div class="jtc-progress"
							     role="progressbar"
							     aria-valuenow="<?php echo esc_attr( $pct ); ?>"
							     aria-valuemin="0"
							     aria-valuemax="100"
							     aria-label="<?php printf( esc_attr__( '%d%% of goal reached', 'join-the-cause' ), $pct ); ?>">
								<div class="jtc-progress__bar"
								     style="width:<?php echo esc_attr( $pct ); ?>%"></div>
							</div>
							<p class="jtc-count__goal">
								<?php printf(
									/* translators: %s = formatted goal number */
									esc_html__( 'Goal: %s', 'join-the-cause' ),
									esc_html( number_format_i18n( $goal ) )
								); ?>
							</p>
							<?php endif; ?>
						</div>
						<?php endif; ?>

						<div class="jtc-form-wrap"
						     id="jtc-form-wrap-<?php echo $pid; ?>">
							<?php $this->render_sign_form( $petition->ID, $s, $fields, $privacy, $terms ); ?>
						</div>

					</aside>
				</div><!-- /.jtc-sign-panel-col -->

			</div><!-- /.jtc-petition__body -->
		</div><!-- /.jtc-petition -->

		<!-- Mobile sticky CTA — fixed to viewport bottom, JS-toggled visibility -->
		<div class="jtc-mobile-cta"
		     id="jtc-mobile-cta-<?php echo $pid; ?>"
		     aria-hidden="true">
			<button class="jtc-mobile-cta__button"
			        type="button"
			        aria-expanded="false"
			        aria-controls="jtc-sign-panel-<?php echo $pid; ?>">
				<?php esc_html_e( 'Sign the Petition', 'join-the-cause' ); ?>
				<?php if ( $show_count ) : ?>
				<span class="jtc-mobile-cta__count"
				      id="jtc-mobile-count-<?php echo $pid; ?>">
					— <?php echo esc_html( number_format_i18n( $count ) ); ?>
					<?php esc_html_e( 'signed', 'join-the-cause' ); ?>
				</span>
				<?php endif; ?>
			</button>
		</div>
		<?php
	}

	// ─── Sign form ────────────────────────────────────────────────────────────

	private function render_sign_form(
		int    $petition_id,
		array  $s,
		array  $fields,
		string $privacy,
		string $terms
	): void {
		$show_recent = ! empty( $s['show_recent'] );
		$pid         = esc_attr( $petition_id );
		?>
		<form class="jtc-form"
		      id="jtc-form-<?php echo $pid; ?>"
		      novalidate
		      aria-label="<?php esc_attr_e( 'Signature form', 'join-the-cause' ); ?>">

			<h2 class="jtc-form__heading">
				<?php esc_html_e( 'Sign the Petition', 'join-the-cause' ); ?>
			</h2>

			<!-- First name -->
			<div class="jtc-field">
				<label class="jtc-field__label"
				       for="jtc-first-<?php echo $pid; ?>">
					<?php esc_html_e( 'First name', 'join-the-cause' ); ?>
					<span aria-hidden="true" class="jtc-required">*</span>
				</label>
				<input class="jtc-field__input"
				       type="text"
				       id="jtc-first-<?php echo $pid; ?>"
				       name="jtc_first_name"
				       autocomplete="given-name"
				       required
				       aria-required="true"
				       aria-describedby="jtc-first-error-<?php echo $pid; ?>">
				<span class="jtc-field__error"
				      id="jtc-first-error-<?php echo $pid; ?>"
				      role="alert"
				      hidden></span>
			</div>

			<!-- Last name -->
			<div class="jtc-field">
				<label class="jtc-field__label"
				       for="jtc-last-<?php echo $pid; ?>">
					<?php esc_html_e( 'Last name', 'join-the-cause' ); ?>
					<span aria-hidden="true" class="jtc-required">*</span>
				</label>
				<input class="jtc-field__input"
				       type="text"
				       id="jtc-last-<?php echo $pid; ?>"
				       name="jtc_last_name"
				       autocomplete="family-name"
				       required
				       aria-required="true"
				       aria-describedby="jtc-last-error-<?php echo $pid; ?>">
				<span class="jtc-field__error"
				      id="jtc-last-error-<?php echo $pid; ?>"
				      role="alert"
				      hidden></span>
			</div>

			<!-- Email -->
			<div class="jtc-field">
				<label class="jtc-field__label"
				       for="jtc-email-<?php echo $pid; ?>">
					<?php esc_html_e( 'Email address', 'join-the-cause' ); ?>
					<span aria-hidden="true" class="jtc-required">*</span>
				</label>
				<input class="jtc-field__input"
				       type="email"
				       id="jtc-email-<?php echo $pid; ?>"
				       name="jtc_email"
				       autocomplete="email"
				       required
				       aria-required="true"
				       aria-describedby="jtc-email-error-<?php echo $pid; ?>">
				<span class="jtc-field__error"
				      id="jtc-email-error-<?php echo $pid; ?>"
				      role="alert"
				      hidden></span>
			</div>

			<!-- Custom fields -->
			<?php foreach ( $fields as $field ) :
				$field_id   = sanitize_key( $field['id'] );
				$input_id   = 'jtc-extra-' . $petition_id . '-' . $field_id;
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
					<?php echo $label_text; ?>
					<?php if ( $required ) : ?>
					<span aria-hidden="true" class="jtc-required">*</span>
					<?php endif; ?>
				</label>
				<?php endif; ?>

				<?php if ( 'textarea' === $type ) : ?>
				<textarea class="jtc-field__input jtc-field__textarea"
				          id="<?php echo esc_attr( $input_id ); ?>"
				          name="<?php echo esc_attr( $input_name ); ?>"
				          placeholder="<?php echo $ph; ?>"
				          <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>
				          rows="3"></textarea>

				<?php elseif ( 'checkbox' === $type ) : ?>
				<label class="jtc-field__checkbox-label">
					<input class="jtc-field__checkbox"
					       type="checkbox"
					       id="<?php echo esc_attr( $input_id ); ?>"
					       name="<?php echo esc_attr( $input_name ); ?>"
					       value="1"
					       <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
					<?php echo $label_text; ?>
				</label>

				<?php elseif ( 'select' === $type ) : ?>
				<select class="jtc-field__input jtc-field__select"
				        id="<?php echo esc_attr( $input_id ); ?>"
				        name="<?php echo esc_attr( $input_name ); ?>"
				        <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
					<option value="">
						<?php esc_html_e( '— Select —', 'join-the-cause' ); ?>
					</option>
					<?php foreach ( (array) ( $field['options'] ?? [] ) as $opt ) : ?>
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
				       placeholder="<?php echo $ph; ?>"
				       <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
				<?php endif; ?>

			</div>
			<?php endforeach; ?>

			<!-- Name-display consent (auto-added when show_recent is on) -->
			<?php if ( $show_recent ) : ?>
			<div class="jtc-field">
				<label class="jtc-field__checkbox-label">
					<input class="jtc-field__checkbox"
					       type="checkbox"
					       name="jtc_display_consent"
					       id="jtc-display-consent-<?php echo $pid; ?>"
					       value="1">
					<?php esc_html_e( 'Show my name in the list of recent supporters', 'join-the-cause' ); ?>
				</label>
			</div>
			<?php endif; ?>

			<?php if ( $privacy ) : ?>
			<p class="jtc-form__privacy"><?php echo wp_kses_post( $privacy ); ?></p>
			<?php endif; ?>

			<?php if ( $terms ) : ?>
			<p class="jtc-form__terms"><?php echo wp_kses_post( $terms ); ?></p>
			<?php endif; ?>

			<div class="jtc-form__status"
			     id="jtc-status-<?php echo $pid; ?>"
			     role="alert"
			     aria-live="assertive"></div>

			<button class="jtc-form__submit"
			        type="submit"
			        id="jtc-submit-<?php echo $pid; ?>">
				<?php esc_html_e( 'Sign the Petition', 'join-the-cause' ); ?>
			</button>

		</form>
		<?php
	}

	// ─── Share buttons ────────────────────────────────────────────────────────

	private function render_share_buttons( WP_Post $petition, array $services ): void {
		$url   = rawurlencode( get_permalink( $petition->ID ) );
		$title = rawurlencode( $petition->post_title );

		$buttons = [
			'facebook' => [
				'label' => __( 'Share on Facebook',    'join-the-cause' ),
				'href'  => "https://www.facebook.com/sharer/sharer.php?u={$url}",
				'icon'  => 'f',
			],
			'twitter'  => [
				'label' => __( 'Share on X (Twitter)', 'join-the-cause' ),
				'href'  => "https://twitter.com/intent/tweet?url={$url}&text={$title}",
				'icon'  => '𝕏',
			],
			'copy'     => [
				'label' => __( 'Copy link',             'join-the-cause' ),
				'href'  => '#copy',
				'icon'  => '🔗',
			],
			'embed'    => [
				'label' => __( 'Embed',                 'join-the-cause' ),
				'href'  => '#embed',
				'icon'  => '</>',
			],
		];

		foreach ( $services as $svc ) {
			if ( ! isset( $buttons[ $svc ] ) ) continue;
			$b      = $buttons[ $svc ];
			$is_ext = in_array( $svc, [ 'facebook', 'twitter' ], true );
			printf(
				'<a class="jtc-share__btn jtc-share__btn--%1$s" href="%2$s" %3$s aria-label="%4$s" rel="noopener noreferrer">%5$s</a>',
				esc_attr( $svc ),
				esc_url( $b['href'] ),
				$is_ext ? 'target="_blank"' : 'data-action="' . esc_attr( $svc ) . '"',
				esc_attr( $b['label'] ),
				esc_html( $b['icon'] )
			);
		}
	}
}
