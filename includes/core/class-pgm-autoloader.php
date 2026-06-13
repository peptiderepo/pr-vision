<?php
declare(strict_types=1);

/**
 * Class and interface autoloader for the Peptide GEO Monitor plugin.
 *
 * Converts PGM_* class/interface names to file paths under includes/.
 * Naming conventions:
 *   PGM_Foo_Bar (class)      → class-pgm-foo-bar.php
 *   PGM_Foo_Bar (interface)  → interface-pgm-foo-bar.php (tried second)
 *
 * Who triggers: Plugin boot (peptide-geo-monitor.php) — called once before
 *               any PGM_* class or interface is instantiated.
 * Dependencies: None (pure PHP).
 *
 * @package PeptideGeoMonitor
 */
class PGM_Autoloader {

	/**
	 * Register the autoloader with the SPL stack.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( static::class, 'load' ) );
	}

	/**
	 * Attempt to load a PGM_* class or interface file.
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
		if ( 0 !== strpos( $class_name, 'PGM_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', $class_name ) );

		$search_dirs = array(
			PGM_PLUGIN_DIR . 'includes/core/',
			PGM_PLUGIN_DIR . 'includes/providers/',
			PGM_PLUGIN_DIR . 'includes/collector/',
			PGM_PLUGIN_DIR . 'includes/panel/',
			PGM_PLUGIN_DIR . 'includes/',
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
