<?php
/**
 * Scoring-config versioning: stamps each run with the model*peptide*intent set.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Tracks the scored config (models x peptides x intents) as a version record.
 *
 * Whenever the scoring-relevant config changes, a new config-version is stamped.
 * Every run is linked to the active config-version at run time. The dashboard
 * trendline uses this to draw break annotations at config changes, and the
 * score query excludes rows from orphaned models/peptides outside the current
 * config-version.
 *
 * Option layout:
 *   prv_config_versions: array of {version: int, hash: string, timestamp: int,
 *                                   models: string[], peptides: string[], intents: string[]}
 *   prv_active_config_version: int (the current version number)
 *
 * Who triggers: PRV_Upgrader::run() for initial seed; PRV_Settings_Page when
 *               scoring-relevant settings are saved.
 * Dependencies: PRV_Model_Registry, PRV_Config.
 *
 * @see class-prv-upgrader.php      -- Calls maybe_seed_initial_version().
 * @see class-prv-settings-page.php -- Calls bump_version_if_changed() on save.
 * @see class-prv-probe-runner.php  -- Reads get_active_version() for run stamp.
 * @package PrVision
 */
class PRV_Config_Version {

	/**
	 * Option key for the version history array.
	 */
	const VERSIONS_KEY = 'prv_config_versions';

	/**
	 * Option key for the currently active version number.
	 */
	const ACTIVE_KEY = 'prv_active_config_version';

	/**
	 * Seed the initial version record if none exists yet.
	 *
	 * Safe to call repeatedly -- no-op when a version already exists.
	 *
	 * Side effects: May write prv_config_versions and prv_active_config_version.
	 *
	 * @return void
	 */
	public static function maybe_seed_initial_version(): void {
		$versions = get_option( self::VERSIONS_KEY, array() );
		if ( ! empty( $versions ) ) {
			return;
		}
		self::create_version_record();
	}

	/**
	 * Compute the config hash from the current model/peptide/intent set.
	 *
	 * @return string MD5 of the sorted, serialised config components.
	 */
	public static function compute_hash(): string {
		$models   = PRV_Model_Registry::get_enabled_slugs();
		$peptides = array_column( PRV_Config::get_peptides(), 'slug' );
		$intents  = PRV_Config::get_prompt_intents();

		sort( $models );
		sort( $peptides );
		sort( $intents );

		return md5( serialize( array( $models, $peptides, $intents ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Bump to a new config version if the scoring config has changed.
	 *
	 * Call this after saving any scoring-relevant setting. Returns the new
	 * version number if a bump occurred, null otherwise.
	 *
	 * Side effects: May write prv_config_versions and prv_active_config_version.
	 *
	 * @return int|null New version number if bumped, null if unchanged.
	 */
	public static function bump_version_if_changed(): ?int {
		$new_hash = self::compute_hash();
		$versions = get_option( self::VERSIONS_KEY, array() );
		$active   = (int) get_option( self::ACTIVE_KEY, 0 );

		foreach ( $versions as $v ) {
			if ( isset( $v['version'] ) && (int) $v['version'] === $active && isset( $v['hash'] ) && $v['hash'] === $new_hash ) {
				return null; // Config unchanged.
			}
		}

		return self::create_version_record();
	}

	/**
	 * Get the active config version number.
	 *
	 * @return int Version number (1-based); 0 if not yet seeded.
	 */
	public static function get_active_version(): int {
		return (int) get_option( self::ACTIVE_KEY, 0 );
	}

	/**
	 * Get all version records, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all_versions(): array {
		$versions = get_option( self::VERSIONS_KEY, array() );
		if ( ! is_array( $versions ) ) {
			return array();
		}
		$sorted = $versions;
		usort( $sorted, static fn( $a, $b ) => (int) ( $b['version'] ?? 0 ) - (int) ( $a['version'] ?? 0 ) );
		return $sorted;
	}

	/**
	 * Check if the given hash differs from the current active config.
	 *
	 * Used by the Settings page to warn before saving a scoring-relevant change.
	 *
	 * @param string $proposed_hash Hash to check.
	 *
	 * @return bool True when the config would change.
	 */
	public static function would_change( string $proposed_hash ): bool {
		$versions = get_option( self::VERSIONS_KEY, array() );
		$active   = (int) get_option( self::ACTIVE_KEY, 0 );
		foreach ( $versions as $v ) {
			if ( isset( $v['version'] ) && (int) $v['version'] === $active ) {
				return isset( $v['hash'] ) && $v['hash'] !== $proposed_hash;
			}
		}
		return false;
	}

	/**
	 * Create a new version record and activate it.
	 *
	 * Side effects: Updates prv_config_versions and prv_active_config_version.
	 *
	 * @return int The new version number.
	 */
	private static function create_version_record(): int {
		$versions = get_option( self::VERSIONS_KEY, array() );
		if ( ! is_array( $versions ) ) {
			$versions = array();
		}
		$new_version = count( $versions ) + 1;
		$models      = PRV_Model_Registry::get_enabled_slugs();
		$peptides    = array_column( PRV_Config::get_peptides(), 'slug' );
		$intents     = PRV_Config::get_prompt_intents();

		$versions[] = array(
			'version'   => $new_version,
			'hash'      => self::compute_hash(),
			'timestamp' => time(),
			'models'    => $models,
			'peptides'  => $peptides,
			'intents'   => $intents,
		);

		update_option( self::VERSIONS_KEY, $versions );
		update_option( self::ACTIVE_KEY, $new_version );
		return $new_version;
	}
}
