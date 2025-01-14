<?php
/**
 * MainWP HTML Regression
 *
 * MainWP HTML Regression extension handler.
 * Extension URL: https://mainwp.com/extension/html-regression/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_HTML_Regression
 *
 * MainWP HTML Regression extension handler.
 */
class MainWP_Child_HTML_Regression { //phpcs:ignore -- NOSONAR - multi methods.

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Public variable to hold the information if the HTML Regression plugin is installed on the child site.
	 *
	 * @var bool If HTML Regression installed, return true, if not, return false.
	 */
	public $is_plugin_installed = true;

	/**
	 * Public variable to hold the information about the language domain.
	 *
	 * @var string 'mainwp-child' languge domain.
	 */
	public $plugin_translate = 'mainwp-child';

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new self();
		}

		return static::$instance;
	}

	/**
	 * MainWP_Child_HTML_Regression constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
	}

	/**
	 * Method init()
	 *
	 * Initiate action hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_plugin_installed ) {
			return;
		}

		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
	}

	/**
	 * Method sync_others_data()
	 *
	 * Sync the HTML Regression plugin settings.
	 *
	 * @param  array $information Array containing the sync information.
	 * @param  array $data        Array containing the HTML Regression plugin data to be synced.
	 *
	 * @return array $information Array containing the sync information.
	 */
	public function sync_others_data( $information, $data = array() ) {

		if ( isset( $data['sync_html_regression_data'] ) && ( 'yes' === $data['sync_html_regression_data'] ) ) {
			try {
				$data                                     = array(
					'files' => $this->get_active_assets(),
				);
				$information['sync_html_regression_data'] = $data;
			} catch ( MainWP_Exception $e ) {
				// ok!
			}
		}
		return $information;
	}

	/**
	 * Method get_active_assets()
	 * Get plugin and theme files
	 */
	public function get_active_assets() {
		$result = array(
			'plugins' => array(),
			'theme'   => array(),
		);

		// Get a list of active plugins.
		$active_plugins = get_option( 'active_plugins', array() );
		$plugin_dir     = WP_PLUGIN_DIR;

		foreach ( $active_plugins as $plugin ) {
			$plugin_path                             = $plugin_dir . '/' . dirname( $plugin );
			$result['plugins'][ dirname( $plugin ) ] = $this->get_css_js_files( $plugin_path );
		}

		// Get a list of CSS and JS files of the currently selected theme.
		$theme                                    = wp_get_theme();
		$theme_dir                                = get_template_directory();
		$result['theme'][ $theme->get( 'Name' ) ] = $this->get_css_js_files( $theme_dir );

		return $result;
	}

	/**
	 * Method get_css_js_files()
	 *
	 * Get css and js files from folder.
	 *
	 * @param string $directory directory path.
	 *
	 * @uses RecursiveDirectoryIterator
	 * @uses RecursiveIteratorIterator
	 * @return array
	 */
	public function get_css_js_files( $directory ) {
		$files = array();

		// Check if directory exists.
		if ( ! is_dir( $directory ) ) {
			return $files;
		}

		// Browse all files in the directory.
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) );

		foreach ( $iterator as $file ) {
			// Get the file extension.
			$extension = pathinfo( $file, PATHINFO_EXTENSION );

			// Check if it is a .css or .js file.
			if ( in_array( $extension, array( 'css', 'js' ), true ) ) {
				// Save file path.
				$files[] = str_replace( ABSPATH, '', $file->getPathname() );
			}
		}
		// Rest value files.
		return array_values( $files );
	}
}
