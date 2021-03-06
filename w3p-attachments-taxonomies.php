<?php
/*
 * The aim of this script is to provide the WordPress native UI to attachments taxonomies.
 * Register your taxonomies, include this script, you're done.
 * Tested on WP 3.4.1. May be this script will be obsolete with WP 3.5: media management is one of possible changes in this version.
 * For feedback, feel free to leave a comment here: http://scri.in/media-taxos (sorry, my site is in french, but I can speak frenglish ;))
 *
 * Version: 1.0.1
 */

if ( is_admin() ):

// !MANAGEMENT PAGES (edit-tags.php) ---------------------------------------------------------------------------------------------------------------------------

// !Adds the management pages to the admin menu
add_action('admin_menu', 'w3p_taxos_admin_menu', 11);
function w3p_taxos_admin_menu() {
	$taxos = get_attachments_taxonomies('object');
	$taxos = apply_filters('attachment_taxonomies_in_menu', $taxos);

	if ( is_array($taxos) && count($taxos) ) {
		foreach ( $taxos as $tax ) {
			add_media_page( $tax->label, $tax->labels->menu_name, $tax->cap->manage_terms, 'edit-tags.php?taxonomy='.$tax->name.'&post_type=attachment' );
		}
	}
}


// !Highlight the good items in the admin menu for the management pages
add_filter('parent_file', 'w3p_taxos_parent_file');
function w3p_taxos_parent_file( $parent_file ) {
	$taxos  = get_attachments_taxonomies();
	$screen = get_current_screen();

	if ( !count($taxos) || !in_array($screen->taxonomy, $taxos) )
		return $parent_file;

	global $submenu_file;
	$submenu_file = 'edit-tags.php?taxonomy='.$screen->taxonomy.'&post_type=attachment';
	return 'upload.php';
}


// !Change the "Posts" column to display "Medias" as a title and to have the good link to the medias page (edit.php -> upload.php)
add_action('admin_head-edit-tags.php', 'w3p_taxos_posts_column');
function w3p_taxos_posts_column() {
	$taxos  = get_attachments_taxonomies();
	$screen = get_current_screen();

	if ( !count($taxos) || !in_array($screen->taxonomy, $taxos) )
		return;

	class WP_Media_Category_List_Table extends WP_Terms_List_Table {

		function get_columns() {
			global $taxonomy, $post_type;

			$columns = array(
				'cb'          => '<input type="checkbox" />',
				'name'        => _x( 'Name', 'term name' ),
				'description' => __( 'Description' ),
				'slug'        => __( 'Slug' ),
				'posts'       => __( 'Media' ),
			);

			return $columns;
		}

		function column_posts( $tag ) {
			global $taxonomy, $post_type;

			$count = number_format_i18n( $tag->count );

			$tax = get_taxonomy( $taxonomy );

			$ptype_object = get_post_type_object( $post_type );
			if ( ! $ptype_object->show_ui )
				return $count;

			if ( $tax->query_var ) {
				$args = array( $tax->query_var => $tag->slug );
			} else {
				$args = array( 'taxonomy' => $tax->name, 'term' => $tag->slug );
			}

			if ( 'post' != $post_type )
				$args['post_type'] = $post_type;

			return "<a href='" . esc_url ( add_query_arg( $args, 'upload.php' ) ) . "'>$count</a>";
		}

	}

	global $wp_list_table;
	$wp_list_table = new WP_Media_Category_List_Table;

	$wp_list_table->prepare_items();
}


// !LIBRARY PAGE (upload.php) ---------------------------------------------------------------------------------------------------------------------------

// !Add the taxonomies columns in the media table
add_filter( 'manage_media_columns', 'w3p_taxos_manage_media_column_title', 10, 2 );
function w3p_taxos_manage_media_column_title( $posts_columns, $detached ) {
	$taxos = get_attachments_taxonomies('object');

	if ( count($taxos) ) {
		// Add the taxonomies columns
		foreach ( $taxos as $tax ) {
			$posts_columns[$tax->name] = $tax->label;
		}

		// Push the "date" column to the end
		if ( isset($posts_columns['date']) ) {
			$date_column = $posts_columns['date'];
			unset($posts_columns['date']);
			$posts_columns['date'] = $date_column;
		}
	}

	return $posts_columns;
}


