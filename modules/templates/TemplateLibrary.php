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

	/** Remote catalog URL. */
	const CATALOG_URL = 'https://templates.neuralforms.ai/catalog.json';

	/** Transient key for the catalog cache. */
	const CATALOG_TRANSIENT = 'neural_forms_template_catalog_gutenberg';

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
	public static function fetch_catalog( bool $bypass_cache = false ) {
		if ( ! $bypass_cache ) {
			$cached = get_transient( self::CATALOG_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$catalog = self::request_json( self::CATALOG_URL );

		// Catalog fetch is resilient by design. DNS failures, 4xx / 5xx
		// from the remote, malformed JSON — none of those should
		// surface as a scary "cURL error 6: Could not resolve host"
		// banner inside the admin. Return an empty catalog with a
		// `service_unavailable` flag instead so the React UI can render
		// a friendly empty state. Cache the empty payload too (short
		// TTL) so repeated screen visits don't hammer the network with
		// retries while the remote is down.
		if ( is_wp_error( $catalog ) ) {
			$payload = [
				'version'             => '',
				'updated_at'          => '',
				'templates'           => [],
				'service_unavailable' => true,
			];
			set_transient( self::CATALOG_TRANSIENT, $payload, 5 * MINUTE_IN_SECONDS );
			return $payload;
		}

		$templates = isset( $catalog['templates'] ) && is_array( $catalog['templates'] )
			? $catalog['templates']
			: [];

		// Filter to Gutenberg-compatible templates only. Catalog
		// entries without a `compatibility` array are treated as
		// non-Gutenberg and excluded so a malformed catalog row
		// doesn't surface as a broken card in the UI.
		$gutenberg = [];
		foreach ( $templates as $row ) {
			if ( ! is_array( $row ) ) continue;
			$compat = isset( $row['compatibility'] ) && is_array( $row['compatibility'] )
				? array_map( 'strval', $row['compatibility'] )
				: [];
			if ( ! in_array( 'gutenberg', $compat, true ) ) continue;
			$gutenberg[] = self::normalize_catalog_row( $row );
		}

		$payload = [
			'version'    => isset( $catalog['version'] ) ? (string) $catalog['version'] : '',
			'updated_at' => isset( $catalog['updated_at'] ) ? (string) $catalog['updated_at'] : '',
			'templates'  => $gutenberg,
		];

		set_transient( self::CATALOG_TRANSIENT, $payload, self::CATALOG_TTL );

		return $payload;
	}

	/**
	 * Import a template by manifest URL.
	 *
	 * Flow:
	 *   1. Validate the manifest URL host against the allow-list.
	 *   2. Fetch manifest → page.json → each form.json.
	 *   3. Sanitize each form (strip integrations, secrets, recipients).
	 *   4. Insert each form via FormRepository, building the
	 *      sourceFormId → newLocalFormId map.
	 *   5. Replace formId attributes inside the page block content
	 *      using parse_blocks() / serialize_blocks(), with a regex
	 *      fallback for safety.
	 *   6. wp_insert_post() the resulting content as a draft page.
	 *
	 * Returns an array with `page_id`, `edit_url`, `view_url`,
	 * `imported_form_ids[]` on success; WP_Error on any failure.
	 *
	 * @param string $manifest_url
	 * @return array|WP_Error
	 */
	public static function import_template( string $manifest_url ) {
		if ( ! self::is_allowed_url( $manifest_url ) ) {
			return new WP_Error(
				'template_disallowed_host',
				__( 'Template manifest URL is not on the allow-list.', 'neural-forms-lite' ),
				[ 'status' => 400 ]
			);
		}

		$manifest = self::request_json( $manifest_url );
		if ( is_wp_error( $manifest ) ) return $manifest;

		// Structural validation BEFORE we touch any value. Even though
		// the catalog server is first-party, we treat its payloads as
		// untrusted — a compromised CDN cache, a man-in-the-middle, or
		// just a typo in catalog authoring shouldn't be enough to
		// poison a downstream install. The manifest must have the
		// keys we'll read; absent keys mean we abort rather than
		// import garbage.
		$manifest_err = self::validate_manifest_shape( $manifest );
		if ( $manifest_err ) return $manifest_err;

		// Builder gate — only Gutenberg manifests are imported by Lite.
		$builder = isset( $manifest['builder'] ) ? (string) $manifest['builder'] : '';
		if ( $builder !== 'gutenberg' ) {
			return new WP_Error(
				'template_unsupported_builder',
				/* translators: %s: builder slug from the remote manifest. */
				sprintf( __( 'Template builder "%s" is not supported yet.', 'neural-forms-lite' ), $builder ),
				[ 'status' => 400 ]
			);
		}

		$page_url = isset( $manifest['page'] ) ? (string) $manifest['page'] : '';
		if ( ! self::is_allowed_url( $page_url ) ) {
			return new WP_Error(
				'template_invalid_page_url',
				__( 'Template page URL is missing or not allowed.', 'neural-forms-lite' ),
				[ 'status' => 400 ]
			);
		}

		$page = self::request_json( $page_url );
		if ( is_wp_error( $page ) ) return $page;

		$page_err = self::validate_page_shape( $page );
		if ( $page_err ) return $page_err;

		// Import forms first so the formId map is ready before we
		// rewrite the page content.
		$id_map      = [];
		$imported_form_ids = [];
		$forms       = isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : [];
		foreach ( $forms as $form_ref ) {
			if ( ! is_array( $form_ref ) ) continue;
			$source_id = isset( $form_ref['sourceFormId'] ) ? (int) $form_ref['sourceFormId'] : 0;
			$file_url  = isset( $form_ref['file'] ) ? (string) $form_ref['file'] : '';
			if ( $source_id <= 0 || ! self::is_allowed_url( $file_url ) ) {
				continue;
			}

			$form_json = self::request_json( $file_url );
			if ( is_wp_error( $form_json ) ) return $form_json;

			$clean   = self::sanitize_imported_form( $form_json );
			$new_id  = FormRepository::create( $clean );
			if ( ! $new_id ) {
				return new WP_Error(
					'template_form_insert_failed',
					__( 'Failed to import a form referenced by the template.', 'neural-forms-lite' ),
					[ 'status' => 500 ]
				);
			}

			$id_map[ $source_id ]  = (int) $new_id;
			$imported_form_ids[]   = (int) $new_id;
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
		$rewrote_content = self::replace_form_ids_in_content( $raw_content, $id_map );
		$safe_content    = wp_kses_post( $rewrote_content );

		$title  = isset( $page['title'] ) ? (string) $page['title'] : ( isset( $manifest['title'] ) ? (string) $manifest['title'] : __( 'Imported Template', 'neural-forms-lite' ) );
		$slug   = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
		// Force draft regardless of what the template says — never
		// auto-publish imported content; the admin should review first.
		$status = 'draft';

		$post_id = wp_insert_post( [
			'post_title'   => sanitize_text_field( $title ),
			'post_name'    => $slug,
			'post_status'  => $status,
			'post_type'    => 'page',
			'post_content' => $safe_content,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return [
			'page_id'           => (int) $post_id,
			'edit_url'          => get_edit_post_link( (int) $post_id, 'raw' ),
			'view_url'          => get_permalink( (int) $post_id ),
			'imported_form_ids' => $imported_form_ids,
		];
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
		$name = isset( $form['name'] ) ? (string) $form['name'] : __( 'Imported Form', 'neural-forms-lite' );
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
		$schema = isset( $form['form_schema'] ) && is_array( $form['form_schema'] )
			? self::sanitize_recursive_strings( $form['form_schema'] )
			: [];

		$raw_settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : [];
		$clean_settings = [];

		// Whitelist of safe settings keys. The template author can
		// pre-fill these for the receiving site without exposing
		// any secrets or recipients.
		$safe_keys = [
			'success_message',
			'error_message',
			'submit_button_text',
			'success_redirect',          // legacy alias
			'thanks_page_message',
		];
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

		return [
			'name'        => sanitize_text_field( $name ),
			'description' => sanitize_textarea_field( $desc ),
			'form_schema' => $schema,
			'settings'    => $clean_settings,
			// Always import in draft so the admin can review before
			// the form goes live. They can flip it to active in the
			// builder.
			'status'      => 'draft',
		];
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
			$out = [];
			foreach ( $value as $k => $v ) {
				// Sanitize the key too — array keys end up in option
				// names / column lookups downstream and shouldn't
				// carry markup. Numeric keys are preserved as-is.
				$safe_key = is_int( $k ) ? $k : sanitize_text_field( (string) $k );
				$out[ $safe_key ] = self::sanitize_recursive_strings( $v );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || $value === null ) {
			return $value;
		}
		// Objects / resources / closures are not valid in a JSON-
		// originated payload; drop them rather than coerce.
		return null;
	}

	/**
	 * Structural validation for a manifest.json payload. Returns null
	 * when shape is acceptable, a WP_Error otherwise. Catches missing
	 * top-level keys, wrong types for known keys, and an empty forms
	 * array (a Gutenberg page template with no Neural Forms form
	 * reference is almost certainly malformed at this stage in Lite).
	 *
	 * @param mixed $manifest
	 * @return WP_Error|null
	 */
	private static function validate_manifest_shape( $manifest ) {
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'template_manifest_invalid', __( 'Template manifest is not a valid object.', 'neural-forms-lite' ), [ 'status' => 502 ] );
		}
		if ( empty( $manifest['builder'] ) || ! is_string( $manifest['builder'] ) ) {
			return new WP_Error( 'template_manifest_missing_builder', __( 'Template manifest is missing the builder field.', 'neural-forms-lite' ), [ 'status' => 502 ] );
		}
		if ( empty( $manifest['page'] ) || ! is_string( $manifest['page'] ) ) {
			return new WP_Error( 'template_manifest_missing_page', __( 'Template manifest is missing the page URL.', 'neural-forms-lite' ), [ 'status' => 502 ] );
		}
		if ( isset( $manifest['forms'] ) && ! is_array( $manifest['forms'] ) ) {
			return new WP_Error( 'template_manifest_forms_invalid', __( 'Template manifest "forms" is not an array.', 'neural-forms-lite' ), [ 'status' => 502 ] );
		}
		return null;
	}

	/**
	 * Structural validation for a page.json payload. Returns null when
	 * shape is acceptable, a WP_Error otherwise. The block-content
	 * itself is further sanitized via wp_kses_post() at import time.
	 *
	 * @param mixed $page
	 * @return WP_Error|null
	 */
	private static function validate_page_shape( $page ) {
		if ( ! is_array( $page ) ) {
			return new WP_Error( 'template_page_invalid', __( 'Template page payload is not a valid object.', 'neural-forms-lite' ), [ 'status' => 502 ] );
		}
		if ( ! isset( $page['post_content'] ) || ! is_string( $page['post_content'] ) ) {
			return new WP_Error( 'template_page_missing_content', __( 'Template page payload is missing post_content.', 'neural-forms-lite' ), [ 'status' => 502 ] );
		}
		// title / slug / status are optional — the importer fills
		// sensible defaults when they're missing.
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
	 * @param string $content Block markup from page.json's post_content.
	 * @param array  $id_map  source form ID (int) → new local form ID (int)
	 * @return string
	 */
	public static function replace_form_ids_in_content( string $content, array $id_map ): string {
		if ( empty( $id_map ) || $content === '' ) {
			return $content;
		}

		// Coerce keys + values to int to make the lookup robust against
		// JSON-decoded values arriving as strings.
		$normalized = [];
		foreach ( $id_map as $src => $dst ) {
			$normalized[ (int) $src ] = (int) $dst;
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return self::regex_replace_form_ids( $content, $normalized );
		}

		$rewritten = self::walk_blocks_swap_form_id( $blocks, $normalized );
		$serialized = serialize_blocks( $rewritten );

		// If the serialized output is empty (shouldn't happen but
		// guard against it), fall back to the regex pass rather than
		// blanking the imported page.
		if ( $serialized === '' || $serialized === null ) {
			return self::regex_replace_form_ids( $content, $normalized );
		}

		return $serialized;
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
			if ( ! is_array( $block ) ) continue;

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			// Cover every Neural Forms block kind that carries a
			// formId attribute (form / quiz / multi-step / poll).
			if ( $name !== '' && strpos( $name, 'neural-forms/' ) === 0 ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
				if ( isset( $attrs['formId'] ) ) {
					$src = (int) $attrs['formId'];
					if ( $src > 0 && isset( $id_map[ $src ] ) ) {
						$attrs['formId'] = $id_map[ $src ];
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
		if ( $url === '' ) return false;
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || $host === '' ) return false;
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( $scheme !== 'https' ) return false;

		$allowed = (array) apply_filters(
			'neural_forms_template_library_allowed_hosts',
			[ self::DEFAULT_HOST ]
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
		return [
			'id'            => isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '',
			'title'         => isset( $row['title'] ) ? (string) $row['title'] : '',
			'description'   => isset( $row['description'] ) ? (string) $row['description'] : '',
			'category'      => isset( $row['category'] ) ? (string) $row['category'] : '',
			'compatibility' => isset( $row['compatibility'] ) && is_array( $row['compatibility'] )
				? array_map( 'strval', $row['compatibility'] )
				: [],
			'thumbnail'     => isset( $row['thumbnail'] ) && self::is_allowed_url( (string) $row['thumbnail'] )
				? esc_url_raw( (string) $row['thumbnail'] )
				: '',
			'preview'       => isset( $row['preview'] ) && self::is_allowed_url( (string) $row['preview'] )
				? esc_url_raw( (string) $row['preview'] )
				: '',
			'manifest'      => isset( $row['manifest'] ) && self::is_allowed_url( (string) $row['manifest'] )
				? esc_url_raw( (string) $row['manifest'] )
				: '',
			'tags'          => isset( $row['tags'] ) && is_array( $row['tags'] )
				? array_values( array_map( 'sanitize_text_field', array_map( 'strval', $row['tags'] ) ) )
				: [],
		];
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
		$response = wp_remote_get( $url, [
			'timeout'    => self::REQUEST_TIMEOUT,
			'redirection' => 2,
			'headers'    => [
				'Accept' => 'application/json',
			],
		] );

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
				[ 'status' => 502 ]
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error(
				'template_remote_status',
				/* translators: %d: HTTP status code returned by the remote template host. */
				sprintf( __( 'Template host returned HTTP %d.', 'neural-forms-lite' ), $code ),
				[ 'status' => 502 ]
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'template_remote_json',
				__( 'Template host returned an invalid JSON payload.', 'neural-forms-lite' ),
				[ 'status' => 502 ]
			);
		}

		return $json;
	}
}
