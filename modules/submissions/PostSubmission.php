<?php
namespace NeuralForms\Submissions;

use NeuralForms\Core\Capabilities;
use NeuralForms\Notifications\NotificationService;
use NeuralForms\Storage\EntryActivity;

defined( 'ABSPATH' ) || exit;

/**
 * Create a post (any public post type) from a form (1.3.0, `frontend_posts` cap).
 *
 * settings.create_post = { enabled, post_type, status draft|pending|publish,
 *   title, content, excerpt (templates with {field_id} tokens),
 *   featured_image (file field id), author (user id for visitors who are
 *   not logged in), terms[{taxonomy, field}], meta[{key, field}] }
 *
 * Safety rules:
 * - Visitor text is inserted ESCAPED into the admin's template, so a
 *   visitor can never add HTML or shortcodes; the template itself goes
 *   through wp_kses_post().
 * - Terms are only ever matched to EXISTING terms (no term spam).
 * - Custom fields use Mapping::safe_meta_key() (no protected "_" keys).
 * - Runs only for submissions that are not spam, after the entry exists.
 */
final class PostSubmission {

	public const STATUSES  = array( 'draft', 'pending', 'publish' );
	public const MAX_TERMS = 10;

	/** Post types a form may create: slug => label. */
	public static function post_types(): array {
		$out = array();
		if ( ! function_exists( 'get_post_types' ) ) {
			return $out;
		}
		foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $slug => $pt ) {
			if ( $slug === 'attachment' ) {
				continue;
			}
			$out[ (string) $slug ] = array(
				'label'      => (string) $pt->labels->singular_name,
				'taxonomies' => array_values(
					array_map(
						static function ( $t ) {
							return array( 'slug' => $t->name, 'label' => (string) $t->labels->singular_name );
						},
						array_filter(
							get_object_taxonomies( (string) $slug, 'objects' ),
							static function ( $t ) {
								return ! empty( $t->show_ui ) && $t->name !== 'post_format';
							}
						)
					)
				),
			);
		}
		return $out;
	}

	public static function sanitize_settings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$status = (string) ( $raw['status'] ?? 'pending' );
		$terms  = array();
		foreach ( is_array( $raw['terms'] ?? null ) ? $raw['terms'] : array() as $t ) {
			$tax   = is_array( $t ) ? sanitize_key( (string) ( $t['taxonomy'] ?? '' ) ) : '';
			$field = is_array( $t ) ? Mapping::field_id( $t['field'] ?? '' ) : '';
			if ( $tax !== '' && $field !== '' && count( $terms ) < self::MAX_TERMS ) {
				$terms[] = array( 'taxonomy' => $tax, 'field' => $field );
			}
		}
		$text = static function ( $key, $max ) use ( $raw ) {
			return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? mb_substr( (string) $raw[ $key ], 0, $max ) : '';
		};
		$out = array(
			'enabled'        => ! empty( $raw['enabled'] ),
			'post_type'      => sanitize_key( (string) ( $raw['post_type'] ?? 'post' ) ) ?: 'post',
			'status'         => in_array( $status, self::STATUSES, true ) ? $status : 'pending',
			'title'          => sanitize_text_field( $text( 'title', 300 ) ),
			'content'        => wp_kses_post( $text( 'content', 20000 ) ),
			'excerpt'        => sanitize_textarea_field( $text( 'excerpt', 1000 ) ),
			'featured_image' => Mapping::field_id( $raw['featured_image'] ?? '' ),
			'author'         => max( 0, (int) ( $raw['author'] ?? 0 ) ),
			'terms'          => $terms,
			'meta'           => Mapping::sanitize_meta_list( $raw['meta'] ?? array() ),
		);
		return ( ! $out['enabled'] && $out['title'] === '' && $out['content'] === '' ) ? array() : $out;
	}

	/** Form save check: 403 when locked, 422 for unusable settings. */
	public static function validate_settings( array $settings, array $form ) {
		$s = is_array( $settings['create_post'] ?? null ) ? $settings['create_post'] : array();
		if ( empty( $s['enabled'] ) ) {
			return true;
		}
		if ( ! Capabilities::can( Capabilities::FRONTEND_POSTS ) ) {
			return Capabilities::locked_error( Capabilities::FRONTEND_POSTS );
		}
		$bad = static function ( $msg ) {
			return new \WP_Error( 'invalid_form_data', $msg, array( 'status' => 422 ) );
		};
		$types = self::post_types();
		if ( ! isset( $types[ $s['post_type'] ?? '' ] ) ) {
			return $bad( __( 'Choose a post type for the new posts.', 'neural-forms-lite' ) );
		}
		if ( trim( (string) ( $s['title'] ?? '' ) ) === '' ) {
			return $bad( __( 'New posts need a title. Use field tags like {field_id} to build it.', 'neural-forms-lite' ) );
		}
		$author = (int) ( $s['author'] ?? 0 );
		if ( $author <= 0 || ! user_can( $author, 'edit_posts' ) ) {
			return $bad( __( 'Choose an author for posts sent by visitors who are not logged in.', 'neural-forms-lite' ) );
		}
		$fields = Mapping::fields_by_id( $form );
		if ( ! empty( $s['featured_image'] ) && ( $fields[ $s['featured_image'] ]['type'] ?? '' ) !== 'file' ) {
			return $bad( __( 'The featured image must come from a File Upload field.', 'neural-forms-lite' ) );
		}
		$taxes = array_column( $types[ $s['post_type'] ]['taxonomies'], 'slug' );
		foreach ( (array) ( $s['terms'] ?? array() ) as $t ) {
			if ( ! in_array( $t['taxonomy'], $taxes, true ) || ! isset( $fields[ $t['field'] ] ) ) {
				return $bad( __( 'A category or tag mapping points at a taxonomy or field that does not exist.', 'neural-forms-lite' ) );
			}
		}
		foreach ( (array) ( $s['meta'] ?? array() ) as $m ) {
			if ( ! isset( $fields[ $m['field'] ] ) ) {
				return $bad( __( 'A post custom field points at a field that is no longer on the form.', 'neural-forms-lite' ) );
			}
		}
		return true;
	}

	/**
	 * Template with every {token} replaced by its escaped plain-text value.
	 * Square brackets become entities too, so a visitor's "[gallery]" or any
	 * other shortcode shows as text and never runs (esc_html() leaves them).
	 */
	public static function render_html( string $template, array $form, array $data, int $entry_id ): string {
		return preg_replace_callback(
			'/\{([A-Za-z0-9_\-]+)\}/',
			static function ( $m ) use ( $form, $data, $entry_id ) {
				$value = NotificationService::render_tokens( $m[0], $form, $data, $entry_id );
				return $value === $m[0] ? '' : str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), esc_html( $value ) );
			},
			wp_kses_post( $template )
		);
	}

	public static function render_text( string $template, array $form, array $data, int $entry_id ): string {
		$out = preg_replace_callback(
			'/\{([A-Za-z0-9_\-]+)\}/',
			static function ( $m ) use ( $form, $data, $entry_id ) {
				$value = NotificationService::render_tokens( $m[0], $form, $data, $entry_id );
				return $value === $m[0] ? '' : $value;
			},
			$template
		);
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $out ) ) );
	}

	/**
	 * After the entry is saved: create the post. Never throws.
	 *
	 * @param int $author_id Logged-in visitor or the account just registered; 0 = use the form's author.
	 * @return int New post id, 0 when nothing was created.
	 */
	public static function create( int $entry_id, array $form, array $data, int $author_id = 0 ): int {
		$s = $form['settings']['create_post'] ?? null;
		if ( ! is_array( $s ) || empty( $s['enabled'] ) || ! Capabilities::can( Capabilities::FRONTEND_POSTS ) ) {
			return 0;
		}
		$type = (string) ( $s['post_type'] ?? 'post' );
		if ( ! post_type_exists( $type ) || ! isset( self::post_types()[ $type ] ) ) {
			EntryActivity::record( $entry_id, 'entry.post_failed', __( 'Post not created: the post type no longer exists.', 'neural-forms-lite' ), array(), 'error' );
			return 0;
		}
		$author = $author_id > 0 ? $author_id : (int) ( $s['author'] ?? 0 );
		if ( $author <= 0 || ! get_userdata( $author ) ) {
			EntryActivity::record( $entry_id, 'entry.post_failed', __( 'Post not created: the chosen author no longer exists.', 'neural-forms-lite' ), array(), 'error' );
			return 0;
		}
		$title = mb_substr( self::render_text( (string) ( $s['title'] ?? '' ), $form, $data, $entry_id ), 0, 200 );
		if ( $title === '' ) {
			$title = sprintf( /* translators: %d: entry id */ __( 'Submission #%d', 'neural-forms-lite' ), $entry_id );
		}
		$status = in_array( (string) ( $s['status'] ?? '' ), self::STATUSES, true ) ? (string) $s['status'] : 'pending';

		$post_id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => wp_kses_post( wpautop( self::render_html( (string) ( $s['content'] ?? '' ), $form, $data, $entry_id ) ) ),
				'post_excerpt' => mb_substr( self::render_text( (string) ( $s['excerpt'] ?? '' ), $form, $data, $entry_id ), 0, 1000 ),
				'post_author'  => $author,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			EntryActivity::record( $entry_id, 'entry.post_failed', __( 'Post not created: ', 'neural-forms-lite' ) . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'unknown error', 'neural-forms-lite' ) ), array(), 'error' );
			return 0;
		}
		$post_id = (int) $post_id;

		foreach ( (array) ( $s['terms'] ?? array() ) as $t ) {
			$tax = (string) ( $t['taxonomy'] ?? '' );
			if ( ! taxonomy_exists( $tax ) || ! is_object_in_taxonomy( $type, $tax ) ) {
				continue;
			}
			$ids = array();
			foreach ( Mapping::choices( $data, (string) $t['field'] ) as $name ) {
				$term = get_term_by( 'slug', sanitize_title( $name ), $tax );
				$term = $term ?: get_term_by( 'name', $name, $tax );
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $term->term_id;
				}
			}
			if ( $ids ) {
				wp_set_object_terms( $post_id, array_values( array_unique( $ids ) ), $tax, true );
			}
		}

		foreach ( (array) ( $s['meta'] ?? array() ) as $m ) {
			$key = Mapping::safe_meta_key( $m['key'] ?? '' );
			if ( $key !== '' ) {
				update_post_meta( $post_id, $key, mb_substr( sanitize_textarea_field( Mapping::text( $form, $data, (string) $m['field'] ) ), 0, 5000 ) );
			}
		}
		update_post_meta( $post_id, '_neural_forms_entry_id', $entry_id );

		$img = $data[ (string) ( $s['featured_image'] ?? '' ) ] ?? null;
		$att = is_array( $img ) ? (int) ( $img['attachment_id'] ?? 0 ) : 0;
		if ( $att > 0 && get_post_type( $att ) === 'attachment' && wp_attachment_is_image( $att ) ) {
			// Uploads are private until the owner publishes them as this post's image.
			wp_update_post( array( 'ID' => $att, 'post_parent' => $post_id, 'post_status' => 'inherit' ) );
			set_post_thumbnail( $post_id, $att );
		}

		EntryActivity::record(
			$entry_id,
			'entry.post_created',
			/* translators: 1: post type label, 2: post title */
			sprintf( __( '%1$s created: %2$s', 'neural-forms-lite' ), self::post_types()[ $type ]['label'], $title ),
			array( 'post_id' => $post_id, 'status' => $status, 'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ) )
		);

		/**
		 * Fires after a form created a post.
		 *
		 * @param int   $post_id
		 * @param int   $entry_id
		 * @param array $form
		 */
		do_action( 'neural_forms_post_created', $post_id, $entry_id, $form );
		return $post_id;
	}
}
