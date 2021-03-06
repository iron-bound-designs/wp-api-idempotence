<?php
/**
 * Main plugin class.
 *
 * @author      Iron Bound Designs
 * @since       1.0
 * @copyright   2017 (c) Iron Bound Designs.
 * @license     GPLv2
 */

namespace IronBound\WP_API_Idempotence;

use BrightNucleus\Dependency\DependencyManagerInterface;
use IronBound\WP_API_Idempotence\Admin\Dispatcher;
use IronBound\WP_API_Idempotence\DataStore\Installable;

/**
 * Class Plugin
 *
 * @package IronBound\WP_API_Idempotence
 */
class Plugin {

	/** @var Middleware */
	private $middleware;

	/** @var Dispatcher */
	private $dispatcher;

	/** @var DependencyManagerInterface */
	private $dependency_manager;

	/** @var Config */
	private $config;

	/**
	 * Plugin constructor.
	 *
	 * @param Middleware                 $middleware
	 * @param Dispatcher                 $dispatcher
	 * @param DependencyManagerInterface $dependency_manager
	 * @param Config                     $config
	 */
	public function __construct( Middleware $middleware, Dispatcher $dispatcher, DependencyManagerInterface $dependency_manager, Config $config ) {
		$this->middleware         = $middleware;
		$this->dispatcher         = $dispatcher;
		$this->dependency_manager = $dependency_manager;
		$this->config             = $config;
	}

	/**
	 * Called when the plugin is activated.
	 *
	 * Registers cron events and installs any data stores.
	 *
	 * @since 1.0.0
	 */
	public function activate() {

		wp_schedule_event( time(), 'daily', 'wp_api_idempotence_flush_logs' );

		if ( $this->middleware->get_data_store() instanceof Installable ) {
			$this->middleware->get_data_store()->install();
		}
	}

	/**
	 * Initialize the plugin.
	 *
	 * Registers actions and filters.
	 *
	 * @since 1.0.0
	 */
	public function initialize() {
		add_action( 'init', [ $this->dependency_manager, 'register' ] );
		add_filter( 'option_wp_api_idempotence', [ $this, 'apply_default_settings' ] );
		add_filter( 'default_option_wp_api_idempotence', [ $this, 'apply_default_settings' ] );
		add_action( 'update_option_wp_api_idempotence', [ $this, 'update_config_on_settings_updated' ], 10, 2 );

		$this->middleware->initialize();

		if ( is_admin() ) {
			add_action( 'init', [ $this, 'register_views' ] );
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			add_action( 'wp_api_idempotence_flush_logs', [ $this, 'flush_logs' ] );
		}
	}

	/**
	 * Filter the get_option call to include the configuration defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function apply_default_settings( $settings = [] ) {
		return wp_parse_args( is_array( $settings ) ? $settings : [], [
			'key_location'           => 'header',
			'key_name'               => 'WP-Idempotency-Key',
			'applicable_methods'     => [ 'POST', 'PUT', 'PATCH' ],
			'allow_logged_out_users' => false,
		] );
	}

	/**
	 * Update the config instance when the settings are saved.
	 *
	 * @since 1.0.0
	 *
	 * @param array $_
	 * @param array $settings
	 */
	public function update_config_on_settings_updated( $_, $settings ) {
		$this->config->set_settings( $settings );
	}

	/**
	 * Register the views with the dispatcher.
	 *
	 * @since    1.0.0
	 *
	 * @internal Init action callback.
	 */
	public function register_views() {
		$this->dispatcher->register_views( include __DIR__ . '/../views.php' );
	}

	/**
	 * Flush idempotent request logs.
	 *
	 * @since    1.0.0
	 *
	 * @internal CRON action callback.
	 */
	public function flush_logs() {
		$this->middleware->get_data_store()->drop_old();
	}
}