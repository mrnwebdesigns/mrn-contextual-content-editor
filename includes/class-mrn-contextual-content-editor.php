<?php
/**
 * Front-end contextual edit menu and field resolver.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'MRN_CONTEXTUAL_CONTENT_EDITOR_URL' ) ) {
	define( 'MRN_CONTEXTUAL_CONTENT_EDITOR_URL', plugin_dir_url( dirname( __DIR__ ) . '/mrn-contextual-content-editor.php' ) );
}

final class MRN_Contextual_Content_Editor {
	const VERSION      = '0.1.0';
	const AJAX_ACTION  = 'mrn_cce_resolve_target';
	const AJAX_NONCE   = 'mrn_cce_resolve_target';
	const SCRIPT_HANDLE = 'mrn-contextual-content-editor';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 30 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_nodes' ), 2000 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'resolve_target_ajax' ) );
	}

	/**
	 * Add a small admin-bar anchor for the picker.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 * @return void
	 */
	public static function add_admin_bar_nodes( $wp_admin_bar ) {
		if ( is_admin() || ! is_admin_bar_showing() || ! self::can_use_on_current_request() ) {
			return;
		}

		$post_id = self::get_current_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'mrn-cce-root',
				'title' => esc_html__( 'Context Edit', 'mrn-contextual-content-editor' ),
				'href'  => '#',
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'mrn-cce-toggle',
				'parent' => 'mrn-cce-root',
				'title'  => esc_html__( 'Toggle Hover Menu', 'mrn-contextual-content-editor' ),
				'href'   => '#',
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'mrn-cce-edit-current',
				'parent' => 'mrn-cce-root',
				'title'  => esc_html__( 'Edit Current Content', 'mrn-contextual-content-editor' ),
				'href'   => esc_url( self::get_post_edit_url( $post_id ) ),
			)
		);
	}

	/**
	 * Enqueue the front-end picker.
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		if ( ! self::can_use_on_current_request() ) {
			return;
		}

		$post_id = self::get_current_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			MRN_CONTEXTUAL_CONTENT_EDITOR_URL . 'assets/css/frontend.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			MRN_CONTEXTUAL_CONTENT_EDITOR_URL . 'assets/js/frontend.js',
			array(),
			self::VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'MRNContextualContentEditor',
			array(
				'action'      => self::AJAX_ACTION,
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'editPostUrl' => self::get_post_edit_url( $post_id ),
				'nonce'       => wp_create_nonce( self::AJAX_NONCE ),
				'postId'      => $post_id,
				'strings'     => array(
					'editThis'      => __( 'Edit', 'mrn-contextual-content-editor' ),
					'editPage'      => __( 'Page', 'mrn-contextual-content-editor' ),
					'finding'       => __( 'Finding field...', 'mrn-contextual-content-editor' ),
					'noMatch'       => __( 'No exact field match found.', 'mrn-contextual-content-editor' ),
					'openBest'      => __( 'Open best match', 'mrn-contextual-content-editor' ),
					'pickerOff'     => __( 'Context edit menu off', 'mrn-contextual-content-editor' ),
					'pickerOn'      => __( 'Context edit menu on', 'mrn-contextual-content-editor' ),
					'resolveFailed' => __( 'Could not resolve this content.', 'mrn-contextual-content-editor' ),
				),
			)
		);
	}

	/**
	 * Enqueue admin-side focus helper for post editor URLs.
	 *
	 * @param string $hook Current admin hook suffix.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$focus = self::get_admin_focus_request();
		if ( empty( $focus['acf_key'] ) && empty( $focus['acf_name'] ) && empty( $focus['core'] ) ) {
			return;
		}

		$post_id = self::get_admin_post_id();
		if ( $post_id > 0 && ! self::current_user_can_use( $post_id ) ) {
			return;
		}

		wp_enqueue_style(
			self::SCRIPT_HANDLE . '-admin',
			MRN_CONTEXTUAL_CONTENT_EDITOR_URL . 'assets/css/admin-focus.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE . '-admin',
			MRN_CONTEXTUAL_CONTENT_EDITOR_URL . 'assets/js/admin-focus.js',
			array(),
			self::VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE . '-admin',
			'MRNContextualContentEditorFocus',
			$focus
		);
	}

	/**
	 * Resolve a front-end element payload to editable targets.
	 *
	 * @return void
	 */
	public static function resolve_target_ajax() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		$context = self::sanitize_ajax_context();
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint validates the scalar input.

		$direct_post_id = isset( $context['direct']['post_id'] ) ? absint( $context['direct']['post_id'] ) : 0;
		if ( $direct_post_id > 0 ) {
			$post_id = $direct_post_id;
		}

		if ( $post_id <= 0 || ! self::current_user_can_use( $post_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to edit this content.', 'mrn-contextual-content-editor' ),
				),
				403
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			wp_send_json_error(
				array(
					'message' => __( 'The requested content could not be found.', 'mrn-contextual-content-editor' ),
				),
				404
			);
		}

		$targets = self::resolve_targets( $post, $context );

		wp_send_json_success(
			array(
				'fallback' => array(
					'editUrl' => self::get_post_edit_url( $post->ID ),
					'label'   => __( 'Edit page', 'mrn-contextual-content-editor' ),
				),
				'targets'  => array_values( array_slice( $targets, 0, 5 ) ),
			)
		);
	}

	/**
	 * Whether the front-end picker can run on this request.
	 *
	 * @return bool
	 */
	private static function can_use_on_current_request() {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}

		$post_id = self::get_current_post_id();
		if ( $post_id <= 0 ) {
			return false;
		}

		$can_use = self::current_user_can_use( $post_id );

		return (bool) apply_filters( 'mrn_contextual_content_editor_can_use_on_request', $can_use, $post_id );
	}

	/**
	 * Check user permission for a post target.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function current_user_can_use( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$capability = (string) apply_filters( 'mrn_contextual_content_editor_required_capability', 'edit_post', $post_id );
		if ( 'edit_post' === $capability && $post_id > 0 ) {
			return current_user_can( 'edit_post', $post_id );
		}

		return current_user_can( $capability );
	}

	/**
	 * Current queried singular post ID.
	 *
	 * @return int
	 */
	private static function get_current_post_id() {
		$post_id = get_queried_object_id();
		if ( $post_id <= 0 ) {
			$post_id = get_the_ID();
		}

		return absint( $post_id );
	}

	/**
	 * Current post ID in the admin editor.
	 *
	 * @return int
	 */
	private static function get_admin_post_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only focus parameters; no state change.
		return isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
	}

	/**
	 * Read and sanitize focus parameters for the admin editor.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_admin_focus_request() {
		$focus = array(
			'acf_path' => array(),
			'acf_key'  => '',
			'acf_name' => '',
			'core'     => '',
			'label'    => '',
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only focus parameters; no state change.
		if ( isset( $_GET['mrn_cce_focus_acf'] ) ) {
			$focus['acf_key'] = sanitize_key( wp_unslash( $_GET['mrn_cce_focus_acf'] ) );
		}

		if ( isset( $_GET['mrn_cce_focus_name'] ) ) {
			$focus['acf_name'] = sanitize_key( wp_unslash( $_GET['mrn_cce_focus_name'] ) );
		}

		if ( isset( $_GET['mrn_cce_focus_core'] ) ) {
			$focus['core'] = sanitize_key( wp_unslash( $_GET['mrn_cce_focus_core'] ) );
		}

		if ( isset( $_GET['mrn_cce_focus_label'] ) ) {
			$focus['label'] = sanitize_text_field( wp_unslash( $_GET['mrn_cce_focus_label'] ) );
		}

		if ( isset( $_GET['mrn_cce_focus_path'] ) ) {
			$focus['acf_path'] = self::sanitize_acf_focus_path_json( wp_unslash( $_GET['mrn_cce_focus_path'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON path is decoded and sanitized entry-by-entry.
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$allowed_core = array( 'title', 'content', 'excerpt', 'thumbnail' );
		if ( ! in_array( $focus['core'], $allowed_core, true ) ) {
			$focus['core'] = '';
		}

		return $focus;
	}

	/**
	 * Build escaped data attributes for exact front-end edit targets.
	 *
	 * @param array<string, mixed> $args Attribute values.
	 * @return string
	 */
	public static function get_data_attributes( array $args ) {
		$attrs = array();

		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		if ( $post_id > 0 ) {
			$attrs['data-mrn-cce-post-id'] = (string) $post_id;
		}

		if ( ! empty( $args['acf_key'] ) ) {
			$attrs['data-mrn-cce-acf-key'] = sanitize_key( (string) $args['acf_key'] );
		}

		if ( ! empty( $args['acf_name'] ) ) {
			$attrs['data-mrn-cce-acf-name'] = sanitize_key( (string) $args['acf_name'] );
		}

		if ( ! empty( $args['core'] ) ) {
			$core = sanitize_key( (string) $args['core'] );
			if ( in_array( $core, array( 'title', 'content', 'excerpt', 'thumbnail' ), true ) ) {
				$attrs['data-mrn-cce-core'] = $core;
			}
		}

		if ( ! empty( $args['label'] ) ) {
			$attrs['data-mrn-cce-label'] = sanitize_text_field( (string) $args['label'] );
		}

		$output = '';
		foreach ( $attrs as $name => $value ) {
			$output .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		return $output;
	}

	/**
	 * Sanitize AJAX context payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function sanitize_ajax_context() {
		$raw_context = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified in resolve_target_ajax() before this sanitizer runs.
		if ( isset( $_POST['context'] ) && is_array( $_POST['context'] ) ) {
			$raw_context = wp_unslash( $_POST['context'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized key-by-key below.
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$context = array(
			'alt'     => '',
			'direct'  => array(),
			'href'    => '',
			'html'    => '',
			'src'     => '',
			'tagName' => '',
			'text'    => '',
			'title'   => '',
		);

		if ( isset( $raw_context['alt'] ) && is_scalar( $raw_context['alt'] ) ) {
			$context['alt'] = self::limit_string( sanitize_text_field( (string) $raw_context['alt'] ), 300 );
		}

		if ( isset( $raw_context['text'] ) && is_scalar( $raw_context['text'] ) ) {
			$context['text'] = self::limit_string( sanitize_textarea_field( (string) $raw_context['text'] ), 1200 );
		}

		if ( isset( $raw_context['html'] ) && is_scalar( $raw_context['html'] ) ) {
			$context['html'] = self::limit_string( wp_kses_post( (string) $raw_context['html'] ), 3000 );
		}

		if ( isset( $raw_context['src'] ) && is_scalar( $raw_context['src'] ) ) {
			$context['src'] = esc_url_raw( (string) $raw_context['src'] );
		}

		if ( isset( $raw_context['href'] ) && is_scalar( $raw_context['href'] ) ) {
			$context['href'] = esc_url_raw( (string) $raw_context['href'] );
		}

		if ( isset( $raw_context['tagName'] ) && is_scalar( $raw_context['tagName'] ) ) {
			$context['tagName'] = sanitize_key( strtolower( (string) $raw_context['tagName'] ) );
		}

		if ( isset( $raw_context['title'] ) && is_scalar( $raw_context['title'] ) ) {
			$context['title'] = self::limit_string( sanitize_text_field( (string) $raw_context['title'] ), 300 );
		}

		if ( isset( $raw_context['direct'] ) && is_array( $raw_context['direct'] ) ) {
			$direct = $raw_context['direct'];

			$context['direct'] = array(
				'acf_key'  => isset( $direct['acfKey'] ) && is_scalar( $direct['acfKey'] ) ? sanitize_key( (string) $direct['acfKey'] ) : '',
				'acf_name' => isset( $direct['acfName'] ) && is_scalar( $direct['acfName'] ) ? sanitize_key( (string) $direct['acfName'] ) : '',
				'core'     => isset( $direct['core'] ) && is_scalar( $direct['core'] ) ? sanitize_key( (string) $direct['core'] ) : '',
				'label'    => isset( $direct['label'] ) && is_scalar( $direct['label'] ) ? sanitize_text_field( (string) $direct['label'] ) : '',
				'post_id'  => isset( $direct['postId'] ) && is_scalar( $direct['postId'] ) ? absint( $direct['postId'] ) : 0,
			);
		}

		return $context;
	}

	/**
	 * Resolve editable targets for the selected element context.
	 *
	 * @param WP_Post              $post    Post object.
	 * @param array<string, mixed> $context Sanitized context.
	 * @return array<int, array<string, mixed>>
	 */
	private static function resolve_targets( WP_Post $post, array $context ) {
		$targets = array();

		self::add_direct_target( $targets, $post, $context );
		self::add_core_targets( $targets, $post, $context );
		self::add_featured_image_target( $targets, $post, $context );
		self::add_acf_targets( $targets, $post, $context );

		$targets = self::dedupe_targets( $targets );
		usort(
			$targets,
			static function ( $a, $b ) {
				$a_score = isset( $a['score'] ) ? (int) $a['score'] : 0;
				$b_score = isset( $b['score'] ) ? (int) $b['score'] : 0;

				return $b_score <=> $a_score;
			}
		);

		return $targets;
	}

	/**
	 * Add direct targets supplied by data attributes.
	 *
	 * @param array<int, array<string, mixed>> $targets Target list.
	 * @param WP_Post                         $post    Post object.
	 * @param array<string, mixed>            $context Context.
	 * @return void
	 */
	private static function add_direct_target( array &$targets, WP_Post $post, array $context ) {
		if ( empty( $context['direct'] ) || ! is_array( $context['direct'] ) ) {
			return;
		}

		$direct = $context['direct'];
		$core   = isset( $direct['core'] ) ? (string) $direct['core'] : '';
		if ( in_array( $core, array( 'title', 'content', 'excerpt', 'thumbnail' ), true ) ) {
			$targets[] = self::build_core_target( $post->ID, $core, 120, self::direct_label( $direct, self::core_label( $core ) ), self::context_preview( $context ) );
			return;
		}

		$acf_key  = isset( $direct['acf_key'] ) ? (string) $direct['acf_key'] : '';
		$acf_name = isset( $direct['acf_name'] ) ? (string) $direct['acf_name'] : '';
		if ( '' === $acf_key && '' === $acf_name ) {
			return;
		}

		$targets[] = self::build_acf_target(
			$post->ID,
			array(
				'key'   => $acf_key,
				'label' => self::direct_label( $direct, __( 'ACF field', 'mrn-contextual-content-editor' ) ),
				'name'  => $acf_name,
				'type'  => '',
			),
			120,
			__( 'Marked field', 'mrn-contextual-content-editor' ),
			self::context_preview( $context ),
			array()
		);
	}

	/**
	 * Resolve post title/content/excerpt matches.
	 *
	 * @param array<int, array<string, mixed>> $targets Target list.
	 * @param WP_Post                         $post    Post object.
	 * @param array<string, mixed>            $context Context.
	 * @return void
	 */
	private static function add_core_targets( array &$targets, WP_Post $post, array $context ) {
		$needle = self::context_text( $context );

		$core_fields = array(
			'title'   => array(
				'label' => __( 'Post title', 'mrn-contextual-content-editor' ),
				'value' => $post->post_title,
				'boost' => 8,
			),
			'excerpt' => array(
				'label' => __( 'Post excerpt', 'mrn-contextual-content-editor' ),
				'value' => $post->post_excerpt,
				'boost' => 3,
			),
			'content' => array(
				'label' => __( 'Classic editor content', 'mrn-contextual-content-editor' ),
				'value' => $post->post_content,
				'boost' => 0,
			),
		);

		foreach ( $core_fields as $core => $field ) {
			$score = self::score_text_match( $needle, (string) $field['value'] );
			if ( $score <= 0 ) {
				continue;
			}

			$targets[] = self::build_core_target(
				$post->ID,
				$core,
				min( 99, $score + (int) $field['boost'] ),
				(string) $field['label'],
				self::preview_scalar_text( (string) $field['value'] )
			);
		}
	}

	/**
	 * Resolve featured image matches.
	 *
	 * @param array<int, array<string, mixed>> $targets Target list.
	 * @param WP_Post                         $post    Post object.
	 * @param array<string, mixed>            $context Context.
	 * @return void
	 */
	private static function add_featured_image_target( array &$targets, WP_Post $post, array $context ) {
		$src = isset( $context['src'] ) ? (string) $context['src'] : '';
		if ( '' === $src ) {
			return;
		}

		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( ! $thumbnail_id ) {
			return;
		}

		$image_urls = array_filter(
			array(
				wp_get_attachment_image_url( $thumbnail_id, 'full' ),
				wp_get_attachment_image_url( $thumbnail_id, 'large' ),
				wp_get_attachment_image_url( $thumbnail_id, 'medium_large' ),
				wp_get_attachment_image_url( $thumbnail_id, 'medium' ),
				wp_get_attachment_url( $thumbnail_id ),
			)
		);

		foreach ( $image_urls as $image_url ) {
			if ( self::urls_match( $src, (string) $image_url ) ) {
				$targets[] = self::build_core_target( $post->ID, 'thumbnail', 94, __( 'Featured image', 'mrn-contextual-content-editor' ), self::attachment_preview( $thumbnail_id ) );
				return;
			}
		}

		$src_attachment_id = attachment_url_to_postid( $src );
		if ( $src_attachment_id > 0 && (int) $thumbnail_id === (int) $src_attachment_id ) {
			$targets[] = self::build_core_target( $post->ID, 'thumbnail', 94, __( 'Featured image', 'mrn-contextual-content-editor' ), self::attachment_preview( $thumbnail_id ) );
		}
	}

	/**
	 * Resolve ACF field matches.
	 *
	 * @param array<int, array<string, mixed>> $targets Target list.
	 * @param WP_Post                         $post    Post object.
	 * @param array<string, mixed>            $context Context.
	 * @return void
	 */
	private static function add_acf_targets( array &$targets, WP_Post $post, array $context ) {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return;
		}

		$fields = get_field_objects( $post->ID );
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return;
		}

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			self::add_acf_field_targets( $targets, $post->ID, $field, isset( $field['value'] ) ? $field['value'] : null, $context, array(), array(), 0 );
		}
	}

	/**
	 * Recursively resolve one ACF field and its subfields.
	 *
	 * @param array<int, array<string, mixed>> $targets Target list.
	 * @param int                             $post_id Post ID.
	 * @param array<string, mixed>            $field   Field object.
	 * @param mixed                           $value   Field value.
	 * @param array<string, mixed>            $context Context.
	 * @param array<int, string>              $path    Field label path.
	 * @param array<int, array<string, mixed>> $focus_path Admin focus path.
	 * @param int                             $depth   Recursion depth.
	 * @return void
	 */
	private static function add_acf_field_targets( array &$targets, $post_id, array $field, $value, array $context, array $path, array $focus_path, $depth ) {
		if ( $depth > 5 || count( $targets ) > 80 ) {
			return;
		}

		$field_label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
		if ( '' === $field_label && isset( $field['name'] ) && is_scalar( $field['name'] ) ) {
			$field_label = self::humanize_key( (string) $field['name'] );
		}

		$current_path = $path;
		if ( '' !== $field_label ) {
			$current_path[] = $field_label;
		}

		$current_focus_path = array_merge( $focus_path, array( self::acf_focus_field_entry( $field ) ) );
		$score = self::score_value_match( $value, $context );
		$type  = isset( $field['type'] ) && is_scalar( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';
		if ( $score > 0 && ! in_array( $type, array( 'group', 'repeater', 'flexible_content' ), true ) ) {
			if ( $depth > 0 ) {
				$score += 6;
			}

			$score += 5;

			$targets[] = self::build_acf_target(
				$post_id,
				$field,
				min( 116, $score ),
				implode( ' > ', array_filter( $current_path ) ),
				self::preview_value( $value, $context, $field ),
				$current_focus_path
			);
		}

		self::walk_acf_subfields( $targets, $post_id, $field, $value, $context, $current_path, $current_focus_path, $depth );
	}

	/**
	 * Walk subfields for groups, repeaters, and flexible content values.
	 *
	 * @param array<int, array<string, mixed>> $targets Target list.
	 * @param int                             $post_id Post ID.
	 * @param array<string, mixed>            $field   Field object.
	 * @param mixed                           $value   Field value.
	 * @param array<string, mixed>            $context Context.
	 * @param array<int, string>              $path    Field label path.
	 * @param array<int, array<string, mixed>> $focus_path Admin focus path.
	 * @param int                             $depth   Recursion depth.
	 * @return void
	 */
	private static function walk_acf_subfields( array &$targets, $post_id, array $field, $value, array $context, array $path, array $focus_path, $depth ) {
		$type = isset( $field['type'] ) && is_scalar( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';

		if ( 'group' === $type && ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) && is_array( $value ) ) {
			foreach ( $field['sub_fields'] as $subfield ) {
				if ( ! is_array( $subfield ) ) {
					continue;
				}

				$name      = isset( $subfield['name'] ) && is_scalar( $subfield['name'] ) ? (string) $subfield['name'] : '';
				$subvalue  = '' !== $name && array_key_exists( $name, $value ) ? $value[ $name ] : null;
				self::add_acf_field_targets( $targets, $post_id, $subfield, $subvalue, $context, $path, $focus_path, $depth + 1 );
			}

			return;
		}

		if ( 'repeater' === $type && ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) && is_array( $value ) ) {
			foreach ( $value as $row_index => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				foreach ( $field['sub_fields'] as $subfield ) {
					if ( ! is_array( $subfield ) ) {
						continue;
					}

					$name      = isset( $subfield['name'] ) && is_scalar( $subfield['name'] ) ? (string) $subfield['name'] : '';
					$subvalue  = '' !== $name && array_key_exists( $name, $row ) ? $row[ $name ] : null;
					$row_label = self::acf_repeater_row_label( $row, $row_index );
					self::add_acf_field_targets(
						$targets,
						$post_id,
						$subfield,
						$subvalue,
						$context,
						array_merge( $path, array( $row_label ) ),
						array_merge( $focus_path, array( self::acf_focus_row_entry( 'repeater', $row_index ) ) ),
						$depth + 1
					);
				}
			}

			return;
		}

		if ( 'flexible_content' === $type && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) && is_array( $value ) ) {
			$layouts = self::index_acf_layouts( $field['layouts'] );
			foreach ( $value as $row_index => $row ) {
				if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) || ! is_scalar( $row['acf_fc_layout'] ) ) {
					continue;
				}

				$layout_name = (string) $row['acf_fc_layout'];
				if ( empty( $layouts[ $layout_name ]['sub_fields'] ) || ! is_array( $layouts[ $layout_name ]['sub_fields'] ) ) {
					continue;
				}

				$layout_label = isset( $layouts[ $layout_name ]['label'] ) && is_scalar( $layouts[ $layout_name ]['label'] )
					? sanitize_text_field( (string) $layouts[ $layout_name ]['label'] )
					: self::humanize_key( $layout_name );

				$row_label = self::acf_flexible_row_label( $row, $layout_label, $row_index );

				foreach ( $layouts[ $layout_name ]['sub_fields'] as $subfield ) {
					if ( ! is_array( $subfield ) ) {
						continue;
					}

					$name     = isset( $subfield['name'] ) && is_scalar( $subfield['name'] ) ? (string) $subfield['name'] : '';
					$subvalue = '' !== $name && array_key_exists( $name, $row ) ? $row[ $name ] : null;
					self::add_acf_field_targets(
						$targets,
						$post_id,
						$subfield,
						$subvalue,
						$context,
						array_merge( $path, array( $row_label ) ),
						array_merge( $focus_path, array( self::acf_focus_row_entry( 'flexible_content', $row_index, $layout_name ) ) ),
						$depth + 1
					);
				}
			}
		}
	}

	/**
	 * Index flexible-content layouts by machine name.
	 *
	 * @param array<int, array<string, mixed>> $layouts Layout definitions.
	 * @return array<string, array<string, mixed>>
	 */
	private static function index_acf_layouts( array $layouts ) {
		$indexed = array();

		foreach ( $layouts as $layout ) {
			if ( ! is_array( $layout ) || empty( $layout['name'] ) || ! is_scalar( $layout['name'] ) ) {
				continue;
			}

			$indexed[ (string) $layout['name'] ] = $layout;
		}

		return $indexed;
	}

	/**
	 * Build an admin-focus field path entry.
	 *
	 * @param array<string, mixed> $field ACF field definition.
	 * @return array<string, string>
	 */
	private static function acf_focus_field_entry( array $field ) {
		return array(
			'key'  => isset( $field['key'] ) && is_scalar( $field['key'] ) ? sanitize_key( (string) $field['key'] ) : '',
			'name' => isset( $field['name'] ) && is_scalar( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '',
			'type' => 'field',
		);
	}

	/**
	 * Build an admin-focus row path entry.
	 *
	 * @param string $kind   Row owner kind.
	 * @param mixed  $index  Zero-based row index.
	 * @param string $layout Flexible layout name.
	 * @return array<string, mixed>
	 */
	private static function acf_focus_row_entry( $kind, $index, $layout = '' ) {
		return array(
			'index'  => max( 0, absint( $index ) ),
			'kind'   => sanitize_key( $kind ),
			'layout' => sanitize_key( $layout ),
			'type'   => 'row',
		);
	}

	/**
	 * Build a readable flexible-content row label.
	 *
	 * @param array<string, mixed> $row          Row value.
	 * @param string               $layout_label Layout label.
	 * @param mixed                $row_index    Zero-based row index.
	 * @return string
	 */
	private static function acf_flexible_row_label( array $row, $layout_label, $row_index ) {
		$layout_label = self::clean_acf_layout_label( $layout_label );
		$row_number   = absint( $row_index ) + 1;
		$row_hint     = self::array_string_value( $row, array( 'internal_name', 'label', 'heading', 'title', 'subheading' ) );

		if ( '' !== $row_hint ) {
			return sprintf(
				/* translators: 1: layout label. 2: row number. 3: row hint. */
				__( '%1$s %2$d: %3$s', 'mrn-contextual-content-editor' ),
				$layout_label,
				$row_number,
				self::preview_scalar_text( $row_hint )
			);
		}

		return sprintf(
			/* translators: 1: layout label. 2: row number. */
			__( '%1$s %2$d', 'mrn-contextual-content-editor' ),
			$layout_label,
			$row_number
		);
	}

	/**
	 * Build a readable repeater row label.
	 *
	 * @param array<string, mixed> $row       Row value.
	 * @param mixed                $row_index Zero-based row index.
	 * @return string
	 */
	private static function acf_repeater_row_label( array $row, $row_index ) {
		$row_number = absint( $row_index ) + 1;
		$row_hint   = self::preview_link_value( $row );
		if ( '' === $row_hint ) {
			$row_hint = self::array_string_value( $row, array( 'label', 'heading', 'title', 'text', 'name' ) );
		}

		if ( '' !== $row_hint ) {
			return sprintf(
				/* translators: 1: row number. 2: row hint. */
				__( 'Row %1$d: %2$s', 'mrn-contextual-content-editor' ),
				$row_number,
				self::preview_scalar_text( $row_hint )
			);
		}

		return sprintf(
			/* translators: %d: ACF row number. */
			__( 'Row %d', 'mrn-contextual-content-editor' ),
			$row_number
		);
	}

	/**
	 * Remove MRN schema suffixes from layout labels.
	 *
	 * @param string $label Layout label.
	 * @return string
	 */
	private static function clean_acf_layout_label( $label ) {
		$label = self::preview_scalar_text( $label );
		$label = preg_replace( '/\s+-\s+.+$/', '', $label );

		return is_string( $label ) && '' !== $label ? $label : __( 'Layout', 'mrn-contextual-content-editor' );
	}

	/**
	 * Decode and sanitize an ACF focus path from the editor URL.
	 *
	 * @param mixed $raw_path Raw JSON path.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_acf_focus_path_json( $raw_path ) {
		if ( ! is_scalar( $raw_path ) ) {
			return array();
		}

		$decoded = json_decode( (string) $raw_path, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$path = array();
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['type'] ) || ! is_scalar( $entry['type'] ) ) {
				continue;
			}

			$type = sanitize_key( (string) $entry['type'] );
			if ( 'field' === $type ) {
				$path[] = array(
					'key'  => isset( $entry['key'] ) && is_scalar( $entry['key'] ) ? sanitize_key( (string) $entry['key'] ) : '',
					'name' => isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? sanitize_key( (string) $entry['name'] ) : '',
					'type' => 'field',
				);
				continue;
			}

			if ( 'row' === $type ) {
				$path[] = array(
					'index'  => isset( $entry['index'] ) ? max( 0, absint( $entry['index'] ) ) : 0,
					'kind'   => isset( $entry['kind'] ) && is_scalar( $entry['kind'] ) ? sanitize_key( (string) $entry['kind'] ) : '',
					'layout' => isset( $entry['layout'] ) && is_scalar( $entry['layout'] ) ? sanitize_key( (string) $entry['layout'] ) : '',
					'type'   => 'row',
				);
			}
		}

		return array_slice( $path, 0, 12 );
	}

	/**
	 * Build a short preview from the current front-end selection context.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return string
	 */
	private static function context_preview( array $context ) {
		foreach ( array( 'text', 'alt', 'title', 'href', 'src' ) as $key ) {
			if ( ! empty( $context[ $key ] ) && is_string( $context[ $key ] ) ) {
				return self::preview_scalar_text( $context[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Build a preview for a mixed field value.
	 *
	 * @param mixed                $value   Field value.
	 * @param array<string, mixed> $context Selection context.
	 * @param array<string, mixed> $field   ACF field definition.
	 * @return string
	 */
	private static function preview_value( $value, array $context, array $field ) {
		$type = isset( $field['type'] ) && is_scalar( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';

		if ( in_array( $type, array( 'image', 'file' ), true ) ) {
			$image_preview = self::preview_media_value( $value );
			if ( '' !== $image_preview ) {
				return $image_preview;
			}
		}

		if ( 'link' === $type ) {
			$link_preview = self::preview_link_value( $value );
			if ( '' !== $link_preview ) {
				return $link_preview;
			}
		}

		if ( is_scalar( $value ) ) {
			return self::preview_scalar_text( (string) $value );
		}

		if ( is_array( $value ) ) {
			$link_preview = self::preview_link_value( $value );
			if ( '' !== $link_preview ) {
				return $link_preview;
			}

			$image_preview = self::preview_media_value( $value );
			if ( '' !== $image_preview ) {
				return $image_preview;
			}
		}

		return self::context_preview( $context );
	}

	/**
	 * Build a link preview from an ACF link-like value.
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	private static function preview_link_value( $value ) {
		if ( ! is_array( $value ) ) {
			return '';
		}

		$url   = self::array_string_value( $value, array( 'url', 'href', 'link' ) );
		$title = self::array_string_value( $value, array( 'title', 'label', 'text', 'name', 'value' ) );

		if ( '' === $url && isset( $value['link'] ) && is_array( $value['link'] ) ) {
			$url = self::array_string_value( $value['link'], array( 'url', 'href' ) );
			if ( '' === $title ) {
				$title = self::array_string_value( $value['link'], array( 'title', 'label', 'text' ) );
			}
		}

		if ( '' === $url && '' === $title ) {
			return '';
		}

		if ( '' !== $url && '' !== $title ) {
			return self::preview_scalar_text( $title ) . ' -> ' . self::preview_scalar_text( $url );
		}

		return self::preview_scalar_text( '' !== $title ? $title : $url );
	}

	/**
	 * Build a media preview from an attachment value.
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	private static function preview_media_value( $value ) {
		if ( is_numeric( $value ) ) {
			return self::attachment_preview( absint( $value ) );
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$id = 0;
		if ( isset( $value['ID'] ) ) {
			$id = absint( $value['ID'] );
		} elseif ( isset( $value['id'] ) ) {
			$id = absint( $value['id'] );
		}

		if ( $id > 0 ) {
			return self::attachment_preview( $id );
		}

		$text = self::array_string_value( $value, array( 'alt', 'title', 'caption', 'filename', 'name' ) );
		$url  = self::array_string_value( $value, array( 'url', 'src' ) );
		if ( '' !== $text && '' !== $url ) {
			return self::preview_scalar_text( $text ) . ' -> ' . self::preview_scalar_text( $url );
		}

		return self::preview_scalar_text( '' !== $text ? $text : $url );
	}

	/**
	 * Build a preview for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function attachment_preview( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$alt   = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$title = get_the_title( $attachment_id );
		$url   = wp_get_attachment_url( $attachment_id );
		$text  = is_scalar( $alt ) && '' !== trim( (string) $alt ) ? (string) $alt : (string) $title;

		if ( '' !== $text && $url ) {
			return self::preview_scalar_text( $text ) . ' -> ' . self::preview_scalar_text( (string) $url );
		}

		return self::preview_scalar_text( '' !== $text ? $text : (string) $url );
	}

	/**
	 * Read the first non-empty string value from an array by key.
	 *
	 * @param array<string, mixed> $value Source array.
	 * @param array<int, string>   $keys  Candidate keys.
	 * @return string
	 */
	private static function array_string_value( array $value, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) && '' !== trim( (string) $value[ $key ] ) ) {
				return (string) $value[ $key ];
			}
		}

		return '';
	}

	/**
	 * Shorten and clean a scalar preview value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function preview_scalar_text( $value ) {
		$value = self::normalize_preview_text( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value ) > 110 ? mb_substr( $value, 0, 107 ) . '...' : $value;
		}

		return strlen( $value ) > 110 ? substr( $value, 0, 107 ) . '...' : $value;
	}

	/**
	 * Build a core editor target.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $core    Core field key.
	 * @param int    $score   Match score.
	 * @param string $label   Display label.
	 * @param string $preview Content preview.
	 * @return array<string, mixed>
	 */
	private static function build_core_target( $post_id, $core, $score, $label, $preview = '' ) {
		$core = sanitize_key( $core );

		return array(
			'detail'  => __( 'Classic Editor', 'mrn-contextual-content-editor' ),
			'editUrl' => add_query_arg(
				array(
					'mrn_cce_focus_core'  => $core,
					'mrn_cce_focus_label' => $label,
				),
				self::get_post_edit_url( $post_id )
			),
			'id'      => 'core:' . $core,
			'kind'    => 'core',
			'label'   => $label,
			'preview' => '' !== $preview ? $preview : $label,
			'score'   => (int) $score,
		);
	}

	/**
	 * Build an ACF editor target.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $field   Field definition.
	 * @param int                  $score   Match score.
	 * @param string               $detail  Display detail.
	 * @param string               $preview Content preview.
	 * @param array<int, array<string, mixed>> $focus_path Admin focus path.
	 * @return array<string, mixed>
	 */
	private static function build_acf_target( $post_id, array $field, $score, $detail, $preview = '', array $focus_path = array() ) {
		$key   = isset( $field['key'] ) && is_scalar( $field['key'] ) ? sanitize_key( (string) $field['key'] ) : '';
		$name  = isset( $field['name'] ) && is_scalar( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';
		$label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
		$type  = isset( $field['type'] ) && is_scalar( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';

		if ( '' === $label ) {
			$label = '' !== $name ? self::humanize_key( $name ) : __( 'ACF field', 'mrn-contextual-content-editor' );
		}

		$query_args = array();
		if ( '' !== $key ) {
			$query_args['mrn_cce_focus_acf'] = $key;
		}

		if ( '' !== $name ) {
			$query_args['mrn_cce_focus_name'] = $name;
		}

		$query_args['mrn_cce_focus_label'] = $label;
		if ( ! empty( $focus_path ) ) {
			$focus_path_json = wp_json_encode( $focus_path );
			if ( is_string( $focus_path_json ) ) {
				$query_args['mrn_cce_focus_path'] = $focus_path_json;
			}
		}

		$path_hash = '';
		if ( ! empty( $focus_path ) ) {
			$encoded_path = wp_json_encode( $focus_path );
			$path_hash    = is_string( $encoded_path ) ? ':' . substr( hash( 'sha256', $encoded_path ), 0, 12 ) : '';
		}

		return array(
			'detail'    => '' !== $detail ? $detail : __( 'ACF field', 'mrn-contextual-content-editor' ),
			'editUrl'   => add_query_arg( $query_args, self::get_post_edit_url( $post_id ) ),
			'fieldKey'  => $key,
			'fieldName' => $name,
			'fieldType' => $type,
			'fieldPath' => $focus_path,
			'id'        => 'acf:' . $key . ':' . $name . $path_hash,
			'kind'      => 'acf',
			'label'     => sprintf(
				/* translators: %s: ACF field label. */
				__( 'ACF: %s', 'mrn-contextual-content-editor' ),
				$label
			),
			'preview'   => '' !== $preview ? $preview : $label,
			'score'     => (int) $score,
		);
	}

	/**
	 * Deduplicate targets by stable ID.
	 *
	 * @param array<int, array<string, mixed>> $targets Target list.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe_targets( array $targets ) {
		$deduped = array();
		foreach ( $targets as $target ) {
			if ( empty( $target['id'] ) || ! is_string( $target['id'] ) ) {
				continue;
			}

			$id = $target['id'];
			if ( ! isset( $deduped[ $id ] ) || (int) $target['score'] > (int) $deduped[ $id ]['score'] ) {
				$deduped[ $id ] = $target;
			}
		}

		return array_values( $deduped );
	}

	/**
	 * Get current selected text from context.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return string
	 */
	private static function context_text( array $context ) {
		if ( ! empty( $context['text'] ) && is_string( $context['text'] ) ) {
			return $context['text'];
		}

		if ( ! empty( $context['html'] ) && is_string( $context['html'] ) ) {
			return wp_strip_all_tags( $context['html'] );
		}

		return '';
	}

	/**
	 * Score a value against the selected context.
	 *
	 * @param mixed                $value   Candidate field value.
	 * @param array<string, mixed> $context Context.
	 * @return int
	 */
	private static function score_value_match( $value, array $context ) {
		$signals = self::extract_value_signals( $value );
		$score   = 0;
		$needle  = self::context_text( $context );

		foreach ( $signals['texts'] as $text ) {
			$score = max( $score, self::score_text_match( $needle, $text ) );
		}

		$src = isset( $context['src'] ) ? (string) $context['src'] : '';
		if ( '' !== $src ) {
			foreach ( $signals['urls'] as $url ) {
				if ( self::urls_match( $src, $url ) ) {
					$score = max( $score, 96 );
				}
			}

			$attachment_id = attachment_url_to_postid( $src );
			if ( $attachment_id > 0 && in_array( $attachment_id, $signals['ids'], true ) ) {
				$score = max( $score, 96 );
			}
		}

		$href = isset( $context['href'] ) ? (string) $context['href'] : '';
		if ( '' !== $href ) {
			foreach ( $signals['urls'] as $url ) {
				if ( self::urls_match( $href, $url ) ) {
					$score = max( $score, 92 );
				}
			}
		}

		return $score;
	}

	/**
	 * Extract text, URL, and ID signals from a mixed ACF value.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current recursion depth.
	 * @return array{texts: array<int, string>, urls: array<int, string>, ids: array<int, int>}
	 */
	private static function extract_value_signals( $value, $depth = 0 ) {
		$signals = array(
			'ids'   => array(),
			'texts' => array(),
			'urls'  => array(),
		);

		if ( $depth > 5 || null === $value || false === $value ) {
			return $signals;
		}

		if ( is_scalar( $value ) ) {
			$string = trim( (string) $value );
			if ( '' === $string ) {
				return $signals;
			}

			if ( is_numeric( $value ) ) {
				$signals['ids'][] = absint( $value );
			}

			if ( filter_var( $string, FILTER_VALIDATE_URL ) ) {
				$signals['urls'][] = esc_url_raw( $string );
			}

			$signals['texts'][] = $string;
			return $signals;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof WP_Post ) {
				$signals['ids'][]   = absint( $value->ID );
				$signals['texts'][] = $value->post_title;
			} elseif ( $value instanceof WP_Term ) {
				$signals['ids'][]   = absint( $value->term_id );
				$signals['texts'][] = $value->name;
			} elseif ( $value instanceof WP_User ) {
				$signals['ids'][]   = absint( $value->ID );
				$signals['texts'][] = $value->display_name;
			}

			return $signals;
		}

		if ( ! is_array( $value ) ) {
			return $signals;
		}

		foreach ( $value as $key => $item ) {
			$key_string = is_scalar( $key ) ? strtolower( (string) $key ) : '';

			if ( is_scalar( $item ) ) {
				$item_string = trim( (string) $item );
				if ( '' === $item_string ) {
					continue;
				}

				if ( 'id' === $key_string || ( is_numeric( $item ) && in_array( $key_string, array( 'image', 'attachment', 'file' ), true ) ) ) {
					$signals['ids'][] = absint( $item );
				}

				if ( in_array( $key_string, array( 'url', 'href', 'link', 'src' ), true ) || filter_var( $item_string, FILTER_VALIDATE_URL ) ) {
					$signals['urls'][] = esc_url_raw( $item_string );
				}

				if ( in_array( $key_string, array( 'title', 'alt', 'caption', 'description', 'label', 'name', 'text', 'value' ), true ) || ! filter_var( $item_string, FILTER_VALIDATE_URL ) ) {
					$signals['texts'][] = $item_string;
				}

				continue;
			}

			$child_signals = self::extract_value_signals( $item, $depth + 1 );
			$signals       = self::merge_signals( $signals, $child_signals );
		}

		$signals['ids']   = array_values( array_unique( array_filter( array_map( 'absint', $signals['ids'] ) ) ) );
		$signals['texts'] = array_values( array_unique( array_filter( $signals['texts'] ) ) );
		$signals['urls']  = array_values( array_unique( array_filter( $signals['urls'] ) ) );

		return $signals;
	}

	/**
	 * Merge signal arrays.
	 *
	 * @param array<string, array<int, mixed>> $a Signals.
	 * @param array<string, array<int, mixed>> $b Signals.
	 * @return array<string, array<int, mixed>>
	 */
	private static function merge_signals( array $a, array $b ) {
		foreach ( array( 'ids', 'texts', 'urls' ) as $key ) {
			if ( ! empty( $b[ $key ] ) && is_array( $b[ $key ] ) ) {
				$a[ $key ] = array_merge( $a[ $key ], $b[ $key ] );
			}
		}

		return $a;
	}

	/**
	 * Score a text match.
	 *
	 * @param string $needle  Selected text.
	 * @param string $haystack Candidate text.
	 * @return int
	 */
	private static function score_text_match( $needle, $haystack ) {
		$needle_normalized   = self::normalize_text( $needle );
		$haystack_normalized = self::normalize_text( $haystack );

		if ( strlen( $needle_normalized ) < 3 || strlen( $haystack_normalized ) < 3 ) {
			return 0;
		}

		if ( $needle_normalized === $haystack_normalized ) {
			return 95;
		}

		if ( false !== strpos( $haystack_normalized, $needle_normalized ) ) {
			return min( 88, 45 + (int) floor( strlen( $needle_normalized ) / 8 ) );
		}

		if ( strlen( $haystack_normalized ) >= 5 && false !== strpos( $needle_normalized, $haystack_normalized ) ) {
			return min( 82, 38 + (int) floor( strlen( $haystack_normalized ) / 8 ) );
		}

		return 0;
	}

	/**
	 * Normalize text for matching.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		$text = is_string( $text ) ? $text : '';

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * Normalize text for display previews.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_preview_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		return is_string( $text ) ? $text : '';
	}

	/**
	 * Compare URLs while ignoring query strings and schemes.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	private static function urls_match( $a, $b ) {
		$a_parts = wp_parse_url( $a );
		$b_parts = wp_parse_url( $b );

		if ( empty( $a_parts['path'] ) || empty( $b_parts['path'] ) ) {
			return false;
		}

		$a_path = untrailingslashit( strtolower( rawurldecode( (string) $a_parts['path'] ) ) );
		$b_path = untrailingslashit( strtolower( rawurldecode( (string) $b_parts['path'] ) ) );

		return '' !== $a_path && $a_path === $b_path;
	}

	/**
	 * Build post edit URL.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_post_edit_url( $post_id ) {
		$url = get_edit_post_link( absint( $post_id ), 'raw' );
		if ( ! $url ) {
			$url = add_query_arg(
				array(
					'action' => 'edit',
					'post'   => absint( $post_id ),
				),
				admin_url( 'post.php' )
			);
		}

		return (string) $url;
	}

	/**
	 * Label for a direct target.
	 *
	 * @param array<string, mixed> $direct   Direct metadata.
	 * @param string               $fallback Fallback label.
	 * @return string
	 */
	private static function direct_label( array $direct, $fallback ) {
		return ! empty( $direct['label'] ) && is_string( $direct['label'] ) ? $direct['label'] : $fallback;
	}

	/**
	 * Human label for core fields.
	 *
	 * @param string $core Core key.
	 * @return string
	 */
	private static function core_label( $core ) {
		$labels = array(
			'content'   => __( 'Classic editor content', 'mrn-contextual-content-editor' ),
			'excerpt'   => __( 'Post excerpt', 'mrn-contextual-content-editor' ),
			'thumbnail' => __( 'Featured image', 'mrn-contextual-content-editor' ),
			'title'     => __( 'Post title', 'mrn-contextual-content-editor' ),
		);

		return isset( $labels[ $core ] ) ? $labels[ $core ] : __( 'Content field', 'mrn-contextual-content-editor' );
	}

	/**
	 * Convert a machine key to a readable label.
	 *
	 * @param string $key Machine key.
	 * @return string
	 */
	private static function humanize_key( $key ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', sanitize_key( $key ) ) );
	}

	/**
	 * Limit a string to a safe length.
	 *
	 * @param string $value String value.
	 * @param int    $limit Maximum length.
	 * @return string
	 */
	private static function limit_string( $value, $limit ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit );
		}

		return substr( $value, 0, $limit );
	}
}
