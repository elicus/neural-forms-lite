<?php
defined( 'ABSPATH' ) || exit;

/**
 * Maps Neural Forms namespaces to file paths.
 *
 * Rules:
 *   NeuralForms\{Class}              '->-> includes/class-{lowercase}.php
 *   NeuralForms\{Module}\{Class}     '->-> modules/{lowercase-module}/{Class}.php
 *   NeuralForms\{Module}\{Sub}\{Class} '->-> modules/{lowercase-module}/{Sub}/{Class}.php
 *
 * Examples:
 *   NeuralForms\Activator               '->-> includes/class-activator.php
 *   NeuralForms\Forms\FormRepository    '->-> modules/forms/FormRepository.php
 *   NeuralForms\AI\Providers\OpenAI     '->-> modules/ai/Providers/OpenAI.php
 */
class NeuralForms_Autoloader {

	private const NAMESPACE_PREFIX = 'NeuralForms\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class ): void {
		// Bail if not our namespace.
		if ( strpos( $class, self::NAMESPACE_PREFIX ) !== 0 ) {
			return;
		}

		// Strip the top-level prefix '->-> e.g. "Forms\FormRepository"
		$relative = substr( $class, strlen( self::NAMESPACE_PREFIX ) );
		$parts    = explode( '\\', $relative );

		if ( count( $parts ) === 1 ) {
			// Root-level class: lives in includes/ with WP file naming.
			$file = NEURAL_FORMS_PATH . 'includes/class-' . strtolower( $parts[0] ) . '.php';
		} else {
			// Module class: first segment is the module directory (lowercase).
			// Remaining segments become the path as-is (preserving case).
			$module     = strtolower( array_shift( $parts ) );
			$class_path = implode( DIRECTORY_SEPARATOR, $parts ) . '.php';
			$file       = NEURAL_FORMS_PATH . 'modules/' . $module . '/' . $class_path;
		}

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
