<?php
/** @package PrVision */
declare(strict_types=1);

/**
 * Class and interface autoloader for the PR Vision plugin.
 *
 * Converts PRV_* class/interface names to file paths under includes/.
 * Naming conventions:
 *   PRV_Foo_Bar (class)      → class-prv-foo-bar.php
 *   PRV_Foo_Bar (interface)  → interface-prv-foo-bar.php (tried second)
 *
 * Who triggers: Plugin boot (pr-vision.php) — called once before
 *               any PRV_* class or interface is instantiated.
 * Dependencies: None (pure PHP).
 *
 * @package PrVision
 */
class PRV_Autoloader {

	/**
	 * Register the autoloader with the SPL stack.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( static::class, 'load' ) );
	}

	/**
	 * Attempt to load a PRV_* class or interface file.
	 *
	 * Walks the known include subdirectories in order, trying the class-
	 * filename first and the interface-filename second.
	 *
	 * Side effects: require_once on a matching file.
	 *
	 * @param string $class_name Fully-qualified class or interface name.
	 *
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'PRV_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', $class_name ) );

		$search_dirs = array(
			PRV_PLUGIN_DIR . 'includes/core/',
			PRV_PLUGIN_DIR . 'includes/providers/',
			PRV_PLUGIN_DIR . 'includes/collector/',
			PRV_PLUGIN_DIR . 'includes/panel/',
			PRV_PLUGIN_DIR . 'includes/',
		);

		// Try class file first, then interface file.
		$candidates = array( 'class-' . $slug . '.php', 'interface-' . $slug . '.php' );

		foreach ( $search_dirs as $dir ) {
			foreach ( $candidates as $file ) {
				$path = $dir . $file;
				if ( file_exists( $path ) ) {
					require_once $path;
					return;
				}
			}
		}
	}
}
