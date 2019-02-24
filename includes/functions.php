<?php
/**
 * Plugin functions
 *
 * @link    https://github.com/barryceelen/wp-multilocale-duplicate-slug
 * @since   1.0.0
 * @package Multilocale_Slug
 */

/**
 * Filter query for pages.
 *
 * In WP_Query requests for single pages eventually end up in get_page_by_path().
 * All of this before an action or filter can be called, thus no filters are available
 * other than hard-core manipulating the actual $query.
 *
 * Note: This also processes attachments which are not currently supported.
 * Note: 'post' post types can also end up in get_page_by_path() if /%postname% is set as the permalink structure.
 *
 * @since 1.0.0
 * @param [type] $query [description].
 * @return [type] [description].
 */
function multilocale_filter_page_query( $query ) {

	if ( is_admin() ) {
		return $query;
	}

	$string = preg_replace( '/\v(?:[\v\h]+)/', ' ', $query );

	if ( strpos( $string, 'SELECT ID, post_name, post_parent, post_type' ) && strpos( $string, 'WHERE post_name IN' ) ) {

		global $wpdb;

		$locale_id = multilocale_locale()->locale_obj->term_id;
		$pos       = strpos( $string, 'WHERE' );
		$array     = array(
			substr( $string, 0, $pos ),
			'INNER JOIN ' . $wpdb->prefix . 'term_relationships AS multilocale_tr ON multilocale_tr.object_id = ID',
			substr( $string, $pos ),
			"AND multilocale_tr.term_taxonomy_id IN ('{$locale_id}')",
		);

		$query = join( ' ', $array );

		// Todo: Removing the query filter after it has run once for now.
		remove_filter( 'query', 'multilocale_filter_page_query', 0 );
	}

	return $query;
}

/**
 * Try to modify WHERE and JOIN clause for single post queries.
 *
 * We're allowing duplicate post slugs for posts in different locales so we need to get
 * the post in a specific locale. Unfortunately tax queries on single post requests are
 * ignored, hence we'll set the WHERE and JOIN clauses.
 *
 * Note: Just looking at post_type, if it is empty and post_name is empty we'll assume the 'post' post_type.
 *
 *       Pages are treated differently in parse_query.
 *       If 'pagename' is set in $wp_query, get_page_by_path() is called and the $queried_object is set if a page is found.
 *       Also, $queried_object_id is set. 'is_page' is set to false if a post exists with the same slug and the permalink
 *       structure is set to /%postname%
 *
 * @since 1.0.0
 *
 * @param string   $string   The WHERE or JOIN clause of the query.
 * @param WP_Query $wp_query The WP_Query instance (passed by reference).
 * @return string The original or modified WHERE or JOIN clause.
 */
function multilocale_slug_filter_posts_where_and_join( $string, $wp_query ) {

	if ( ! is_admin() && $wp_query->is_main_query() && $wp_query->is_single() && ! is_attachment() ) { // Unfortunately, pages (and attachments) are handled elsewhere.

		$post_type = empty( $wp_query->query_vars['post_type'] ) ? 'post' : $wp_query->query_vars['post_type'];

		if ( ! post_type_supports( $post_type, 'multilocale' ) ) {
			return $string;
		}

		if ( 'posts_where' === current_filter() ) {
			$string = $string . ' AND multilocale_tr.term_taxonomy_id IN (' . multilocale_locale()->locale_obj->term_id . ')';
		} else {
			global $wpdb;
			$string = $string . " INNER JOIN $wpdb->term_relationships AS multilocale_tr ON multilocale_tr.object_id = ID";
		}
	}

	return $string;
}

/**
 * Computes a unique slug for the post, when given the desired slug and some post details.
 *
 * Duplicate post slugs are allowed for posts in different locales.
 * Mostly a duplication of the {@see wp_unique_post_slug()} function.
 *
 * @param string $slug          The post slug.
 * @param int    $post_id       Post ID.
 * @param string $post_status   The post status.
 * @param string $post_type     Post type.
 * @param int    $post_parent   Post parent ID.
 * @param string $original_slug The original post slug.
 * @return string Unique slug for the post, based on $post_name (with a -1, -2, etc. suffix).
 */
