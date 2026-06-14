<?php
/**
 * Model registry: versioned, idempotent migration + CRUD for prv_models.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Manages prv_models: v0.1.x→v0.2.0 migration, CRUD, per-model run-health.
 *
 * V2 schema: [{id, provider, slug, enabled, note, health_status, health_probed,
 *              health_errors, health_run_id}]. v1 legacy: flat string array.
 *
 * Who triggers: PRV_Upgrader::run() (upgrade path); PRV_Settings_Page (CRUD).
 * Dependencies: get_option(), update_option().
 *
 * @see class-prv-upgrader.php      -- Calls run_migration_v2() on upgrade.
 * @see class-prv-settings-page.php -- CRUD consumers.
 * @see ARCHITECTURE.md             -- Section Config (options + defaults).
 * @package PrVision
 */
class PRV_Model_Registry {

	/**
	 * Current schema version stored in prv_models_schema_version.
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * Option key for the models array.
	 */
	const OPTION_KEY = 'prv_models';

	/**
	 * Option key for the models schema version.
	 */
	const VERSION_KEY = 'prv_models_schema_version';

	/**
	 * Run the migration from v1 (flat strings) to v2 (rich objects).
	 *
	 * Idempotent: checks prv_models_schema_version before acting; safe to call
	 * on every plugins_loaded. Preserves the live model set -- existing model
	 * slugs are carried forward with enabled=true and health_status=unknown.
	 *
	 * Side effects: May update prv_models and prv_models_schema_version options.
	 *
	 * @return bool True when a migration was performed, false when already current.
	 */
	public static function run_migration_v2(): bool {
		$current_version = (int) get_option( self::VERSION_KEY, 0 );
		if ( $current_version >= self::SCHEMA_VERSION ) {
			return false;
		}

		$existing = get_option( self::OPTION_KEY, array() );
		$migrated = array();

		if ( is_array( $existing ) && ! empty( $existing ) ) {
			foreach ( $existing as $item ) {
				if ( is_string( $item ) ) {
					// v1 flat string -- upgrade to rich object.
					$migrated[] = self::make_model_object( $item, '', true );
				} elseif ( is_array( $item ) && isset( $item['slug'] ) ) {
					// Already a v2 object -- pass through.
					$migrated[] = self::ensure_model_fields( $item );
				}
			}
		}

		if ( empty( $migrated ) ) {
			$migrated = self::default_model_objects();
		}

		update_option( self::OPTION_KEY, $migrated );
		update_option( self::VERSION_KEY, self::SCHEMA_VERSION );
		return true;
	}

