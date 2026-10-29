<?php
/**
 * Plugin Name:       Neural Forms Lite
 * Plugin URI:        https://neuralforms.ai
 * Description:       A modern lead inbox for WordPress. Build forms, triage submissions, and let AI summarize and draft replies for you. Upgrade to Neural Forms Pro for Divi 5, spam detection, and advanced AI tools.
 * Version:           1.2.1
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            Elicus
 * Author URI:        https://elicus.com
 * License:           GPL-2.0-or-later
 * Text Domain:       neural-forms-lite
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// --- Constants -----------------------------------------------------------
//
// NEURAL_FORMS_LITE_VERSION is the canonical Lite version for add-ons
// (e.g. Neural Forms Pro) to check against. NEURAL_FORMS_VERSION is kept
// as an alias so existing internal usages - enqueue versions, X-NeuralForms-Version
// headers, etc. - continue to work without a sweeping rename.

define( 'NEURAL_FORMS_LITE_VERSION', '1.2.1' );
define( 'NEURAL_FORMS_VERSION', NEURAL_FORMS_LITE_VERSION );
define( 'NEURAL_FORMS_DB_VERSION', '1.2.0' );
define( 'NEURAL_FORMS_CAP_MANAGE_FORMS', 'neural_forms_manage_forms' );
define( 'NEURAL_FORMS_FILE', __FILE__ );
define( 'NEURAL_FORMS_PATH', plugin_dir_path( __FILE__ ) );  // trailing slash
define( 'NEURAL_FORMS_URL', plugin_dir_url( __FILE__ ) );   // trailing slash
define( 'NEURAL_FORMS_BASENAME', plugin_basename( __FILE__ ) );

// --- Autoloader ----------------------------------------------------------

require_once NEURAL_FORMS_PATH . 'includes/class-autoloader.php';
NeuralForms_Autoloader::register();

// --- Activation / Deactivation hooks ------------------------------------
// These run before the main class boots so that DB setup happens first.

register_activation_hook( NEURAL_FORMS_FILE, array( 'NeuralForms\Activator', 'activate' ) );
register_deactivation_hook( NEURAL_FORMS_FILE, array( 'NeuralForms\Deactivator', 'deactivate' ) );

// --- Boot ----------------------------------------------------------------

function neural_forms(): NeuralForms\NeuralForms {
	return NeuralForms\NeuralForms::instance();
}

add_action( 'plugins_loaded', 'neural_forms' );