function multilocale_slug_filter_unique_post_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {

	global $wpdb, $wp_rewrite;

	if ( $original_slug === $slug ) {
		return $slug;
	}

	// If our slug doesn't end with a number, we don't need to worry about it.
	if ( ! preg_match( '|[0-9]$|', $slug ) ) {
		return $slug;
	}

	// Attachments and menu items are currently not supported.
	if ( 'attachment' === $post_type || 'nav_menu_item' === $post_type || ! post_type_supports( $post_type, 'multilocale' ) ) {
		return $slug;
	}

	$post_locale = multilocale_get_post_locale( $post_id );

	if ( ! $post_locale ) {
		return $slug;
	}

	$feeds = $wp_rewrite->feeds;

	if ( ! is_array( $feeds ) ) {
		$feeds = array();
	}

	if ( is_post_type_hierarchical( $post_type ) ) {

		$check_sql = "SELECT post_name FROM $wpdb->posts INNER JOIN $wpdb->term_relationships AS tr ON tr.object_id = ID WHERE post_name = %s AND post_type = %s AND ID != %d AND post_parent = %d AND tr.term_taxonomy_id = %d LIMIT 1";
		$post_name_check = $wpdb->get_var( $wpdb->prepare( $check_sql, $original_slug, $post_type, $post_id, $post_parent, $post_locale->term_id ) );

		/**
		 * Filter whether the post slug would make a bad hierarchical post slug.
		 *
		 * @since 3.1.0
		 *
		 * @param bool   $bad_slug    Whether the post slug would be bad in a hierarchical post context.
		 * @param string $slug        The post slug.
		 * @param string $post_type   Post type.
		 * @param int    $post_parent Post parent ID.
		 */
		if ( $post_name_check || in_array( $original_slug, $feeds ) || preg_match( "@^($wp_rewrite->pagination_base)?\d+$@", $original_slug )  || apply_filters( 'wp_unique_post_slug_is_bad_hierarchical_slug', false, $original_slug, $post_type, $post_parent ) ) {
			$suffix = 2;
			do {
				$alt_post_name = _truncate_post_slug( $original_slug, 200 - ( strlen( $suffix ) + 1 ) ) . "-$suffix";
				$post_name_check = $wpdb->get_var( $wpdb->prepare( $check_sql, $alt_post_name, $post_type, $post_id, $post_parent, $post_locale->term_id ) );
				$suffix++;
			} while ( $post_name_check );
			$slug = $alt_post_name;
		} else {
			$slug = $original_slug;
		}
	} else {

		$check_sql = "SELECT post_name FROM $wpdb->posts INNER JOIN $wpdb->term_relationships AS tr ON tr.object_id = ID WHERE post_name = %s AND post_type = %s AND ID != %d AND tr.term_taxonomy_id = %d LIMIT 1";
		$post_name_check = $wpdb->get_row( $wpdb->prepare( $check_sql, $original_slug, $post_type, $post_id, $post_locale->term_id ) );

		// Prevent new post slugs that could result in URLs that conflict with date archives.
		$post = get_post( $post_id );
		$conflicts_with_date_archive = false;

		if ( 'post' === $post_type && ( ! $post || $post->post_name !== $original_slug ) && preg_match( '/^[0-9]+$/', $original_slug ) && $slug_num = intval( $original_slug ) ) {

			$permastructs   = array_values( array_filter( explode( '/', get_option( 'permalink_structure' ) ) ) );
			$postname_index = array_search( '%postname%', $permastructs );

			/*
			 * Potential date clashes are as follows:
			 *
			 * - Any integer in the first permastruct position could be a year.
			 * - An integer between 1 and 12 that follows 'year' conflicts with 'monthnum'.
			 * - An integer between 1 and 31 that follows 'monthnum' conflicts with 'day'.
			 */
			if ( 0 === $postname_index ||
				( $postname_index && '%year%' === $permastructs[ $postname_index - 1 ] && 13 > $slug_num ) ||
				( $postname_index && '%monthnum%' === $permastructs[ $postname_index - 1 ] && 32 > $slug_num )
			) {
				$conflicts_with_date_archive = true;
			}
		}

		/**
		 * Filter whether the post slug would be bad as a flat slug.
		 *
		 * @since 3.1.0
		 *
		 * @param bool   $bad_slug  Whether the post slug would be bad as a flat slug.
		 * @param string $slug      The post slug.
		 * @param string $post_type Post type.
		 */
		if ( $post_name_check || in_array( $original_slug, $feeds, true ) || $conflicts_with_date_archive || apply_filters( 'wp_unique_post_slug_is_bad_flat_slug', false, $original_slug, $post_type ) ) {
			$suffix = 2;
			do {
				$alt_post_name = _truncate_post_slug( $original_slug, 200 - ( strlen( $suffix ) + 1 ) ) . "-$suffix";
				$post_name_check = $wpdb->get_var( $wpdb->prepare( $check_sql, $alt_post_name, $post_type, $post_id, $post_locale->term_id ) );
				$suffix++;
			} while ( $post_name_check );
			$slug = $alt_post_name;
		} else {
			$slug = $original_slug;
		}
	}

	return $slug;
}

/**
 * Filter attachment link.
 *
 * Attachment links don't respond well to the 'duplicate slug' treatment given by this plugin.
 * This function creates new links for attachments: http://example.com/media/my-cool-attachment.
 *
 * @since 1.0.0
 * @param string $link    Orginal attachment URL.
 * @param int    $post_id ID of the attachment in question.
 * @return string Modified attachment URL.
 */
function multilocale_slug_attachment_link( $link, $post_id ) {

	if ( empty( get_option( 'permalink_structure' ) ) ) {
		return $link;
	}

	$post = get_post( $post_id );

	/**
	 * Filter the prefix used in attachment urls.
	 *
	 * @since 1.0.0
	 *
	 * @var string Prefix.
	 */
	$prefix = apply_filters( 'multilocale_slug_attachment_rewrite_slug', 'media' );

	return home_url( '/' . $prefix . '/' . $post->post_name );
}