	/**
	 * Get all models as rich-object arrays.
	 *
	 * @return array<int, array<string, mixed>> Model objects.
	 */
	public static function get_all(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return self::default_model_objects();
		}
		return array_values( array_filter( $raw, 'is_array' ) );
	}

	/**
	 * Get only enabled model slugs (for backward compat with PRV_Config).
	 *
	 * @return array<int, string>
	 */
	public static function get_enabled_slugs(): array {
		$models = self::get_all();
		$slugs  = array();
		foreach ( $models as $m ) {
			if ( ! empty( $m['enabled'] ) ) {
				$slugs[] = (string) $m['slug'];
			}
		}
		return $slugs;
	}

	/**
	 * Find a single model by id.
	 *
	 * @param string $id Model id.
	 *
	 * @return array<string, mixed>|null Null if not found.
	 */
	public static function find_by_id( string $id ): ?array {
		foreach ( self::get_all() as $m ) {
			if ( isset( $m['id'] ) && $m['id'] === $id ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Add a new model.
	 *
	 * @param string $slug     OpenRouter model slug.
	 * @param string $provider Provider name (e.g. "openrouter", "perplexity").
	 * @param bool   $enabled  Whether to enable on creation.
	 * @param string $note     Optional operator note.
	 *
	 * @return string New model id.
	 */
	public static function add( string $slug, string $provider, bool $enabled = true, string $note = '' ): string {
		$models   = self::get_all();
		$obj      = self::make_model_object( $slug, $provider, $enabled, $note );
		$models[] = $obj;
		update_option( self::OPTION_KEY, $models );
		return (string) $obj['id'];
	}

	/**
	 * Update an existing model by id.
	 *
	 * @param string               $id   Model id.
	 * @param array<string, mixed> $data Fields to update (slug, provider, enabled, note).
	 *
	 * @return bool True if found and updated.
	 */
	public static function update( string $id, array $data ): bool {
		$models  = self::get_all();
		$updated = false;
		foreach ( $models as &$m ) {
			if ( isset( $m['id'] ) && $m['id'] === $id ) {
				foreach ( array( 'slug', 'provider', 'enabled', 'note' ) as $field ) {
					if ( array_key_exists( $field, $data ) ) {
						$m[ $field ] = $data[ $field ];
					}
				}
				$updated = true;
				break;
			}
		}
		unset( $m );
		if ( $updated ) {
			update_option( self::OPTION_KEY, $models );
		}
		return $updated;
	}

	/**
	 * Remove a model by id.
	 *
	 * @param string $id Model id.
	 *
	 * @return bool True if found and removed.
	 */
	public static function remove( string $id ): bool {
		$models   = self::get_all();
		$filtered = array_values( array_filter( $models, static fn( $m ) => ( $m['id'] ?? '' ) !== $id ) );
		if ( count( $filtered ) === count( $models ) ) {
			return false;
		}
		update_option( self::OPTION_KEY, $filtered );
		return true;
	}

	/**
	 * Update per-model run-health after a completed run.
	 *
	 * Called by PRV_Probe_Runner at the end of a run with per-slug outcome
	 * counts. Sets health_status to 'healthy' or 'retired'.
	 *
	 * Side effects: Updates prv_models option.
	 *
	 * @param string                                         $run_id   UUID of completed run.
	 * @param array<string, array{probed: int, errors: int}> $outcomes Per-slug outcome.
	 *
	 * @return void
	 */
	public static function update_health( string $run_id, array $outcomes ): void {
		$models = self::get_all();
		foreach ( $models as &$m ) {
			$slug = (string) ( $m['slug'] ?? '' );
			if ( empty( $m['enabled'] ) ) {
				$m['health_status'] = 'disabled';
				continue;
			}
			if ( ! isset( $outcomes[ $slug ] ) ) {
				continue;
			}
			$o = $outcomes[ $slug ];
			$m['health_probed'] = (int) $o['probed'];
			$m['health_errors'] = (int) $o['errors'];
			$m['health_run_id'] = $run_id;
			$m['health_status'] = ( 0 === $o['probed'] && $o['errors'] > 0 ) ? 'retired' : 'healthy';
		}
		unset( $m );
		update_option( self::OPTION_KEY, $models );
	}

	/**
	 * Build a new model object with all required fields.
	 *
	 * @param string $slug     Model slug.
	 * @param string $provider Provider name.
	 * @param bool   $enabled  Enabled flag.
	 * @param string $note     Operator note.
	 *
	 * @return array<string, mixed>
	 */
	private static function make_model_object(
		string $slug,
		string $provider = '',
		bool $enabled = true,
		string $note = ''
	): array {
		if ( '' === $provider ) {
			$provider = ( 'perplexity/sonar' === $slug ) ? 'perplexity' : 'openrouter';
		}
		return array(
			'id'            => self::generate_id(),
			'slug'          => $slug,
			'provider'      => $provider,
			'enabled'       => $enabled,
			'note'          => $note,
			'health_status' => 'unknown',
			'health_probed' => 0,
			'health_errors' => 0,
			'health_run_id' => null,
		);
	}

	/**
	 * Ensure all v2 fields exist on an already-rich object (forward compat).
	 *
	 * @param array<string, mixed> $m Existing model array.
	 *
	 * @return array<string, mixed>
	 */
	private static function ensure_model_fields( array $m ): array {
		$defaults = array(
			'id'            => self::generate_id(),
			'provider'      => 'openrouter',
			'enabled'       => true,
			'note'          => '',
			'health_status' => 'unknown',
			'health_probed' => 0,
			'health_errors' => 0,
			'health_run_id' => null,
		);
		return array_merge( $defaults, $m );
	}

	/**
	 * Default model objects matching v0.1.x defaults.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function default_model_objects(): array {
		return array(
			self::make_model_object( 'perplexity/sonar', 'perplexity', true, 'Primary citation signal -- real-web retrieval' ),
			self::make_model_object( 'openai/gpt-4o-search-preview', 'openrouter', true, 'GPT search breadth' ),
			self::make_model_object( 'google/gemini-2.0-flash-001', 'openrouter', true, 'Gemini breadth -- verify slug active before use' ),
		);
	}

	/**
	 * Generate a short unique id for a model object.
	 *
	 * @return string
	 */
	private static function generate_id(): string {
		return sprintf( 'mdl_%s', substr( md5( uniqid( '', true ) ), 0, 12 ) );
	}
}
