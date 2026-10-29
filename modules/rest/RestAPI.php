<?php
namespace NeuralForms\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Boots all REST endpoints by hooking into rest_api_init.
 *
 * Adding a new endpoint in a future step = one new line in register_routes().
 * Nothing else needs to change.
 */
class RestAPI {

	const NAMESPACE = 'neural-forms/v1';

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		( new Endpoints\FormsEndpoint() )->register_routes();
		( new Endpoints\PreviewEndpoint() )->register_routes();
		( new Endpoints\SubmitEndpoint() )->register_routes();
		( new Endpoints\EntriesEndpoint() )->register_routes();
		( new Endpoints\ExportEndpoint() )->register_routes();
		( new Endpoints\ReplyEndpoint() )->register_routes();
		( new Endpoints\RetryEndpoint() )->register_routes();
		( new Endpoints\MessagesEndpoint() )->register_routes();
		( new Endpoints\SettingsEndpoint() )->register_routes();
		( new Endpoints\ProcessQueueEndpoint() )->register_routes();
		( new Endpoints\StatsEndpoint() )->register_routes();
		( new Endpoints\MigrationEndpoint() )->register_routes();
		( new Endpoints\PollsEndpoint() )->register_routes();
		( new Endpoints\TemplatesEndpoint() )->register_routes();
		( new Endpoints\WorkflowsEndpoint() )->register_routes();
	}
}