// !Content of the taxonomies columns
add_action( 'manage_media_custom_column', 'w3p_taxos_manage_media_column_content', 10, 2 );
function w3p_taxos_manage_media_column_content( $column_name, $id ) {
	$taxos = get_attachments_taxonomies();

	if ( count($taxos) && array_search($column_name, $taxos) !== false ) {
		$terms = wp_get_post_terms( $id, $column_name, array( 'fields' => 'names' ) );
		echo implode( _x( ',', 'tag delimiter' ).' ', $terms );
	}
}


// !Add CSS in upload.php head to set the taxonomies columns width
add_action('admin_print_styles-upload.php', 'w3p_taxos_admin_head');
// !Add CSS in media.php head to set the taxonomies meta-boxes width
add_action('admin_print_styles-media.php', 'w3p_taxos_admin_head');
// !Add CSS in media-upload-popup head to set the taxonomies meta-boxes width
add_action('admin_print_styles-media-upload-popup', 'w3p_taxos_admin_head');
function w3p_taxos_admin_head() {
	$taxos = get_attachments_taxonomies();

	if ( count($taxos) ) {
		$col_w = esc_attr(apply_filters('attachment_taxonomies_columns_width', '10%'));
		echo '<style type="text/css">';
		foreach ( $taxos as $tax ) {
			echo '.fixed .column-'.$tax.'{width:'.$col_w.';}';
		}
		echo '.categorydiv{width:462px;}.media-item .describe .tagsdiv .newtag{width:384px;}';
		echo '</style>';
	}
}


// !EDIT MEDIA PAGE (media.php) ---------------------------------------------------------------------------------------------------------------------------

// !Replace the simple text inputs with meta-boxes
add_filter('attachment_fields_to_edit', 'w3p_taxos_attachment_fields_to_edit', 0, 2);
function w3p_taxos_attachment_fields_to_edit($form_fields, $post) {
	$screen_id = get_current_screen()->id;
	if ( $screen_id != 'media' && $screen_id != 'media-upload' )
		return $form_fields;

	$taxos = get_attachments_taxonomies('object');
	$taxos = apply_filters('attachment_taxonomies_meta_box_fields', $taxos);

	if ( is_array($taxos) && count($taxos) ) {
		// The meta-boxes need javascript (but bad news, post.js must be rewritten because it does not work well with multiple meta-boxes: we have multiple identical ids)
		$script_url = str_replace(ABSPATH, site_url().'/', dirname(__FILE__)) . '/' . basename(__FILE__, '.php') . '.js';
		wp_enqueue_script ('w3p-attachments-taxonomies', $script_url, array('suggest', 'wp-lists'), '1.0', true);
		wp_localize_script('w3p-attachments-taxonomies', 'postL10n', array('comma' => _x( ',', 'tag delimiter' )));

		// The meta-boxes.php file is not included yet
		if ( !function_exists('post_categories_meta_box') )
			require(ABSPATH . '/wp-admin/includes/meta-boxes.php');

		foreach ( $taxos as $tax ) {
			if ( isset($form_fields[$tax->name]) ) {
				ob_start();
				if ( $tax->hierarchical ) {
					post_categories_meta_box( $post, array( 'args' => array('taxonomy' => $tax->name) ) );
					echo '<input type="hidden" name="w3p_taxos_att_id" value="'.$post->ID.'"/>';	// Attachment id is needed for ajax
				} else
					post_tags_meta_box( $post, array( 'args' => array('taxonomy' => $tax->name), 'title' => $tax->label ) );
				$html = ob_get_contents();
				ob_end_clean();

				// We must add the post id in inputs name, overwise it won't work with multiple meta-boxes
				$html = str_replace( 'tax_input['.$tax->name.']', 'tax_input['.$post->ID.']['.$tax->name.']', $html );
				$form_fields[$tax->name]['html']  = $html;
				$form_fields[$tax->name]['input'] = 'html';
			}
		}
	}

	return $form_fields;
}


