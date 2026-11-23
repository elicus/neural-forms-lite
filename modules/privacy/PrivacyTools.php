<?php
namespace NeuralForms\Privacy;

use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Storage\AIResponseRepository;
use NeuralForms\Storage\EntryMessageRepository;
use NeuralForms\Storage\EntryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress privacy-tool integration.
 *
 *  - Tools > Export Personal Data: lists every entry whose submitted data
 *    contains the requested email address (all stored fields + visitor
 *    metadata when present).
 *  - Tools > Erase Personal Data: hard-deletes those entries together with
 *    their message threads and AI response drafts.
 *  - Settings > Privacy > Policy Guide: suggested policy text.
 *
 * Matching is by exact email in the submitted data (see
 * EntryRepository::data_contains_email()). Entries that never captured
 * an email cannot be attributed to a person and are not touched.
 */
class PrivacyTools {

	const GROUP_ID  = 'neural-forms-entries';
	const PER_PAGE  = 50;

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['neural-forms-lite'] = array(
			'exporter_friendly_name' => __( 'Neural Forms submissions', 'neural-forms-lite' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['neural-forms-lite'] = array(
			'eraser_friendly_name' => __( 'Neural Forms submissions', 'neural-forms-lite' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter callback. WordPress calls this with increasing $page until
	 * 'done' is true.
	 *
	 * @param string $email
	 * @param int    $page
	 * @return array{ data: array, done: bool }
	 */
	public static function export( string $email, int $page = 1 ): array {
		$page    = max( 1, $page );
		$entries = EntryRepository::find_by_email( $email, self::PER_PAGE, $page );
		$export  = array();
		$labels  = array();
		$names   = array();

		foreach ( $entries as $entry ) {
			$form_id = (int) ( $entry['form_id'] ?? 0 );
			if ( ! isset( $labels[ $form_id ] ) ) {
				$form              = $form_id > 0 ? FormRepository::find( $form_id ) : null;
				$names[ $form_id ] = (string) ( $form['name'] ?? sprintf( '#%d', $form_id ) );
				$labels[ $form_id ] = self::field_labels( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() );
			}

			$data = array(
				array( 'name' => __( 'Form', 'neural-forms-lite' ),         'value' => $names[ $form_id ] ),
				array( 'name' => __( 'Submitted at', 'neural-forms-lite' ), 'value' => (string) ( $entry['submitted_at'] ?? $entry['created_at'] ?? '' ) ),
			);
			foreach ( (array) ( $entry['data'] ?? array() ) as $field_id => $value ) {
				$data[] = array(
					'name'  => $labels[ $form_id ][ $field_id ] ?? (string) $field_id,
					'value' => self::stringify( $value ),
				);
			}
			foreach ( array( 'ip_address' => __( 'IP address', 'neural-forms-lite' ), 'user_agent' => __( 'Browser (user agent)', 'neural-forms-lite' ), 'page_url' => __( 'Page URL', 'neural-forms-lite' ) ) as $col => $label ) {
				if ( ! empty( $entry[ $col ] ) ) {
					$data[] = array( 'name' => $label, 'value' => (string) $entry[ $col ] );
				}
			}

			$export[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Form submissions', 'neural-forms-lite' ),
				'item_id'     => 'neural-forms-entry-' . (int) $entry['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $export,
			'done' => count( $entries ) < self::PER_PAGE,
		);
	}

	/**
	 * Eraser callback. Always reads page 1 because deleted rows no longer
	 * exist — WordPress keeps calling until 'done' is true.
	 *
	 * @param string $email
	 * @param int    $page
	 * @return array{ items_removed: int, items_retained: int, messages: string[], done: bool }
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$entries  = EntryRepository::find_by_email( $email, self::PER_PAGE, 1 );
		$removed  = 0;
		$retained = 0;

		foreach ( $entries as $entry ) {
			$id = (int) $entry['id'];
			// Uploaded files are personal data too: delete the media-library
			// attachments referenced by the entry (file fields, repeater
			// children) before the row goes.
			self::delete_attachments( is_array( $entry['data'] ?? null ) ? $entry['data'] : array() );
			EntryMessageRepository::delete_by_entry( $id );
			AIResponseRepository::delete_by_entry( $id );
			$retained += self::erase_commerce( $id );
			if ( EntryRepository::delete( $id ) ) {
				$removed++;
				// The timeline names the person (replies, invoices sent to …).
				if ( class_exists( '\\NeuralForms\\Storage\\EntryActivity' ) ) {
					\NeuralForms\Storage\EntryActivity::delete_for_entry( $id );
				}
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained > 0
				? array( __( 'Payment records for this person were kept as financial records, with their name and email removed.', 'neural-forms-lite' ) )
				: array(),
			'done'           => count( $entries ) < self::PER_PAGE,
		);
	}

	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for sites that collect form submissions with Neural Forms. Adjust it to match your settings (Privacy mode, AI analysis, notifications).', 'neural-forms-lite' ) . '</p>'
			. '<p>' . esc_html__( 'When you submit a form on this site, we store the information you enter so that we can respond to your enquiry. Unless Privacy mode is enabled, we also store your IP address and browser user agent with the submission for spam prevention and security. Submissions may be forwarded by email to site administrators. If AI analysis is enabled, the content of your submission is processed by the Neural Forms service to summarise it and draft a reply. Submissions are retained until deleted by a site administrator. You can request a copy or deletion of your submissions by contacting us.', 'neural-forms-lite' ) . '</p>';
		wp_add_privacy_policy_content( 'Neural Forms Lite', wp_kses_post( $content ) );
	}

	// ── helpers ──

	/**
	 * Quotes and payments of an entry that is being erased (1.2.0).
	 *
	 * Open checkouts are closed first so no payment can arrive for a person
	 * who asked to be forgotten. An entry with a confirmed payment keeps its
	 * quotes and payment attempts as the financial record, with the
	 * customer's details blanked and the public quote links revoked;
	 * otherwise both are deleted outright, like an admin delete.
	 *
	 * @return int Number of financial rows kept (anonymised).
	 */
	private static function erase_commerce( int $entry_id ): int {
		$has_payments = class_exists( '\\NeuralForms\\Payments\\PaymentRepository' );
		$has_quotes   = class_exists( '\\NeuralForms\\Quotes\\QuoteRepository' );
		$settled      = false;

		if ( $has_payments ) {
			foreach ( \NeuralForms\Payments\PaymentRepository::list_for_entry( $entry_id ) as $attempt ) {
				if ( in_array( $attempt['status'], \NeuralForms\Payments\PaymentStatus::SETTLED, true ) ) {
					$settled = true;
					continue;
				}
				if ( $attempt['status'] === 'pending' && \NeuralForms\Payments\PaymentRepository::update_if_status( (int) $attempt['id'], 'pending', array( 'status' => 'replaced' ) ) ) {
					$provider = class_exists( '\\NeuralForms\\Payments\\PaymentProviderRegistry' ) ? \NeuralForms\Payments\PaymentProviderRegistry::get( (string) $attempt['provider'] ) : null;
					if ( $provider && ! empty( $attempt['session_id'] ) ) {
						try {
							$provider->expire_checkout( $attempt );
						} catch ( \Throwable $e ) {
							unset( $e ); // The gateway call is best effort; the attempt is already closed locally.
						}
					}
				}
			}
		}

		if ( ! $settled ) {
			if ( $has_quotes ) {
				\NeuralForms\Quotes\QuoteRepository::delete_for_entry( $entry_id );
			}
			if ( $has_payments ) {
				\NeuralForms\Payments\PaymentRepository::delete_for_entry( $entry_id );
			}
			return 0;
		}

		$kept = \NeuralForms\Payments\PaymentRepository::anonymise_for_entry( $entry_id );
		if ( $has_quotes ) {
			$kept += \NeuralForms\Quotes\QuoteRepository::anonymise_for_entry( $entry_id );
		}
		return $kept;
	}

	/** Walk entry data and hard-delete every referenced attachment. */
	private static function delete_attachments( array $data ): void {
		if ( ! function_exists( 'wp_delete_attachment' ) ) {
			return;
		}
		$stack = array( $data );
		while ( $stack ) {
			$node = array_pop( $stack );
			if ( isset( $node['attachment_id'] ) && is_numeric( $node['attachment_id'] ) ) {
				wp_delete_attachment( (int) $node['attachment_id'], true );
				continue;
			}
			foreach ( $node as $v ) {
				if ( is_array( $v ) ) {
					$stack[] = $v;
				}
			}
		}
	}

	/** @return array<string,string> field id → label */
	private static function field_labels( array $fields ): array {
		$out = array();
		foreach ( SchemaHelper::flatten_fields( $fields ) as $f ) {
			$f = (array) $f;
			if ( isset( $f['id'] ) ) {
				$label = trim( (string) ( $f['label'] ?? '' ) );
				$out[ (string) $f['id'] ] = $label !== '' ? $label : (string) $f['id'];
			}
		}
		return $out;
	}

	private static function stringify( $value ): string {
		if ( is_array( $value ) ) {
			// File uploads carry { url, filename, ... }; show the URL.
			if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
				return $value['url'];
			}
			$scalars = array_filter( $value, 'is_scalar' );
			if ( count( $scalars ) === count( $value ) ) {
				return implode( ', ', array_map( 'strval', $scalars ) );
			}
			return (string) wp_json_encode( $value );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
