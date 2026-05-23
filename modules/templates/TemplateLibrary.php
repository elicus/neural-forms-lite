<?php
namespace NeuralForms\Templates;

use NeuralForms\Forms\FormRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Remote template library service.
 *
 * Lite's first-party hosted catalog lives at templates.neuralforms.ai
 * and ships a JSON catalog + per-template manifest / page / form JSON
 * files. This class handles the read path (fetching + caching the
 * catalog) and the import path (assembling a draft WordPress page
 * with any Neural Forms forms referenced from the template).
 *
 * Trust model
 * -----------
 * The remote host is fixed to `templates.neuralforms.ai`; every URL
 * passed to wp_remote_get() is validated against that allow-list (the
 * `neural_forms_template_library_allowed_hosts` filter exists so a
 * site can opt-in to a staging mirror, but the default is locked).
 * Imported form JSON is run through {@see ::sanitize_imported_form()}
 * which strips every integration / token / webhook / recipient key
 * before persisting via FormRepository — the template author can't
 * inject HubSpot tokens, Slack webhooks, or admin email recipients
 * into a downstream site.
 *
 * Caching
 * -------
 * The catalog is cached as a transient for 6 hours. Per-template
 * manifests / pages / forms are NOT cached — they're only fetched
 * at import time, which is rare and human-triggered.
 *
 * Phase scope
 * -----------
 * MVP supports `builder: gutenberg` templates only. Divi / Elementor
 * branches are intentionally absent; the catalog field
 * `compatibility[]` is kept extensible so a future Divi import can
 * be added without breaking the catalog contract.
 */
class TemplateLibrary {

	/** Default remote host. Filter the allow-list to add mirrors. */
	const DEFAULT_HOST = 'templates.neuralforms.ai';

	/** Catalog base — builder slug is appended to form the per-builder
	 *  index URL (e.g. /gutenberg/templates.json). The flat layout
	 *  scales to hundreds of templates without a single root catalog
	 *  growing unbounded — each builder gets its own index and the
	 *  plugin only fetches the one it needs. */
	const CATALOG_BASE = 'https://templates.neuralforms.ai/';

	/** Builders we support fetching for. The remote catalog host moved
	 *  from /gutenberg/ to /block-editor/ in 2026-05; we keep
	 *  'gutenberg' in the allow-list as a back-compat alias that
	 *  rewrites to 'block-editor' before the URL is built (see
	 *  fetch_catalog). divi / elementor slugs can be added here as
	 *  catalogs ship without touching call sites. */
	const SUPPORTED_BUILDERS = array( 'block-editor', 'gutenberg' );

	/** Transient key prefix for the catalog cache. Suffixed by builder
	 *  so a future divi/elementor cache doesn't collide. */
	const CATALOG_TRANSIENT_PREFIX = 'neural_forms_template_catalog_';

	/** Cache TTL in seconds. */
	const CATALOG_TTL = 6 * HOUR_IN_SECONDS;

	/** Outbound request timeout in seconds. */
	const REQUEST_TIMEOUT = 15;

