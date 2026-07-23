<?php
/**
 * Minimal, dependency-free Markdown → HTML renderer.
 *
 * Deliberately small: it supports exactly the subset used by the plugin's docs
 * (so the in-admin Help tab can render straight from a docs/*.md file, keeping
 * one source of truth). Supported: ATX headings (#..######), paragraphs,
 * unordered (-, *) and ordered (1.) lists, fenced code blocks (```), and inline
 * **bold**, `code`, and [links](url). Tables and other syntax are NOT supported
 * — author the docs within this subset. All text is escaped; only whitelisted
 * tags are emitted.
 *
 * @since 2.18.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_AI_Markdown {

	/** Convert a Markdown string to safe HTML. */
	public static function to_html( $md ) {
		$lines = explode( "\n", str_replace( "\r\n", "\n", (string) $md ) );
		$n     = count( $lines );
		$out   = '';
		$para  = array();
		$list  = null; // 'ul' | 'ol' | null
		$item  = null; // current list-item text buffer (accumulates wrapped lines)

		$flush_para = function () use ( &$para, &$out ) {
			if ( $para ) {
				$out .= '<p>' . self::inline( implode( ' ', $para ) ) . "</p>\n";
				$para = array();
			}
		};
		// Emit the buffered list item, if any.
		$flush_item = function () use ( &$item, &$out ) {
			if ( $item !== null ) {
				$out .= '<li>' . self::inline( trim( $item ) ) . "</li>\n";
				$item = null;
			}
		};
		$close_list = function () use ( &$list, &$item, &$out ) {
			if ( $item !== null ) {
				$out .= '<li>' . self::inline( trim( $item ) ) . "</li>\n";
				$item = null;
			}
			if ( $list ) {
				$out  .= '</' . $list . ">\n";
				$list  = null;
			}
		};

		for ( $i = 0; $i < $n; $i++ ) {
			$line = $lines[ $i ];

			// Fenced code block.
			if ( preg_match( '/^```/', $line ) ) {
				$flush_para();
				$close_list();
				$code = array();
				for ( $i++; $i < $n && ! preg_match( '/^```/', $lines[ $i ] ); $i++ ) {
					$code[] = $lines[ $i ];
				}
				$out .= '<pre class="bl-md-pre"><code>' . esc_html( implode( "\n", $code ) ) . "</code></pre>\n";
				continue;
			}

			// Blank line — ends a paragraph / list.
			if ( trim( $line ) === '' ) {
				$flush_para();
				$close_list();
				continue;
			}

			// Heading.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$flush_para();
				$close_list();
				$lvl  = strlen( $m[1] );
				$out .= '<h' . $lvl . '>' . self::inline( $m[2] ) . '</h' . $lvl . ">\n";
				continue;
			}

			// Unordered list item.
			if ( preg_match( '/^[-*]\s+(.*)$/', $line, $m ) ) {
				$flush_para();
				if ( 'ul' !== $list ) {
					$close_list();
					$out  .= "<ul>\n";
					$list  = 'ul';
				} else {
					$flush_item(); // close the previous item before starting a new one
				}
				$item = $m[1];
				continue;
			}

			// Ordered list item.
			if ( preg_match( '/^\d+\.\s+(.*)$/', $line, $m ) ) {
				$flush_para();
				if ( 'ol' !== $list ) {
					$close_list();
					$out  .= "<ol>\n";
					$list  = 'ol';
				} else {
					$flush_item();
				}
				$item = $m[1];
				continue;
			}

			// A non-blank line inside a list continues the current item (soft-wrap /
			// lazy continuation) — this is what keeps multi-line bullets on one line.
			if ( $list !== null && $item !== null ) {
				$item .= ' ' . trim( $line );
				continue;
			}

			// Otherwise: paragraph text (consecutive lines accumulate).
			$para[] = trim( $line );
		}

		$flush_para();
		$close_list();

		return $out;
	}

	/**
	 * Inline formatting. Text is HTML-escaped first, then links / bold / italic /
	 * code are applied, so no raw markup from the source can leak through.
	 */
	private static function inline( $text ) {
		$text = esc_html( $text );

		// [label](url)
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
			},
			$text
		);

		// **bold** (before single-asterisk italic)
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

		// *italic*
		$text = preg_replace( '/\*([^*\n]+)\*/', '<em>$1</em>', $text );

		// `code`
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		return $text;
	}
}
