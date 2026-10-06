<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- Required WordPress block/template filename convention.

/**
 * Asset metadata for the jtc/petition block editor script.
 *
 * Hand-written (the block has no build step). Read by
 * register_block_script_handle() when the block type is registered on init:
 * the dependency list guarantees wp-blocks/wp-element/etc. are loaded before
 * index.js runs, and listing wp-i18n enables the automatic
 * wp_set_script_translations() for the block.json textdomain.
 *
 * @package JoinTheCause
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-components',
		'wp-block-editor',
		'wp-i18n',
		'wp-server-side-render',
		'wp-api-fetch',
	),
	'version'      => defined( 'JTC_VERSION' ) ? JTC_VERSION : '0.1.0',
);
