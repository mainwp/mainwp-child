<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test fixture and adapters keep their consumer-oriented filenames.
/**
 * Disposable filesystem layouts for backup characterization tests.
 *
 * @package Mainwp_Child
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Keep the reusable helper filename alongside its test consumers.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Real local filesystem operations exercise permissions, symlinks and exact fixture bytes.

/**
 * Supplies explicit roots without changing WordPress constants.
 */
class MainWP_External_Layout_Fixture {

	const MTIME = 1700000000;

	/**
	 * Owned scratch directory.
	 *
	 * @var string
	 */
	public $scratch;
	/**
	 * Number of layouts built by this fixture owner.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Create an owner for disposable layout trees.
	 *
	 * @param string|null $base_dir Optional parent for ABSPATH-relative tar fixtures.
	 */
	public function __construct( $base_dir = null ) {
		$base_dir      = null === $base_dir ? sys_get_temp_dir() : $base_dir;
		$this->scratch = rtrim( $base_dir, '/' ) . '/mwp-external-layout-' . uniqid();
		$this->make_directory( $this->scratch );
	}

	/**
	 * Build a named tree with distinct same-named sentinel files.
	 *
	 * @param string $layout Layout name.
	 * @throws InvalidArgumentException When the layout name is unknown.
	 * @throws RuntimeException When creating or seeding a tree fails.
	 * @return array Named roots, fixture directory and sentinel paths.
	 */
	public function build( $layout ) {
		$allowed = array( 'normal', 'sibling-content', 'symlink-content', 'moved-uploads', 'bedrock', 'bedrock-internal-content', 'moved-plugins', 'read-only-core', 'parent-config' );
		if ( ! in_array( $layout, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Unknown external layout fixture.' );
		}
		++$this->sequence;
		$base    = $this->scratch . '/' . $this->sequence . '-' . $layout;
		$site    = $base . '/wp';
		$content = $site . '/wp-content';
		$config  = $site . '/wp-config.php';
		if ( in_array( $layout, array( 'sibling-content', 'read-only-core' ), true ) ) {
			$content = $base . '/content';
		} elseif ( in_array( $layout, array( 'bedrock', 'bedrock-internal-content' ), true ) ) {
			$site    = $base . '/project/web/wp';
			$content = 'bedrock' === $layout ? $base . '/project/web/app' : $site . '/wp-content';
			$config  = $base . '/project/web/wp-config.php';
		} elseif ( 'parent-config' === $layout ) {
			$site    = $base . '/public';
			$content = $site . '/wp-content';
			$config  = $base . '/wp-config.php';
		}
		$paths = array(
			'base'       => $base,
			'site'       => $site,
			'content'    => $content,
			'uploads'    => 'moved-uploads' === $layout ? $base . '/uploads' : $content . '/uploads',
			'plugins'    => 'moved-plugins' === $layout ? $base . '/plugins' : $content . '/plugins',
			'mu_plugins' => 'moved-plugins' === $layout ? $base . '/platform-mu' : $content . '/mu-plugins',
			'config'     => $config,
		);
		$this->make_directory( $site );
		if ( 'symlink-content' === $layout ) {
			$this->make_directory( $base . '/content' );
			if ( ! symlink( $base . '/content', $content ) ) {
				throw new RuntimeException( 'Could not create fixture content symlink.' );
			}
		}
		$paths['sentinels'] = array();
		foreach ( array(
			'site'       => 'core',
			'content'    => 'content',
			'uploads'    => 'uploads',
			'plugins'    => 'plugins',
			'mu_plugins' => 'mu-plugins',
		) as $role => $bytes ) {
			$path = $paths[ $role ] . '/sentinel.txt';
			$this->seed( $path, $bytes );
			$paths['sentinels'][ $role ] = $path;
		}
		$this->seed( $config, "<?php\n" );
		if ( in_array( $layout, array( 'bedrock', 'bedrock-internal-content' ), true ) ) {
			$this->seed( $base . '/project/config/application.php', "<?php\n" );
			$this->seed( $base . '/project/config/environments/development.php', "<?php\n" );
			$this->seed( $base . '/project/.env', "DB_PASSWORD=fixture-not-a-secret\n" );
			$this->seed( $base . '/project/vendor/sentinel.txt', 'vendor' );
		}
		if ( 'read-only-core' === $layout ) {
			$this->set_read_only( $site );
		}
		return $paths;
	}

	/** Remove owned trees without descending into a symlink target. */
	public function cleanup() {
		$this->remove_tree( $this->scratch );
	}

	/**
	 * Create an owned directory when it does not exist.
	 *
	 * @param string $path Directory path.
	 * @throws RuntimeException When creation fails.
	 */
	private function make_directory( $path ) {
		if ( ! is_dir( $path ) && ! mkdir( $path, 0755, true ) ) {
			throw new RuntimeException( 'Could not create fixture directory.' );
		}
	}

	/**
	 * Write stable bytes and a fixed mtime.
	 *
	 * @param string $path Fixture file.
	 * @param string $bytes Literal contents.
	 * @throws RuntimeException When writing or setting the mtime fails.
	 */
	private function seed( $path, $bytes ) {
		$this->make_directory( dirname( $path ) );
		if ( strlen( $bytes ) !== file_put_contents( $path, $bytes ) || ! touch( $path, self::MTIME ) ) {
			throw new RuntimeException( 'Could not seed fixture file.' );
		}
	}

	/**
	 * Remove write permissions from core files and directories.
	 *
	 * @param string $path Owned core path.
	 */
	private function set_read_only( $path ) {
		if ( is_dir( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->set_read_only( $path . '/' . $entry );
				}
			}
			chmod( $path, 0555 );
		} else {
			chmod( $path, 0444 );
		}
	}

	/**
	 * Restore directory write access and unlink without following symlinks.
	 *
	 * @param string $path Owned path.
	 */
	private function remove_tree( $path ) {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
			return;
		}
		chmod( $path, 0755 );
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_tree( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}
