<?php
/**
 * Plugin Name: MRN Contextual Content Editor
 * Description: Adds a logged-in front-end contextual menu that opens matching Classic Editor and ACF fields for the current content.
 * Author: MRN Web Designs
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'MRN_CONTEXTUAL_CONTENT_EDITOR_LOADED' ) ) {
	return;
}

define( 'MRN_CONTEXTUAL_CONTENT_EDITOR_LOADED', true );
define( 'MRN_CONTEXTUAL_CONTENT_EDITOR_FILE', __FILE__ );
define( 'MRN_CONTEXTUAL_CONTENT_EDITOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'MRN_CONTEXTUAL_CONTENT_EDITOR_URL', plugin_dir_url( __FILE__ ) );

require_once MRN_CONTEXTUAL_CONTENT_EDITOR_DIR . 'includes/class-mrn-contextual-content-editor.php';

MRN_Contextual_Content_Editor::init();

if ( ! function_exists( 'mrn_contextual_content_editor_attrs' ) ) {
	/**
	 * Return escaped data attributes for exact front-end edit targets.
	 *
	 * Example:
	 * echo '<h2' . mrn_contextual_content_editor_attrs(
	 *     array(
	 *         'post_id'  => get_the_ID(),
	 *         'acf_key'  => 'field_abc123',
	 *         'acf_name' => 'hero_heading',
	 *         'label'    => 'Hero heading',
	 *     )
	 * ) . '>' . esc_html( $heading ) . '</h2>';
	 *
	 * @param array<string, mixed> $args Attribute values.
	 * @return string
	 */
	function mrn_contextual_content_editor_attrs( $args = array() ) {
		return MRN_Contextual_Content_Editor::get_data_attributes( is_array( $args ) ? $args : array() );
	}
}
