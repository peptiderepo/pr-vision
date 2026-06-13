<?php
declare(strict_types=1);

/**
 * Detects whether peptiderepo.com appears in a list of source domains.
 *
 * Centralises the citation-detection logic so both providers can share it
 * without duplication.  Also parses raw citation arrays/URLs into a clean
 * domain list.
 *
 * Who triggers: PGM_Perplexity_Provider, PGM_OpenRouter_Provider.
 * Dependencies: None (pure PHP).
 *
 * @see CONTEXT.md — "cited", "our_position", "source_domains".
 * @package PeptideGeoMonitor
 */
class PGM_Citation_Detector {

	/**
	 * Parse raw citation data from an API response into a clean domain list.
	 *
	 * Accepts either a flat array of URL strings or an array of objects with
	 * a 'url' key (Perplexity style). Unknown shapes are skipped gracefully.
	 *
	 * @param array<mixed> $raw_citations Raw citations from the API response.
	 *
	 * @return string[] Lowercase domain names (e.g. ['peptiderepo.com', 'examine.com']).
	 */
	public function parse_domains( array $raw_citations ): array {
		$domains = array();

		foreach ( $raw_citations as $item ) {
			$url = '';
			if ( is_string( $item ) ) {
				$url = $item;
			} elseif ( is_array( $item ) && isset( $item['url'] ) && is_string( $item['url'] ) ) {
				$url = $item['url'];
			}

			if ( '' === $url ) {
				continue;
			}

			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' !== $host ) {
				$domains[] = strtolower( ltrim( $host, 'www.' ) );
			}
		}

		return array_values( array_unique( $domains ) );
	}

	/**
	 * Detect whether PGM_TARGET_DOMAIN appears in a domain list.
	 *
	 * @param string[] $domains Parsed domain list from parse_domains().
	 *
	 * @return bool
	 */
	public function is_cited( array $domains ): bool {
		return in_array( PGM_TARGET_DOMAIN, $domains, true );
	}

	/**
	 * Find the 1-based position of PGM_TARGET_DOMAIN in a domain list.
	 *
	 * Returns null when the target domain is not found.
	 *
	 * @param string[] $domains Parsed domain list.
	 *
	 * @return int|null
	 */
	public function get_our_position( array $domains ): ?int {
		$pos = array_search( PGM_TARGET_DOMAIN, $domains, true );
		if ( false === $pos ) {
			return null;
		}
		return (int) $pos + 1; // Convert 0-based index to 1-based position.
	}
}
