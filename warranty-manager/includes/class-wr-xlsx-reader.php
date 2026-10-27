<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimal streaming reader for the first worksheet of an .xlsx file.
 * Needs only ZipArchive and XMLReader, so no Composer libraries on the server.
 */
class WR_Xlsx_Reader {

	/**
	 * Yields each row as a 0-indexed array of cell strings.
	 *
	 * @return Generator<array>
	 */
	public static function rows( string $path, int $max_cols = 20 ): Generator {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( 'Το αρχείο δεν είναι έγκυρο .xlsx.' );
		}
		$shared = self::shared_strings( $zip );
		$sheet  = self::first_sheet_path( $zip );
		$xml    = $zip->getFromName( $sheet );
		$zip->close();
		if ( false === $xml ) {
			throw new RuntimeException( 'Δεν βρέθηκε φύλλο εργασίας στο αρχείο.' );
		}

		$r = new XMLReader();
		$r->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT );
		$row  = null;
		$col  = 0;
		$type = '';
		$val  = '';
		$in_v = false;
		while ( $r->read() ) {
			if ( XMLReader::ELEMENT === $r->nodeType ) {
				switch ( $r->localName ) {
					case 'row':
						$row = array();
						$col = 0;
						if ( $r->isEmptyElement ) {
							yield array();
							$row = null;
						}
						break;
					case 'c':
						$ref  = (string) $r->getAttribute( 'r' );
						$col  = '' !== $ref ? self::col_index( $ref ) : $col;
						$type = (string) $r->getAttribute( 't' );
						$val  = '';
						if ( $r->isEmptyElement ) {
							++$col;
						}
						break;
					case 'v':
					case 't':
						$in_v = true;
						break;
				}
			} elseif ( XMLReader::TEXT === $r->nodeType || XMLReader::CDATA === $r->nodeType || XMLReader::SIGNIFICANT_WHITESPACE === $r->nodeType ) {
				if ( $in_v ) {
					$val .= $r->value;
				}
			} elseif ( XMLReader::END_ELEMENT === $r->nodeType ) {
				switch ( $r->localName ) {
					case 'v':
					case 't':
						$in_v = false;
						break;
					case 'c':
						if ( null !== $row && $col < $max_cols ) {
							$row[ $col ] = 's' === $type ? ( $shared[ (int) $val ] ?? '' ) : $val;
						}
						++$col;
						break;
					case 'row':
						if ( null !== $row ) {
							$out = array();
							$n   = $row ? max( array_keys( $row ) ) : -1;
							for ( $i = 0; $i <= $n; $i++ ) {
								$out[] = trim( (string) ( $row[ $i ] ?? '' ) );
							}
							yield $out;
						}
						$row = null;
						break;
				}
			}
		}
		$r->close();
	}

	private static function col_index( string $ref ): int {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $ref ) );
		$n       = 0;
		foreach ( str_split( $letters ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}

	private static function shared_strings( ZipArchive $zip ): array {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $xml ) {
			return array();
		}
		$out = array();
		$r   = new XMLReader();
		$r->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT );
		$cur  = null;
		$in_t = false;
		$skip = 0; // Inside <rPh> (phonetic runs), which are not part of the text.
		while ( $r->read() ) {
			if ( XMLReader::ELEMENT === $r->nodeType ) {
				if ( 'si' === $r->localName ) {
					$cur = '';
					if ( $r->isEmptyElement ) {
						$out[] = '';
						$cur   = null;
					}
				} elseif ( 'rPh' === $r->localName && ! $r->isEmptyElement ) {
					++$skip;
				} elseif ( 't' === $r->localName && ! $r->isEmptyElement ) {
					$in_t = true;
				}
			} elseif ( XMLReader::END_ELEMENT === $r->nodeType ) {
				if ( 'si' === $r->localName ) {
					$out[] = (string) $cur;
					$cur   = null;
				} elseif ( 'rPh' === $r->localName ) {
					--$skip;
				} elseif ( 't' === $r->localName ) {
					$in_t = false;
				}
			} elseif ( $in_t && ! $skip && null !== $cur && in_array( $r->nodeType, array( XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE, XMLReader::WHITESPACE ), true ) ) {
				$cur .= $r->value;
			}
		}
		$r->close();
		return $out;
	}

	private static function first_sheet_path( ZipArchive $zip ): string {
		$wb   = $zip->getFromName( 'xl/workbook.xml' );
		$rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false !== $wb && false !== $rels ) {
			$rid = null;
			if ( preg_match( '/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m ) || preg_match( '/<(?:\w+:)?sheet\b[^>]*\bid="([^"]+)"/', $wb, $m ) ) {
				$rid = $m[1];
			}
			if ( $rid && preg_match( '/<Relationship\b[^>]*\bId="' . preg_quote( $rid, '/' ) . '"[^>]*\bTarget="([^"]+)"/', $rels, $m )
				|| ( $rid && preg_match( '/<Relationship\b[^>]*\bTarget="([^"]+)"[^>]*\bId="' . preg_quote( $rid, '/' ) . '"/', $rels, $m ) ) ) {
				$target = ltrim( $m[1], '/' );
				return str_starts_with( $target, 'xl/' ) ? $target : 'xl/' . $target;
			}
		}
		return 'xl/worksheets/sheet1.xml';
	}
}