// !Save the meta-boxes values
add_filter('attachment_fields_to_save', 'w3p_taxos_attachment_fields_to_save', 10, 2);
function w3p_taxos_attachment_fields_to_save($post, $attachment) {

	$post_id = (int) $post['ID'];
	$tax_input = array();

	if ( $post_id && isset($_POST['tax_input'][$post_id])) {
		foreach ( $_POST['tax_input'][$post_id] as $tax_name => $terms ) {
			if ( empty($terms) )
				continue;
			if ( is_taxonomy_hierarchical( $tax_name ) ) {
				$tax_input[ $tax_name ] = array_map( 'absint', $terms );
			} else {
				$comma = _x( ',', 'tag delimiter' );
				if ( ',' !== $comma )
					$terms = str_replace( $comma, ',', $terms );
				$tax_input[ $tax_name ] = explode( ',', trim( $terms, " \n\t\r\0\x0B," ) );
			}
		}

		$taxonomies = get_attachment_taxonomies($post);
		foreach ( $taxonomies as $t ) {
			if ( isset($tax_input[$t]) )
				wp_set_object_terms($post_id, $tax_input[$t], $t, false);
		}
	}

	return $post;
}


// !For hierarchical taxonomies we need an ajax filter to change the "name" attributes in the response
add_action( 'registered_taxonomy', 'w3p_taxos_ajax_actions', 10, 3 );
function w3p_taxos_ajax_actions($taxonomy, $object_type, $args) {
	if ( $args['hierarchical'] == true && ($object_type = 'attachment' || strpos($object_type, 'attachment:') === 0) ) {
		remove_filter('wp_ajax_add-' . $taxonomy, '_wp_ajax_add_hierarchical_term');
		add_filter('wp_ajax_add-' . $taxonomy, 'w3p_taxos_wp_ajax_add_hierarchical_term');
	}
}


