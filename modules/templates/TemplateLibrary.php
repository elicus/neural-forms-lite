<?php
namespace NeuralForms\Templates;

use NeuralForms\Forms\FormRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Local template library service.
 *
 * Templates ship with the plugin (no remote host fetched). The Lite
 * plugin ships its block-editor catalog under `templates/block-editor/`;
 * Pro and other extension plugins register additional roots via the
 * `neural_forms_template_library_roots` filter — e.g. Pro maps the
 * `divi` builder to its own `templates/divi/` directory the same way.
 *
 * Folder convention (per root):
 *   templates/<builder>/templates.json
 *   templates/<builder>/<category>/<slug>.json
 *   templates/<builder>/<category>/screenshots/<slug>.jpg
 *
 * Trust model
 * -----------
 * No outbound HTTP — the source of every file is the plugin's own
 * filesystem, vetted at distribution time. import_url values in
 * templates.json are RELATIVE paths only; resolve_local_path() runs
 * realpath() and rejects anything that escapes the registered root,
 * so a malformed catalog row can't read arbitrary files. Imported
 * form JSON is still run through sanitize_imported_form() which
 * strips every integration / token / webhook / recipient key before
 * persisting — defence in depth against a poisoned template file.
 *
 * Bundle shapes (supported by import_template)
 * --------------------------------------------
 *   Form-only:  { form: {...} }              → creates one form, returns form_id
 *   Page:       { page: {...}, forms: [...] } → existing draft-page flow
 *
 * The form-only shape is the recommended primary for the local
 * template library; the page shape is retained for back-compat with
 * templates that bundle a page + embedded forms.
 *
 * Extensibility
 * -------------
 * Filter `neural_forms_template_library_roots` to register additional
 * roots, e.g.:
 *
 *   add_filter( 'neural_forms_template_library_roots', function ( $roots ) {
 *       $roots['divi'][] = array(
 *           'dir' => NEURAL_FORMS_PRO_PATH . 'templates/divi/',
 *           'url' => NEURAL_FORMS_PRO_URL  . 'templates/divi/',
 *       );
 *       return $roots;
 *   } );
 */
class TemplateLibrary {

	/** Builders the loader recognises. Plugins can register roots for
	 *  any of these via the roots filter; the loader simply returns an
	 *  empty catalog for builders with no registered roots. */
	const SUPPORTED_BUILDERS = array( 'block-editor', 'gutenberg', 'divi', 'elementor' );

	/**
	 * Registered template roots, keyed by builder. Each value is a
	 * list of root arrays { dir, url } so multiple plugins (Lite +
	 * Pro + extensions) can contribute templates for the same builder
	 * without stepping on each other.
	 *
	 * Filter `neural_forms_template_library_roots` to extend.
	 *
	 * @return array<string, array<int, array{dir:string,url:string}>>
	 */
	public static function get_template_roots(): array {
		$roots = array(
			'block-editor' => array(
				array(
					'dir' => NEURAL_FORMS_PATH . 'templates/block-editor/',
					'url' => NEURAL_FORMS_URL  . 'templates/block-editor/',
				),
			),
		);
		$roots = (array) apply_filters( 'neural_forms_template_library_roots', $roots );

		// Normalise — drop builders with no roots, drop malformed
		// root entries.
		$out = array();
		foreach ( $roots as $builder => $list ) {
			if ( ! is_string( $builder ) || ! is_array( $list ) ) {
				continue;
			}
			$valid = array();
			foreach ( $list as $root ) {
				if ( is_array( $root ) && ! empty( $root['dir'] ) && ! empty( $root['url'] ) ) {
					$valid[] = array(
						'dir' => (string) $root['dir'],
						'url' => (string) $root['url'],
					);
				}
			}
			if ( ! empty( $valid ) ) {
				$out[ $builder ] = $valid;
			}
		}
		return $out;
	}