	/**
	 * Fetch the remote catalog and return only Gutenberg-compatible
	 * templates. Returns a WP_Error on transport / decode failure so
	 * the REST endpoint can surface a useful message to the admin.
	 *
	 * @param bool $bypass_cache When true, skips the transient lookup.
	 * @return array|WP_Error
	 */
	public static function fetch_catalog( bool $bypass_cache = false, string $builder = 'block-editor' ) {
		if ( ! in_array( $builder, self::SUPPORTED_BUILDERS, true ) ) {
			return new WP_Error(
				'template_unsupported_builder',
				/* translators: %s: builder slug requested by the client. */
				sprintf( __( 'Template builder "%s" is not supported.', 'neural-forms-lite' ), $builder ),
				array( 'status' => 400 )
			);
		}

		// Back-compat: the catalog host moved from /gutenberg/ to
		// /block-editor/ in 2026-05. Older clients that still send
		// `?builder=gutenberg` get rewritten here so the request hits
		// the live catalog instead of a 404.
		if ( 'gutenberg' === $builder ) {
			$builder = 'block-editor';
		}

		$transient_key = self::CATALOG_TRANSIENT_PREFIX . $builder;
		if ( ! $bypass_cache ) {
			$cached = get_transient( $transient_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$catalog_url = self::CATALOG_BASE . $builder . '/templates.json';
		$catalog     = self::request_json( $catalog_url );

		// Catalog fetch is resilient by design. DNS failures, 4xx / 5xx
		// from the remote, malformed JSON — none of those should
		// surface as a scary "cURL error 6: Could not resolve host"
		// banner inside the admin. Return an empty catalog with a
		// `service_unavailable` flag instead so the React UI can render
		// a friendly empty state. Cache the empty payload too (short
		// TTL) so repeated screen visits don't hammer the network with
		// retries while the remote is down.
		if ( is_wp_error( $catalog ) ) {
			$payload = array(
				'version'             => '',
				'builder'             => $builder,
				'updated_at'          => '',
				'templates'           => array(),
				'service_unavailable' => true,
			);
			set_transient( $transient_key, $payload, 5 * MINUTE_IN_SECONDS );
			return $payload;
		}

		$templates = isset( $catalog['templates'] ) && is_array( $catalog['templates'] )
			? $catalog['templates']
			: array();

		// Filter to compatible templates only. Catalog entries
		// without a `compatibility` array, or whose array doesn't
		// include this builder, are excluded so a malformed row
		// doesn't render as a broken card in the UI.
		$visible = array();
		foreach ( $templates as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$compat = isset( $row['compatibility'] ) && is_array( $row['compatibility'] )
				? array_map( 'strval', $row['compatibility'] )
				: array();
			if ( ! in_array( $builder, $compat, true ) ) {
				continue;
			}
			$visible[] = self::normalize_catalog_row( $row );
		}

		$payload = array(
			'version'    => isset( $catalog['version'] ) ? (string) $catalog['version'] : '',
			'builder'    => isset( $catalog['builder'] ) ? (string) $catalog['builder'] : $builder,
			'updated_at' => isset( $catalog['updated_at'] ) ? (string) $catalog['updated_at'] : '',
			'templates'  => $visible,
		);

		set_transient( $transient_key, $payload, self::CATALOG_TTL );

		return $payload;
	}

	/**
	 * Import a template from a single self-contained bundle URL.
	 *
	 * MVP layout (templates.neuralforms.ai):
	 *   /<builder>/templates.json                    ← index (catalog)
	 *   /<builder>/<category>/<slug>.json            ← bundle  (this method)
	 *   /<builder>/<category>/screenshots/<slug>.jpg ← card thumb
	 *
	 * The bundle is one JSON document carrying both the Gutenberg page
	 * (block markup) and every form referenced from that markup — so
	 * a successful import is one HTTP request, not three (catalog
	 * already fetched the index, this is the second + final fetch).
	 *
	 * Flow:
	 *   1. Validate the bundle URL host against the allow-list.
	 *   2. Fetch the bundle JSON.
	 *   3. Structural validation (builder, page, forms shape).
	 *   4. For each `forms[]` row: sanitize the embedded `form`
	 *      payload, insert via FormRepository, record
	 *      sourceFormId → newLocalFormId.
	 *   5. Replace formId attributes inside page.post_content using
	 *      parse_blocks() / serialize_blocks(), with a regex fallback
	 *      for safety.
	 *   6. wp_kses_post() the rewritten content.
	 *   7. wp_insert_post() as a draft page.
	 *
	 * Extensibility: the manifest-based multi-file layout (catalog →
	 * manifest → page.json → forms[].file) is no longer fetched, but
	 * the helpers it relied on (replace_form_ids_in_content,
	 * sanitize_imported_form, validate_page_shape) are unchanged and
	 * reusable if a future release reintroduces the multi-file flow
	 * for very large templates.
	 *
	 * Returns an array with `page_id`, `edit_url`, `view_url`,
	 * `imported_form_ids[]` on success; WP_Error on any failure.
	 *
	 * @param string $import_url Bundle URL on the allow-list.
	 * @return array|WP_Error
	 */
	public static function import_template( string $import_url ) {
		if ( ! self::is_allowed_url( $import_url ) ) {
			return new WP_Error(
				'template_disallowed_host',
				__( 'Template import URL is not on the allow-list.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$bundle = self::request_json( $import_url );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		// Structural validation BEFORE we touch any value. Even though
		// the catalog server is first-party, we treat its payloads as
		// untrusted — a compromised CDN cache, a man-in-the-middle, or
		// just a typo in template authoring shouldn't be enough to
		// poison a downstream install.
		$shape_err = self::validate_bundle_shape( $bundle );
		if ( $shape_err ) {
			return $shape_err;
		}

		// Builder gate — Lite imports Block-Editor / Gutenberg bundles.
		// The remote host moved from /gutenberg/ to /block-editor/ in
		// 2026-05; bundles now declare `builder: 'block-editor'`.
		// Older bundles still declaring `gutenberg` continue to import
		// (same payload shape — only the slug label changed).
		$builder = isset( $bundle['builder'] ) ? (string) $bundle['builder'] : '';
		if ( ! in_array( $builder, array( 'block-editor', 'gutenberg' ), true ) ) {
			return new WP_Error(
				'template_unsupported_builder',
				/* translators: %s: builder slug from the remote bundle. */
				sprintf( __( 'Template builder "%s" is not supported yet.', 'neural-forms-lite' ), $builder ),
				array( 'status' => 400 )
			);
		}

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
	 * True when $url's host is on the allow-list.
	 *
	 * Filter `neural_forms_template_library_allowed_hosts` to add
	 * mirrors (e.g. a staging environment) without modifying source.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function is_allowed_url( string $url ): bool {
		$url = trim( $url );
		if ( $url === '' ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || $host === '' ) {
			return false;
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( $scheme !== 'https' ) {
			return false;
		}

		$allowed = (array) apply_filters(
			'neural_forms_template_library_allowed_hosts',
			array( self::DEFAULT_HOST )
		);
		$allowed = array_map( 'strtolower', array_map( 'strval', $allowed ) );
		return in_array( strtolower( $host ), $allowed, true );
	}

	/**
	 * Shape a catalog row into the minimal payload the React UI
	 * consumes. Defensive against missing keys so a partial row
	 * doesn't crash the card grid.
	 *
	 * @param array $row
	 * @return array
	 */
	private static function normalize_catalog_row( array $row ): array {
		// Field names mirror the templates.json contract:
		// name         (card title, was "title" in the older layout)
		// preview_url  (HTML demo page opened in a new tab — was "preview")
		// import_url   (the single self-contained template JSON the
		// Import button POSTs through — was "manifest")
		// thumbnail / category / compatibility / tags / description are
		// unchanged. Any URL that fails the allow-list check is dropped
		// to '' so the card still renders (just without that image /
		// action) rather than failing the whole grid.
		return array(
			'id'            => isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '',
			'name'          => isset( $row['name'] ) ? (string) $row['name'] : '',
			'description'   => isset( $row['description'] ) ? (string) $row['description'] : '',
			'category'      => isset( $row['category'] ) ? (string) $row['category'] : '',
			'compatibility' => isset( $row['compatibility'] ) && is_array( $row['compatibility'] )
				? array_map( 'strval', $row['compatibility'] )
				: array(),
			'thumbnail'     => isset( $row['thumbnail'] ) && self::is_allowed_url( (string) $row['thumbnail'] )
				? esc_url_raw( (string) $row['thumbnail'] )
				: '',
			'preview_url'   => isset( $row['preview_url'] ) && self::is_allowed_url( (string) $row['preview_url'] )
				? esc_url_raw( (string) $row['preview_url'] )
				: '',
			'import_url'    => isset( $row['import_url'] ) && self::is_allowed_url( (string) $row['import_url'] )
				? esc_url_raw( (string) $row['import_url'] )
				: '',
			'tags'          => isset( $row['tags'] ) && is_array( $row['tags'] )
				? array_values( array_map( 'sanitize_text_field', array_map( 'strval', $row['tags'] ) ) )
				: array(),
		);
	}

	/**
	 * Fetch a JSON document over HTTPS with a fixed timeout, validate
	 * the status code, and decode the body. Returns a WP_Error on any
	 * failure so the caller can render an admin-facing message.
	 *
	 * @param string $url
	 * @return array|WP_Error
	 */
	private static function request_json( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 2,
				'headers'     => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Don't surface the raw transport message (e.g. "cURL
			// error 6: Could not resolve host: …") — it's
			// implementation noise the admin can't act on and it
			// would advertise our deploy state when the host isn't
			// live yet. Log the underlying message at debug level
			// only, return a generic admin-facing message.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Neural Forms templates: transport failure — ' . $response->get_error_message() );
			}
			return new WP_Error(
				'template_remote_failed',
				__( 'Couldn\'t reach the template library right now. Please try again later.', 'neural-forms-lite' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'template_remote_status',
				/* translators: %d: HTTP status code returned by the remote template host. */
				sprintf( __( 'Template host returned HTTP %d.', 'neural-forms-lite' ), $code ),
				array( 'status' => 502 )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'template_remote_json',
				__( 'Template host returned an invalid JSON payload.', 'neural-forms-lite' ),
				array( 'status' => 502 )
			);
		}

		return $json;
	}
}
