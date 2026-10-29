<?php
namespace NeuralForms\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Façade for "Create form with Neural AI". The plugin never holds a
 * provider key — model routing, credit deduction and fallback live in
 * Neural Forms Cloud; this service is the local validation + sanitisation
 * gate over the cloud response. Lite-owned since 2026-10-30 (was Pro):
 * every install with a connected cloud key can generate, the cloud bills
 * the credits.
 */
final class FormGeneratorService {

	const MAX_PROMPT_CHARS = 1500;

	public static function status(): array {
		$snap = self::resolve_status_snapshot();
		return array(
			'configured'      => $snap['cloud_connected'],
			'cloud_connected' => $snap['cloud_connected'],
			'ai_enabled'      => $snap['ai_enabled'],
			'plan'            => $snap['plan'],
			'eligible'        => $snap['eligible'],
			'credits'         => $snap['credits'],
			'limits'          => array(
				'max_prompt_chars' => self::MAX_PROMPT_CHARS,
			),
		);
	}

	public static function debug_status_snapshot(): array {
		$snap = self::resolve_status_snapshot();
		return array(
			'cloud_connected'   => $snap['cloud_connected'],
			'ai_enabled'        => $snap['ai_enabled'],
			'plan'              => $snap['plan'],
			'credits_available' => $snap['credits']['available'],
			'credits_remaining' => $snap['credits']['remaining'],
			'eligible'          => $snap['eligible'],
			'eligible_reasons'  => $snap['eligible_reasons'],
		);
	}

	/** Cloud is authoritative — optimistic defaults when the plan probe fails. */
	private static function resolve_status_snapshot(): array {
		$cloud_connected = CloudFormGeneratorClient::is_connected();
		$ai_enabled      = self::ai_master_toggle_enabled();
		$plan            = '';
		$credits         = array(
			'available' => true,
			'remaining' => null,
		);

		if ( class_exists( '\\NeuralForms\\Plan\\PlanManager' ) ) {
			try {
				$payload = \NeuralForms\Plan\PlanManager::public_payload();
				if ( is_array( $payload ) ) {
					if ( is_array( $payload['plan'] ?? null ) ) {
						$plan = (string) ( $payload['plan']['id'] ?? '' );
					}
					if ( is_array( $payload['balance'] ?? null ) && isset( $payload['balance']['credits'] ) ) {
						$remaining            = (int) $payload['balance']['credits'];
						$credits['remaining'] = $remaining;
						$credits['available'] = $remaining > 0;
					}
				}
			} catch ( \Throwable $e ) {
				// keep optimistic defaults
			}
		}

		$reasons = array();
		if ( ! $cloud_connected ) {
			$reasons[] = 'cloud_not_connected';
		}
		if ( ! $ai_enabled ) {
			$reasons[] = 'ai_master_toggle_off';
		}
		if ( ! $credits['available'] ) {
			$reasons[] = 'insufficient_credits';
		}

		return array(
			'cloud_connected'  => $cloud_connected,
			'ai_enabled'       => $ai_enabled,
			'plan'             => $plan,
			'credits'          => $credits,
			'eligible'         => $cloud_connected && $ai_enabled && $credits['available'],
			'eligible_reasons' => $reasons,
		);
	}

	private static function ai_master_toggle_enabled(): bool {
		if ( ! class_exists( '\\NeuralForms\\Core\\Config' ) ) {
			return true;
		}
		try {
			return (bool) \NeuralForms\Core\Config::get( 'cloud_ai_enabled', true );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/** True when the builder UX should render the working state. */
	public static function is_configured(): bool {
		return CloudFormGeneratorClient::is_connected();
	}

	/**
	 * Generate a form schema from a prompt by routing through the Cloud.
	 *
	 * @return array|WP_Error On success: { form, warnings, request_id, usage }
	 */
	public static function generate( string $prompt ) {
		$prompt = trim( $prompt );
		if ( $prompt === '' ) {
			return new WP_Error( 'invalid_prompt', __( 'Prompt is empty.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}
		if ( strlen( $prompt ) > self::MAX_PROMPT_CHARS ) {
			return new WP_Error(
				'invalid_prompt',
				sprintf(
					/* translators: %d: prompt char cap */
					__( 'Prompt exceeds the %d character limit.', 'neural-forms-lite' ),
					self::MAX_PROMPT_CHARS
				),
				array( 'status' => 400 )
			);
		}

		if ( ! CloudFormGeneratorClient::is_connected() ) {
			return new WP_Error(
				'cloud_not_connected',
				__( 'Connect Neural Forms Cloud to use Neural AI generation.', 'neural-forms-lite' ),
				array( 'status' => 409 )
			);
		}
		if ( ! self::ai_master_toggle_enabled() ) {
			return new WP_Error(
				'ai_disabled',
				__( 'Neural Forms Cloud AI is disabled in Neural Forms settings. Enable it to use Neural AI generation.', 'neural-forms-lite' ),
				array( 'status' => 409 )
			);
		}

		$cloud = CloudFormGeneratorClient::generate( $prompt );
		if ( is_wp_error( $cloud ) ) {
			return $cloud;
		}

		// Defence-in-depth: re-normalise the cloud response so the plugin's
		// own field-type whitelist + FormValidator stay the final authority.
		$normalized = SchemaNormalizer::normalize(
			is_array( $cloud['form'] ) ? self::flatten_form_for_normalizer( $cloud['form'] ) : array()
		);
		if ( null !== $normalized['error'] ) {
			return new WP_Error(
				'ai_schema_invalid',
				$normalized['error'],
				array(
					'status'     => 422,
					'warnings'   => array_merge( $cloud['warnings'], $normalized['warnings'] ),
					'request_id' => $cloud['request_id'] ?? '',
				)
			);
		}

		return array(
			'form'       => $normalized['form'],
			'warnings'   => array_merge( $cloud['warnings'], $normalized['warnings'] ),
			'request_id' => $cloud['request_id'] ?? '',
			'usage'      => $cloud['usage'] ?? array(),
		);
	}

	/**
	 * SchemaNormalizer expects the AI's raw shape `{ name, description,
	 * fields, settings }`; the cloud returns `{ name, description,
	 * form_schema: { fields, settings } }`.
	 */
	private static function flatten_form_for_normalizer( array $form ): array {
		$schema = is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : array();
		return array(
			'name'        => $form['name'] ?? '',
			'description' => $form['description'] ?? '',
			'fields'      => is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array(),
			'settings'    => is_array( $schema['settings'] ?? null ) ? $schema['settings'] : array(),
		);
	}
}