function w3p_taxos_wp_ajax_add_hierarchical_term() {
	$action   = $_POST['action'];
	$taxonomy = get_taxonomy(substr($action, 4));
	$post_id  = isset($_POST['w3p_taxos_att_id']) ? (int)$_POST['w3p_taxos_att_id'] : 0;	// Attachment id
	check_ajax_referer( $action, '_ajax_nonce-add-' . $taxonomy->name );
	if ( !current_user_can( $taxonomy->cap->edit_terms ) )
		wp_die( -1 );
	$names = explode(',', $_POST['new'.$taxonomy->name]);
	$parent = isset($_POST['new'.$taxonomy->name.'_parent']) ? (int) $_POST['new'.$taxonomy->name.'_parent'] : 0;
	if ( 0 > $parent )
		$parent = 0;
	if ( $taxonomy->name == 'category' ) {
		if ($post_id)
			$post_category = isset($_POST['post_category'][$post_id]) ? (array) $_POST['post_category'][$post_id] : array();
		else
			$post_category = isset($_POST['post_category']) ? (array) $_POST['post_category'] : array();
	} else {
		if ($post_id)
			$post_category = ( isset($_POST['tax_input'][$post_id], $_POST['tax_input'][$post_id][$taxonomy->name]) ) ? (array) $_POST['tax_input'][$post_id][$taxonomy->name] : array();
		else
			$post_category = ( isset($_POST['tax_input'], $_POST['tax_input'][$taxonomy->name]) ) ? (array) $_POST['tax_input'][$taxonomy->name] : array();
	}
	$checked_categories = array_map( 'absint', (array) $post_category );
	$popular_ids = wp_popular_terms_checklist($taxonomy->name, 0, 10, false);

	foreach ( $names as $cat_name ) {
		$cat_name = trim($cat_name);
		$category_nicename = sanitize_title($cat_name);
		if ( '' === $category_nicename )
			continue;
		if ( !$cat_id = term_exists( $cat_name, $taxonomy->name, $parent ) )
			$cat_id = wp_insert_term( $cat_name, $taxonomy->name, array( 'parent' => $parent ) );
		if ( is_wp_error( $cat_id ) )
			continue;
		else if ( is_array( $cat_id ) )
			$cat_id = $cat_id['term_id'];
		$checked_categories[] = $cat_id;
		if ( $parent ) // Do these all at once in a second
			continue;
		ob_start();
			wp_terms_checklist( 0, array( 'taxonomy' => $taxonomy->name, 'descendants_and_self' => $cat_id, 'selected_cats' => $checked_categories, 'popular_cats' => $popular_ids ));
		$data = ob_get_contents();
		ob_end_clean();
		$add = array(
			'what' => $taxonomy->name,
			'id' => $cat_id,
			'data' => str_replace( array("\n", "\t"), '', $data),
			'position' => -1
		);
	}

	if ( $parent ) { // Foncy - replace the parent and all its children
		$parent = get_term( $parent, $taxonomy->name );
		$term_id = $parent->term_id;

		while ( $parent->parent ) { // get the top parent
			$parent = &get_term( $parent->parent, $taxonomy->name );
			if ( is_wp_error( $parent ) )
				break;
			$term_id = $parent->term_id;
		}

		ob_start();
			wp_terms_checklist( 0, array('taxonomy' => $taxonomy->name, 'descendants_and_self' => $term_id, 'selected_cats' => $checked_categories, 'popular_cats' => $popular_ids));
		$data = ob_get_contents();
		ob_end_clean();
		$add = array(
			'what' => $taxonomy->name,
			'id' => $term_id,
			'data' => str_replace( array("\n", "\t"), '', $data),
			'position' => -1
		);
	}

	if ( isset($add['data']) && !empty($add['data']) )	// Need the attachemnt id in the name attribute
		$add['data'] = str_replace( 'tax_input['.$taxonomy->name.']', 'tax_input['.$post_id.']['.$taxonomy->name.']', $add['data'] );

	ob_start();
		wp_dropdown_categories( array(
			'taxonomy' => $taxonomy->name, 'hide_empty' => 0, 'name' => 'new'.$taxonomy->name.'_parent', 'orderby' => 'name',
			'hierarchical' => 1, 'show_option_none' => '&mdash; '.$taxonomy->labels->parent_item.' &mdash;'
		) );
	$sup = ob_get_contents();
	ob_end_clean();
	$add['supplemental'] = array( 'newcat_parent' => $sup );

	$x = new WP_Ajax_Response( $add );
	$x->send();
}


// !UTILITIES ---------------------------------------------------------------------------------------------------------------------------

// !Returns all attachments taxonomies, including those declared with "attachment:whatever"
// Uses strpos_zero_in_array()
// Returns an array of taxonomies names or taxonomies objects
if ( !function_exists('get_attachments_taxonomies') ) {
	function get_attachments_taxonomies($output = 'names') {
		global $wp_taxonomies;

		$taxonomies = array();
		foreach ( (array) $wp_taxonomies as $tax_name => $tax_obj ) {
			if ( in_array('attachment', (array) $tax_obj->object_type) || strpos_zero_in_array('attachment:', (array) $tax_obj->object_type) ) {
				if ( 'names' == $output )
					$taxonomies[] = $tax_name;
				else
					$taxonomies[ $tax_name ] = $tax_obj;
			}
		}

		return $taxonomies;
	}
}


// !Kind of in_array() but returns true for values starting with $find
// Returns (bool)
if ( !function_exists('strpos_zero_in_array') ) {
	function strpos_zero_in_array($find, $arr = array()) {
		if ( !is_array($arr) || !count($arr) || !is_string($find) )
			return false;

		foreach ( $arr as $v ) {
			if ( strpos($v, $find) === 0 )
				return true;
		}
	}
}


endif;