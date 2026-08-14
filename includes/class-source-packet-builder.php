<?php
/**
 * Build deterministic, source-traceable Journal drafts when AI is unavailable.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Dispatch_Source_Packet_Builder {
	const MARKER = '<!-- lunara-source-packet -->';

	/**
	 * Build one editable four-paragraph section per source item.
	 *
	 * These packets are intentionally marked as needing review. They preserve
	 * the reporting lead, provenance, and image workflow without pretending a
	 * provider outage produced finished Lunara copy.
	 *
	 * @param array $items Hydrated Dispatch source items.
	 * @return string
	 */
	public static function build_html( array $items ) {
		$sections = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title = self::clean_text( $item['title'] ?? '' );
			$url   = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( '' === $title || '' === $url ) {
				continue;
			}

			$source      = self::clean_text( $item['source_label'] ?? 'the original source' );
			$published   = self::clean_text( $item['published_at'] ?? '' );
			$description = self::clean_text( $item['description'] ?? '' );
			if ( '' === $description ) {
				$description = sprintf(
					'Dispatch received this report from %s under the headline “%s.”',
					$source,
					$title
				);
			}
			$description = wp_trim_words( $description, 90, '…' );

			$source_note = '' !== $published
				? sprintf( 'Reported by %s on %s. The source date, original URL, and available lead image are preserved with this draft.', $source, $published )
				: sprintf( 'Reported by %s. The original URL and available lead image are preserved with this draft.', $source );

			$editorial_note = 'Editorial brief: this is a source packet, not publishable copy. Verify the reporting, decide what Lunara actually thinks, then rewrite it in the provocative, honest Journal voice before approval.';
			$link_note = sprintf(
				'Original report: <a href="%s" rel="noopener noreferrer">%s at %s</a>.',
				esc_url( $url ),
				esc_html( $title ),
				esc_html( $source )
			);

			$sections[] = '<h2>' . esc_html( $title ) . '</h2>'
				. self::MARKER
				. self::paragraph( esc_html( $description ) )
				. self::paragraph( esc_html( $source_note ) )
				. self::paragraph( '<strong>Editorial brief:</strong> ' . esc_html( preg_replace( '/^Editorial brief:\s*/', '', $editorial_note ) ) )
				. self::paragraph( $link_note );
		}

		return implode( "\n", $sections );
	}

	private static function paragraph( $html ) {
		return "\n<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->\n";
	}

	private static function clean_text( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}
}
