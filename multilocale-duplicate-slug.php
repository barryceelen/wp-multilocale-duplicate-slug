<?php
/**
 * Main plugin file
 *
 * @link              https://github.com/barryceelen/wp-multilocale-duplicate-slug
 * @since             1.0.0
 * @package           WordPress
 * @subpackage        Multilocale_Slug
 *
 * Plugin Name:       Multilocale Duplicate Slug
 * Plugin URI:        https://github.com/barryceelen/wp-multilocale-duplicate-slug
 * GitHub Plugin URI: https://github.com/barryceelen/wp-multilocale-duplicate-slug
 * Description:       Allow post translations to have the same slug.
 * Version:           1.0.0
 * Author:            Barry Ceelen
 * Author URI:        https://github.com/barryceelen
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       multilocale-slug
 * Domain Path:       /languages
 */

register_activation_hook( __FILE__, 'multilocale_slug_on_activate' );
register_deactivation_hook( __FILE__, 'multilocale_slug_on_deactivate' );

/**
 * Runs when the plugin is activated.
 *
 * @since 1.0.0
 */
function multilocale_slug_on_activate() {
	$regex = '^' . apply_filters( 'multilocale_slug_attachment_rewrite_slug', 'media' ) . '/([0-9]+)/?';
	add_rewrite_rule( $regex, 'index.php?attachment_id=$matches[1]', 'top' );
	flush_rewrite_rules();
}

/**
 * Runs when the plugin is deactivated.
 *
 * @since 1.0.0
 */
function multilocale_slug_on_deactivate() {
	delete_option( 'rewrite_rules' );
}

add_action( 'init', 'multilocale_slug_init' );

/**
 * Initialize plugin by adding filters.
 *
 * @since 1.0.0
 */
function multilocale_slug_init() {

	if ( empty( get_option( 'permalink_structure' ) ) || ! function_exists( 'multilocale' ) ) {
		return;
	}

	include 'includes/functions.php';

	// Prevent the Multilocale plugin from trying to redirect an unlocalized url to its localized version.
	add_filter( 'multilocale_redirect_to_localized_post_url', '__return_false' );

	// Allows duplicate slugs for posts in different locales.
	add_filter( 'wp_unique_post_slug', 'multilocale_slug_filter_unique_post_slug', 10, 6 );

	// Filter query to enable querying for a singular post by slug in a specific locale.
	add_filter( 'query', 'multilocale_filter_page_query', 0, 2 );

	// Filter queries.
	add_filter( 'posts_where', 'multilocale_slug_filter_posts_where_and_join', 10, 2 );
	add_filter( 'posts_join', 'multilocale_slug_filter_posts_where_and_join', 10, 2 );

	/*
	 * Translating attachments is currently not supported.
	 * Filtering the query for get_page_by path() is not a happy moment in the life of
	 * attachment permalinks so let's go ahead and change attachment permalinks.
	 */
	add_filter( 'attachment_link', 'multilocale_slug_attachment_link', 10, 2 );
}