	/**
	 * Load the catalog for a builder from every registered root,
	 * merging the templates[] arrays. Returns a WP_Error only for
	 * unsupported builder slugs; missing / unreadable / malformed
	 * JSON in any single root is silently skipped so one broken root
	 * doesn't black out the whole catalog.
	 *
	 * $bypass_cache is preserved on the signature for API stability
	 * but is now a no-op — local file reads are cheap and avoid
	 * stale-cache surprises when a plugin ships a template update.
	 *
	 * @param bool   $bypass_cache  No-op (kept for signature stability).
	 * @param string $builder       Builder slug.
	 * @return array|WP_Error
	 */
	public static function fetch_catalog( bool $bypass_cache = false, string $builder = 'block-editor' ) {
		unset( $bypass_cache ); // no remote cache to bypass anymore

		// Back-compat: old clients that still send the legacy `gutenberg`
		// slug get rewritten to the canonical `block-editor`.
		if ( 'gutenberg' === $builder ) {
			$builder = 'block-editor';
		}

		if ( ! in_array( $builder, self::SUPPORTED_BUILDERS, true ) ) {
			return new WP_Error(
				'template_unsupported_builder',
				/* translators: %s: builder slug requested by the client. */
				sprintf( __( 'Template builder "%s" is not supported.', 'neural-forms-lite' ), $builder ),
				array( 'status' => 400 )
			);
		}

		$all_roots     = self::get_template_roots();
		$builder_roots = isset( $all_roots[ $builder ] ) ? $all_roots[ $builder ] : array();

		// No registered roots for this builder → return an empty,
		// well-formed catalog. The React UI renders this as the
		// "coming soon" / empty state instead of an error banner.
		if ( empty( $builder_roots ) ) {
			return array(
				'version'    => '',
				'builder'    => $builder,
				'updated_at' => '',
				'templates'  => array(),
			);
		}

		$all_templates     = array();
		$catalog_version   = '';
		$catalog_updated   = '';
		foreach ( $builder_roots as $root ) {
			$index_path = rtrim( $root['dir'], '/\\' ) . '/templates.json';
			if ( ! is_readable( $index_path ) ) {
				continue;
			}
			// Local plugin asset, not a remote URL — wp_remote_get is
			// wrong here. JSON parse failure for any single root is
			// skipped silently rather than erroring out the whole
			// catalog.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw  = file_get_contents( $index_path );
			$data = json_decode( (string) $raw, true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( '' === $catalog_version && isset( $data['version'] ) ) {
				$catalog_version = (string) $data['version'];
			}
			if ( '' === $catalog_updated && isset( $data['updated_at'] ) ) {
				$catalog_updated = (string) $data['updated_at'];
			}

			$templates = isset( $data['templates'] ) && is_array( $data['templates'] )
				? $data['templates']
				: array();

			foreach ( $templates as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				// Compatibility filter. If the row declares
				// compatibility[], the builder slug must appear in
				// it (accept the legacy `gutenberg` alias too).
				// Rows with no compatibility array are accepted by
				// default — the per-builder directory is itself an
				// implicit compatibility declaration.
				if ( isset( $row['compatibility'] ) && is_array( $row['compatibility'] ) ) {
					$compat = array_map( 'strval', $row['compatibility'] );
					if ( ! in_array( $builder, $compat, true )
						&& ! ( 'block-editor' === $builder && in_array( 'gutenberg', $compat, true ) )
					) {
						continue;
					}
				}
				$all_templates[] = self::normalize_catalog_row( $row, $root['url'] );
			}
		}

		return array(
			'version'    => $catalog_version,
			'builder'    => $builder,
			'updated_at' => $catalog_updated,
			'templates'  => $all_templates,
		);
	}

	/**
	 * Resolve a relative import_url against a registered root dir,
	 * with strict path-traversal protection.
	 *
	 * Rules:
	 *   - relative path required (no absolute, no scheme)
	 *   - no `..` segments
	 *   - must end with `.json`
	 *   - resolved realpath must be inside the realpath of the root
	 *
	 * @param string $root_dir  Registered template root dir.
	 * @param string $relative  Value from templates.json's import_url.
	 * @return string|WP_Error  Absolute filesystem path, or WP_Error.
	 */
	private static function resolve_local_path( string $root_dir, string $relative ) {
		$relative = trim( $relative );
		if ( '' === $relative ) {
			return new WP_Error(
				'template_invalid_path',
				__( 'Template import_url is empty.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}
		if ( strpos( $relative, '..' ) !== false ) {
			return new WP_Error( 'template_invalid_path', __( 'Template path contains forbidden segment.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $relative ) ) {
			return new WP_Error( 'template_invalid_path', __( 'Template path must be relative, not a URL.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}
		// No absolute path (Unix `/` or Windows `C:\`).
		if ( '/' === $relative[0] || '\\' === $relative[0] || preg_match( '#^[a-zA-Z]:[\\\\/]#', $relative ) ) {
			return new WP_Error( 'template_invalid_path', __( 'Template path must be relative.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/\.json$/i', $relative ) ) {
			return new WP_Error( 'template_invalid_path', __( 'Template path must reference a .json file.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}

		$base_real = realpath( rtrim( $root_dir, '/\\' ) );
		if ( false === $base_real ) {
			return new WP_Error( 'template_root_missing', __( 'Template root directory does not exist.', 'neural-forms-lite' ), array( 'status' => 500 ) );
		}
		$full = realpath( $base_real . DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative ) );
		if ( false === $full ) {
			return new WP_Error( 'template_file_missing', __( 'Template file does not exist.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( 0 !== strpos( $full, $base_real . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error( 'template_path_outside_root', __( 'Template path resolved outside the registered root.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}
		return $full;
	}

	/**
	 * Locate a template by id across every registered root for the
	 * given builder.
	 *
	 * @param string $builder
	 * @param string $template_id
	 * @return array{template:array,root:array}|null
	 */
	private static function find_template( string $builder, string $template_id ): ?array {
		$roots = self::get_template_roots();
		$list  = isset( $roots[ $builder ] ) ? $roots[ $builder ] : array();
		foreach ( $list as $root ) {
			$index_path = rtrim( $root['dir'], '/\\' ) . '/templates.json';
			if ( ! is_readable( $index_path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw  = file_get_contents( $index_path );
			$data = json_decode( (string) $raw, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$templates = isset( $data['templates'] ) && is_array( $data['templates'] ) ? $data['templates'] : array();
			foreach ( $templates as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) && (string) $row['id'] === $template_id ) {
					return array( 'template' => $row, 'root' => $root );
				}
			}
		}
		return null;
	}

	/**
	 * Import a template by (builder, template_id).
	 *
	 * The frontend may also pass the catalog's import_url for backward
	 * compat with the old API, but we IGNORE it — looking up the
	 * relative path via template_id in the local catalog is the only
	 * source of truth, so a tampered frontend payload can't be used to
	 * traverse the filesystem.
	 *
	 * Two bundle shapes are supported:
	 *
	 *   Form-only:   { form: {...} }
	 *     Creates a single form via FormRepository and returns its id +
	 *     edit URL. This is the primary shape for the local library.
	 *
	 *   Page+forms:  { page: {...}, forms: [...] }
	 *     Imports each embedded form, rewrites the page block markup's
	 *     formId references to the new local ids, and creates a draft
	 *     page. Kept for back-compat with remote bundles that ship a
	 *     full page layout around the form.
	 *
	 * @param string $builder      Builder slug (`block-editor`, `divi`, …).
	 * @param string $template_id  Catalog id (matches templates.json row).
	 * @return array|WP_Error
	 */
	public static function import_template( string $builder, string $template_id ) {
		// Back-compat alias.
		if ( 'gutenberg' === $builder ) {
			$builder = 'block-editor';
		}

		if ( ! in_array( $builder, self::SUPPORTED_BUILDERS, true ) ) {
			return new WP_Error(
				'template_unsupported_builder',
				/* translators: %s: builder slug requested by the client. */
				sprintf( __( 'Template builder "%s" is not supported.', 'neural-forms-lite' ), $builder ),
				array( 'status' => 400 )
			);
		}

		$template_id = sanitize_key( $template_id );
		if ( '' === $template_id ) {
			return new WP_Error( 'template_missing_id', __( 'template_id is required.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}

		$found = self::find_template( $builder, $template_id );
		if ( null === $found ) {
			return new WP_Error( 'template_not_found', __( 'Template not found in the local library.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}

		$relative = isset( $found['template']['import_url'] ) ? (string) $found['template']['import_url'] : '';
		$file     = self::resolve_local_path( $found['root']['dir'], $relative );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Local plugin asset — file_get_contents is appropriate; the
		// realpath gate above already proved $file lives inside the
		// registered root.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw    = file_get_contents( $file );
		$bundle = json_decode( (string) $raw, true );
		if ( ! is_array( $bundle ) ) {
			return new WP_Error(
				'template_invalid_bundle',
				__( 'Template file is not valid JSON.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}

		// Bundle shape detection.
		//   form-only: has `form` (object) and no `page`
		//   page:      has `page` (object) [+ optional `forms[]`]
		// Other shapes fall through to validate_bundle_shape, which
		// errors with a useful message.
		if ( isset( $bundle['form'] ) && is_array( $bundle['form'] ) && ! isset( $bundle['page'] ) ) {
			return self::import_form_only_bundle( $bundle );
		}

		// Page+forms flow — original behaviour. Run the structural
		// validator first so a malformed bundle surfaces a precise
		// error instead of a confusing partial-import.
		$shape_err = self::validate_bundle_shape( $bundle );
		if ( $shape_err ) {
			return $shape_err;
		}
		return self::import_page_bundle( $bundle );
	}

	/**
	 * Import a form-only bundle: { form: {...} } → one form created
	 * via FormRepository. Returns the new form_id + an edit URL so the
	 * builder can navigate the admin straight into editing it.
	 *
	 * @param array $bundle
	 * @return array|WP_Error
	 */
	private static function import_form_only_bundle( array $bundle ) {
		$form_data = isset( $bundle['form'] ) && is_array( $bundle['form'] ) ? $bundle['form'] : array();
		if ( empty( $form_data ) ) {
			return new WP_Error(
				'template_empty_form',
				__( 'Template bundle has an empty form payload.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}
		$clean  = self::sanitize_imported_form( $form_data );
		$new_id = FormRepository::create( $clean );
		if ( ! $new_id ) {
			return new WP_Error(
				'template_form_insert_failed',
				__( 'Failed to import the template form.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}
		return array(
			'form_id'           => (int) $new_id,
			'edit_url'          => admin_url( 'admin.php?page=neural-forms&edit=' . (int) $new_id ),
			'view_url'          => '',
			'page_id'           => 0,
			'imported_form_ids' => array( (int) $new_id ),
		);
	}

	/**
	 * Original page-bundle import: creates each embedded form,
	 * rewrites formId references in page.post_content, inserts the
	 * page as a draft.
	 *
	 * @param array $bundle  Already-validated bundle.
	 * @return array|WP_Error
	 */
	private static function import_page_bundle( array $bundle ) {
		$page  = isset( $bundle['page'] ) && is_array( $bundle['page'] ) ? $bundle['page'] : array();
		$forms = isset( $bundle['forms'] ) && is_array( $bundle['forms'] ) ? $bundle['forms'] : array();

		// Import forms first so the formId map is ready before we
		// rewrite the page content. Each row carries the form payload
		// inline (the older multi-file layout had a `file` URL here
		// instead — we no longer fetch that for MVP).
		$id_map            = array();
		$slug_map          = array();
		$imported_form_ids = array();
		foreach ( $forms as $form_ref ) {
			if ( ! is_array( $form_ref ) ) {
				continue;
			}
			$source_id = isset( $form_ref['sourceFormId'] ) ? (int) $form_ref['sourceFormId'] : 0;
			$form_data = isset( $form_ref['form'] ) && is_array( $form_ref['form'] ) ? $form_ref['form'] : null;
			if ( $source_id <= 0 || null === $form_data ) {
				// Skip — without a sourceFormId we can't remap, and
				// without an inline form payload there's nothing to
				// import. A malformed row shouldn't fail the whole
				// import.
				continue;
			}

			$clean  = self::sanitize_imported_form( $form_data );
			$new_id = FormRepository::create( $clean );
			if ( ! $new_id ) {
				return new WP_Error(
					'template_form_insert_failed',
					__( 'Failed to import a form referenced by the template.', 'neural-forms-lite' ),
					array( 'status' => 500 )
				);
			}

			$id_map[ $source_id ] = (int) $new_id;
			$imported_form_ids[]  = (int) $new_id;

			// Capture the source slug when the bundle ships one — used
			// to rewrite `[neural-forms slug="…"]` shortcodes that
			// referenced the form on the export side. Falls back to
			// the inline payload's own slug if the ref didn't surface
			// it explicitly.
			$source_slug = '';
			if ( isset( $form_ref['sourceSlug'] ) ) {
				$source_slug = (string) $form_ref['sourceSlug'];
			} elseif ( isset( $form_data['slug'] ) ) {
				$source_slug = (string) $form_data['slug'];
			}
			$source_slug = strtolower( trim( $source_slug ) );
			if ( $source_slug !== '' ) {
				$slug_map[ $source_slug ] = (int) $new_id;
			}
		}

		// Rewrite the page content with new local form IDs, then strip
		// any markup that's not allowed in post content. wp_kses_post
		// is the same filter WordPress applies when an Editor user
		// saves a post — it strips <script>, on* attributes,
		// javascript: URLs, and other XSS vectors. The catalog server
		// is ours, but treating its output as untrusted by default
		// means a CDN poisoning or a typo in a future template author
		// upload cannot ship script payloads into a downstream site.
		$raw_content     = isset( $page['post_content'] ) ? (string) $page['post_content'] : '';
		$rewrote_content = self::replace_form_ids_in_content( $raw_content, $id_map, $slug_map );
		$safe_content    = wp_kses_post( $rewrote_content );

		$title = isset( $page['title'] ) ? (string) $page['title'] : ( isset( $bundle['name'] ) ? (string) $bundle['name'] : __( 'Imported Template', 'neural-forms-lite' ) );
		$slug  = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
		// Publish the imported page immediately so the user can hit
		// "View page" and see the rendered form without an extra
		// review step. They can flip it back to draft / pending from
		// the standard wp-admin Pages edit screen if they need to.
		$status = 'publish';

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $title ),
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_type'    => 'page',
				'post_content' => $safe_content,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'page_id'           => (int) $post_id,
			'edit_url'          => get_edit_post_link( (int) $post_id, 'raw' ),
			'view_url'          => get_permalink( (int) $post_id ),
			'imported_form_ids' => $imported_form_ids,
		);
	}

	/**
	 * Strip every integration / secret / recipient field from a form
	 * payload before persistence. Keeps only the structural / display
	 * settings that are safe to ship with a template.
	 *
	 * Removed at the `settings` level (whitelist approach — anything
	 * not explicitly kept is dropped):
	 *   - hubspot / google_forms / whatsapp / mailchimp / zapier blocks
	 *   - api_key / access_token / refresh_token / *_token / *_secret
	 *   - webhook_url / webhook / hook_url
	 *   - notification recipients
	 *   - last_sent_at / last_error / last_status_code telemetry
	 *
	 * Removed at the root level:
	 *   - entries / submissions / ai_responses
	 *
	 * Kept:
	 *   - name / description / form_schema (fields, layout)
	 *   - settings.success_message / settings.error_message
	 *   - settings.redirect_url (if it's a relative path; absolute
	 *     URLs are dropped to avoid leaking the template author's
	 *     own site or sending visitors off-domain)
	 *
	 * @param array $form Raw form payload from the remote template.
	 * @return array
	 */
	public static function sanitize_imported_form( array $form ): array {
		// Name: accept either `name` (legacy nested shape) or `title`
		// (flat shape the live remote bundles use). Fall back to a
		// generic label only when neither is present.
		$raw_name = '';
		if ( isset( $form['name'] ) && '' !== (string) $form['name'] ) {
			$raw_name = (string) $form['name'];
		} elseif ( isset( $form['title'] ) && '' !== (string) $form['title'] ) {
			$raw_name = (string) $form['title'];
		}
		$name = '' !== $raw_name ? $raw_name : __( 'Imported Form', 'neural-forms-lite' );
		$desc = isset( $form['description'] ) ? (string) $form['description'] : '';

		// Deep-sanitize every string inside form_schema before it goes
		// to the DB. The schema is a nested array of fields (label,
		// placeholder, default_value, help, options[], …) — any of
		// those values could contain script tags if the remote payload
		// is malicious or corrupted. sanitize_text_field strips tags
		// and HTML-encodes special chars, so the stored value is safe
		// to render later. SQL injection is already handled by
		// FormRepository::create() ($wpdb->insert with format
		// specifiers); deep sanitize here defends the rendering layer.
		//
		// Two bundle shapes accepted:
		//   - Legacy nested: form.form_schema = { fields, settings }
		//   - Flat (current remote 2026-05+): form.fields + form.design
		//     live at the root; we synthesise a form_schema wrapper
		//     so FormRepository::create() sees what it expects.
		if ( isset( $form['form_schema'] ) && is_array( $form['form_schema'] ) ) {
			$schema_raw = $form['form_schema'];
		} else {
			$schema_raw = array();
			if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
				$schema_raw['fields'] = $form['fields'];
			}
			// `design` carries layout/branding (the form-builder design
			// tab payload). Live bundles ship it at the root; the
			// builder reads it back from form_schema.design so we
			// preserve it under the schema wrapper.
			if ( isset( $form['design'] ) && is_array( $form['design'] ) ) {
				$schema_raw['design'] = $form['design'];
			}
		}
		$schema = ! empty( $schema_raw )
			? self::sanitize_recursive_strings( $schema_raw )
			: array();

		$raw_settings   = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();
		$clean_settings = array();

		// Whitelist of safe settings keys. The template author can
		// pre-fill these for the receiving site without exposing
		// any secrets or recipients.
		$safe_keys = array(
			'success_message',
			'error_message',
			'submit_button_text',
			'success_redirect',          // legacy alias
			'thanks_page_message',
		);
		foreach ( $safe_keys as $key ) {
			if ( array_key_exists( $key, $raw_settings ) ) {
				$clean_settings[ $key ] = is_scalar( $raw_settings[ $key ] )
					? (string) $raw_settings[ $key ]
					: '';
			}
		}

		// `redirect_url` — only keep if it's relative; absolute URLs
		// would point the new site's visitors to the template
		// author's domain on submit, which is hostile.
		if ( isset( $raw_settings['redirect_url'] ) && is_string( $raw_settings['redirect_url'] ) ) {
			$candidate = trim( $raw_settings['redirect_url'] );
			if ( $candidate !== '' && strpos( $candidate, '://' ) === false && $candidate[0] !== '/' ) {
				// Treat as a relative slug; safe to keep.
				$clean_settings['redirect_url'] = sanitize_text_field( $candidate );
			} elseif ( $candidate !== '' && $candidate[0] === '/' && strpos( $candidate, '//' ) !== 0 ) {
				// Site-relative path like /thank-you — safe.
				$clean_settings['redirect_url'] = esc_url_raw( $candidate );
			}
		}

		return array(
			'name'        => sanitize_text_field( $name ),
			'description' => sanitize_textarea_field( $desc ),
			'form_schema' => $schema,
			'settings'    => $clean_settings,
			// Imported forms ship active so the published landing page
			// has a working live form on first render. Admins can flip
			// the form back to draft from the Forms list if they need
			// to (or use the Archive action for a longer pause).
			'status'      => 'active',
		);
	}

	/**
	 * Walk a nested array and sanitize every string leaf with
	 * sanitize_text_field. Numbers / booleans / nulls pass through
	 * unchanged. Anything else (object, resource) is dropped. Used to
	 * defang form_schema before it lands in the DB even though the
	 * remote payload is first-party — defense in depth against a
	 * compromised CDN, mis-authored template, or future-proofing
	 * against a third-party catalog mirror.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function sanitize_recursive_strings( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				// Sanitize the key too — array keys end up in option
				// names / column lookups downstream and shouldn't
				// carry markup. Numeric keys are preserved as-is.
				$safe_key         = is_int( $k ) ? $k : sanitize_text_field( (string) $k );
				$out[ $safe_key ] = self::sanitize_recursive_strings( $v );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		// Objects / resources / closures are not valid in a JSON-
		// originated payload; drop them rather than coerce.
		return null;
	}

	/**
	 * Structural validation for a self-contained template bundle.
	 *
	 * Returns null when shape is acceptable, a WP_Error otherwise.
	 * Required: top-level object, `builder` string, `page` object
	 * containing a string `post_content`. `forms` is optional (a
	 * landing-page template with no form is legal) but must be an
	 * array when present.
	 *
	 * @param mixed $bundle
	 * @return WP_Error|null
	 */
	private static function validate_bundle_shape( $bundle ) {
		if ( ! is_array( $bundle ) ) {
			return new WP_Error( 'template_bundle_invalid', __( 'Template bundle is not a valid object.', 'neural-forms-lite' ), array( 'status' => 502 ) );
		}
		if ( empty( $bundle['builder'] ) || ! is_string( $bundle['builder'] ) ) {
			return new WP_Error( 'template_bundle_missing_builder', __( 'Template bundle is missing the builder field.', 'neural-forms-lite' ), array( 'status' => 502 ) );
		}
		if ( ! isset( $bundle['page'] ) || ! is_array( $bundle['page'] ) ) {
			return new WP_Error( 'template_bundle_missing_page', __( 'Template bundle is missing the page object.', 'neural-forms-lite' ), array( 'status' => 502 ) );
		}
		if ( ! isset( $bundle['page']['post_content'] ) || ! is_string( $bundle['page']['post_content'] ) ) {
			return new WP_Error( 'template_bundle_missing_content', __( 'Template bundle page is missing post_content.', 'neural-forms-lite' ), array( 'status' => 502 ) );
		}
		if ( isset( $bundle['forms'] ) && ! is_array( $bundle['forms'] ) ) {
			return new WP_Error( 'template_bundle_forms_invalid', __( 'Template bundle "forms" is not an array.', 'neural-forms-lite' ), array( 'status' => 502 ) );
		}
		return null;
	}

	/**
	 * Replace formId attributes inside Gutenberg block content with
	 * locally-imported IDs.
	 *
	 * Preferred path: parse_blocks → walk recursively → swap attrs →
	 * serialize_blocks. Catches every Neural Forms block instance
	 * regardless of attribute order, whitespace, or whether the value
	 * is encoded as a JSON number or string.
	 *
	 * Fallback: a narrow regex that targets just the literal
	 * `"formId":<n>` and `"formId":"<n>"` patterns for the IDs we know
	 * about. The fallback only runs if the block parser produced no
	 * blocks (extreme edge case: the page.json had no Gutenberg block
	 * markup at all) so we don't risk double-rewriting.
	 *
	 * @param string $content  Block markup from page.json's post_content.
	 * @param array  $id_map   source form ID (int) → new local form ID (int)
	 * @param array  $slug_map source form slug (string) → new local form ID (int)
	 * @return string
	 */
	public static function replace_form_ids_in_content( string $content, array $id_map, array $slug_map = array() ): string {
		if ( $content === '' ) {
			return $content;
		}
		if ( empty( $id_map ) && empty( $slug_map ) ) {
			return $content;
		}

		// Coerce keys + values to int to make the lookup robust against
		// JSON-decoded values arriving as strings.
		$normalized = array();
		foreach ( $id_map as $src => $dst ) {
			$normalized[ (int) $src ] = (int) $dst;
		}

		// Normalise slug map: trim + lowercase the source slugs so
		// matches survive case/whitespace drift in the bundle.
		$normalized_slugs = array();
		foreach ( $slug_map as $src_slug => $dst_id ) {
			$key = strtolower( trim( (string) $src_slug ) );
			if ( $key !== '' ) {
				$normalized_slugs[ $key ] = (int) $dst_id;
			}
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			$fallback = self::regex_replace_form_ids( $content, $normalized );
			return self::rewrite_form_shortcodes( $fallback, $normalized, $normalized_slugs );
		}

		$rewritten  = self::walk_blocks_swap_form_id( $blocks, $normalized );
		$serialized = serialize_blocks( $rewritten );

		// If the serialized output is empty (shouldn't happen but
		// guard against it), fall back to the regex pass rather than
		// blanking the imported page.
		if ( $serialized === '' || null === $serialized ) {
			$fallback = self::regex_replace_form_ids( $content, $normalized );
			return self::rewrite_form_shortcodes( $fallback, $normalized, $normalized_slugs );
		}

		// Shortcodes ([neural-forms id="…"] / slug="…") live in the
		// raw post_content alongside blocks (commonly inside a
		// wp:shortcode wrapper). serialize_blocks preserves their
		// text verbatim, so we rewrite them after the block pass.
		return self::rewrite_form_shortcodes( $serialized, $normalized, $normalized_slugs );
	}

	/**
	 * Rewrite [neural-forms id="N"] / [neural-forms slug="X"] shortcodes
	 * so they point at the freshly-imported local form IDs.
	 *
	 * Handles single-, double-, and unquoted attribute values, attributes
	 * in either order, and the self-closing `/]` variant. Shortcodes
	 * whose target isn't in the map pass through untouched.
	 *
	 * Slug references are rewritten to the `id="N"` form because the
	 * importer mints a fresh unique slug for each form on insert — so
	 * the source slug is not guaranteed to exist locally.
	 *
	 * @param string $content
	 * @param array  $id_map   source form ID (int) → new local form ID (int)
	 * @param array  $slug_map source form slug (lowercased) → new local form ID (int)
	 * @return string
	 */
	private static function rewrite_form_shortcodes( string $content, array $id_map, array $slug_map ): string {
		if ( $content === '' || ( empty( $id_map ) && empty( $slug_map ) ) ) {
			return $content;
		}
		if ( strpos( $content, '[neural-forms' ) === false ) {
			return $content;
		}

		// Match the whole [neural-forms …] (or [neural-forms … /])
		// opening tag so we can rewrite each attribute in place.
		$pattern = '/\[neural-forms\b([^\]]*?)(\/?)\]/i';

		return preg_replace_callback(
			$pattern,
			static function ( $matches ) use ( $id_map, $slug_map ) {
				$inner      = (string) $matches[1];
				$self_close = (string) $matches[2];
				$has_id     = (bool) preg_match( '/\bid\s*=/i', $inner );

				// id="N" / id='N' / id=N — rewrite first because the
				// shortcode handler treats id as taking precedence
				// over slug.
				$inner = preg_replace_callback(
					'/(\bid\s*=\s*)("?)(\d+)("?)/i',
					static function ( $m ) use ( $id_map ) {
						$src = (int) $m[3];
						if ( $src > 0 && isset( $id_map[ $src ] ) ) {
							return $m[1] . '"' . (int) $id_map[ $src ] . '"';
						}
						return $m[0];
					},
					$inner
				);

				// slug="X" / slug='X' / slug=X — only rewrite when
				// the shortcode has no id attribute (id takes
				// precedence in the handler). Rewrite to id="N"
				// because the new install mints a fresh unique slug
				// on insert.
				if ( ! $has_id && ! empty( $slug_map ) ) {
					$inner = preg_replace_callback(
						'/(\bslug\s*=\s*)(?|"([^"]*)"|\'([^\']*)\'|([^\s\]\/]+))/i',
						static function ( $m ) use ( $slug_map ) {
							$key = strtolower( trim( (string) $m[2] ) );
							if ( $key !== '' && isset( $slug_map[ $key ] ) ) {
								return 'id="' . (int) $slug_map[ $key ] . '"';
							}
							return $m[0];
						},
						$inner
					);
				}

				return '[neural-forms' . $inner . $self_close . ']';
			},
			$content
		);
	}

	/**
	 * Recursive helper for {@see ::replace_form_ids_in_content()}.
	 *
	 * @param array $blocks
	 * @param array $id_map
	 * @return array
	 */
	private static function walk_blocks_swap_form_id( array $blocks, array $id_map ): array {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			// Cover every Neural Forms block kind that carries a
			// formId attribute (form / quiz / multi-step / poll).
			if ( $name !== '' && strpos( $name, 'neural-forms/' ) === 0 ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				if ( isset( $attrs['formId'] ) ) {
					$src = (int) $attrs['formId'];
					if ( $src > 0 && isset( $id_map[ $src ] ) ) {
						$attrs['formId']       = $id_map[ $src ];
						$blocks[ $i ]['attrs'] = $attrs;
					}
				}
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::walk_blocks_swap_form_id( $block['innerBlocks'], $id_map );
			}
		}
		return $blocks;
	}

	/**
	 * Narrow regex fallback for {@see ::replace_form_ids_in_content()}.
	 *
	 * Matches `"formId":<digits>` and `"formId":"<digits>"` exactly,
	 * with optional whitespace after the colon. Only replaces IDs
	 * present in $id_map — values not in the map pass through
	 * untouched.
	 *
	 * @param string $content
	 * @param array  $id_map
	 * @return string
	 */
	private static function regex_replace_form_ids( string $content, array $id_map ): string {
		return preg_replace_callback(
			'/"formId"\s*:\s*"?(\d+)"?/',
			static function ( $matches ) use ( $id_map ) {
				$src = (int) $matches[1];
				if ( isset( $id_map[ $src ] ) ) {
					return '"formId":' . (int) $id_map[ $src ];
				}
				return $matches[0];
			},
			$content
		);
	}

	/**
	 * Shape a catalog row into the minimal payload the React UI
	 * consumes. Defensive against missing keys so a partial row
	 * doesn't crash the card grid.
	 *
	 * Relative thumbnail / preview_url values from templates.json get
	 * resolved against $root_url (the registered URL for the root
	 * that owns this template), producing absolute plugin URLs the
	 * browser can load directly. Already-absolute https URLs pass
	 * through; http:// URLs are dropped so a template author can't
	 * leak mixed-content references into the admin.
	 *
	 * `import_url` stays as the relative path — the server consults
	 * it via find_template() + resolve_local_path() on import, and
	 * the React UI doesn't fetch it directly.
	 *
	 * @param array  $row
	 * @param string $root_url  Registered base URL for the root.
	 * @return array
	 */
	private static function normalize_catalog_row( array $row, string $root_url = '' ): array {
		$resolve_url = static function ( $val ) use ( $root_url ) {
			$val = trim( (string) $val );
			if ( '' === $val ) {
				return '';
			}
			if ( preg_match( '#^https://#i', $val ) ) {
				return esc_url_raw( $val );
			}
			if ( preg_match( '#^http://#i', $val ) ) {
				// Skip insecure references — prevents a template
				// author from triggering mixed-content warnings.
				return '';
			}
			if ( '' === $root_url ) {
				return '';
			}
			return esc_url_raw( rtrim( $root_url, '/' ) . '/' . ltrim( $val, '/' ) );
		};

		// Category accepts either a string ("Contact Us") or an array
		// (["Contact Us","Lead"]). Pass both shapes through — the
		// React UI's normaliseCategories helper handles either.
		$category = '';
		if ( isset( $row['category'] ) ) {
			if ( is_array( $row['category'] ) ) {
				$category = array_values( array_map(
					'sanitize_text_field',
					array_map( 'strval', $row['category'] )
				) );
			} elseif ( is_string( $row['category'] ) ) {
				$category = (string) $row['category'];
			}
		}

		return array(
			'id'            => isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '',
			'name'          => isset( $row['name'] ) ? (string) $row['name'] : '',
			'description'   => isset( $row['description'] ) ? (string) $row['description'] : '',
			'category'      => $category,
			'compatibility' => isset( $row['compatibility'] ) && is_array( $row['compatibility'] )
				? array_map( 'strval', $row['compatibility'] )
				: array(),
			'thumbnail'     => isset( $row['thumbnail'] )   ? $resolve_url( $row['thumbnail'] )   : '',
			'preview_url'   => isset( $row['preview_url'] ) ? $resolve_url( $row['preview_url'] ) : '',
			// import_url stays as the relative path; clients should
			// treat it as opaque and POST template_id back instead.
			'import_url'    => isset( $row['import_url'] ) ? (string) $row['import_url'] : '',
			'tags'          => isset( $row['tags'] ) && is_array( $row['tags'] )
				? array_values( array_map( 'sanitize_text_field', array_map( 'strval', $row['tags'] ) ) )
				: array(),
		);
	}
}
