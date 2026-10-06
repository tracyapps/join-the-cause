<?php
/**
 * Stored petition URLs and QR helpers. Public rendering never refreshes Short.io.
 *
 * @package JoinTheCause
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Get petition short url.
 *
 * @param int  $petition_id Petition post ID.
 * @param bool $allow_refresh Allow refresh.
 * @return string Result value.
 */
function jtc_get_petition_short_url( int $petition_id, bool $allow_refresh = false ): string {
	$client = new JTC_Short_IO();

	if ( $allow_refresh && $client->is_configured() ) {
		// Internally throttled by a short transient: at most one remote
		// refresh per petition per ~10 minutes, never on every view.
		$client->refresh_petition_link_from_remote( $petition_id );
	}

	$data = $client->get_petition_data( $petition_id );

	return jtc_fallback( $data['secure_url'], $data['short_url'] );
}
/**
 * Get petition share url.
 *
 * @param int  $petition_id Petition post ID.
 * @param bool $allow_refresh Allow refresh.
 * @return string Result value.
 */
function jtc_get_petition_share_url( int $petition_id, bool $allow_refresh = false ): string {
	$client = new JTC_Short_IO();

	// The short URL is only ever used when Short.io is fully configured,
	// so a disabled integration can never serve a stale short link.
	if ( $client->is_configured() && get_option( 'jtc_shortio_use_for_sharing', 0 ) ) {
		$short = jtc_get_petition_short_url( $petition_id, $allow_refresh );
		if ( $short ) {
			return $short;
		}
	}

	return (string) get_permalink( $petition_id );
}
/**
 * Get petition qr url.
 *
 * @param int $petition_id Petition post ID.
 * @return string Result value.
 */
function jtc_get_petition_qr_url( int $petition_id ): string {
	$attachment_id = (int) get_post_meta( $petition_id, '_jtc_shortio_qr_attachment_id', true );

	return $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '';
}
