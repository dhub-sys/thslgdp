<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bulk import of serial numbers from .xlsx/.csv.
 *
 * The upload is converted once into a normalized CSV in a private folder, then
 * processed in small chunks via AJAX so large files never hit the hosting's
 * time/memory limits. Every import is a batch that can be reverted.
 */
class WR_Importer {

	const CHUNK      = 500;
	const MAX_ERRORS = 200;

	const ALIASES = array(
		'serial'    => array( 'serial', 'serial number', 'serial_number', 'serialnumber', 'sn', 's/n', 'σειριακός', 'σειριακος', 'σειριακός αριθμός', 'σειριακος αριθμος', 'σ/α' ),
		'model'     => array( 'model', 'μοντέλο', 'μοντελο', 'product', 'product code', 'προϊόν', 'προιον', 'κωδικός', 'κωδικος', 'κωδικός προϊόντος', 'κωδικος προιοντος', 'sku' ),
		'sale_date' => array( 'date', 'sale date', 'sale_date', 'ημερομηνία', 'ημερομηνια', 'ημερομηνία πώλησης', 'ημερομηνια πωλησης', 'ημ/νία', 'ημ/νια πώλησης' ),
	);

	/**
	 * @return int|WP_Error Batch id.
	 */
	public static function start( array $file, bool $auto_products ) {
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'wr_upload', 'Η μεταφόρτωση του αρχείου απέτυχε.' );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'xlsx', 'csv', 'txt' ), true ) ) {
			return new WP_Error( 'wr_ext', 'Υποστηρίζονται αρχεία .xlsx και .csv. Τα παλιά .xls αποθηκεύστε τα πρώτα ως .xlsx.' );
		}

		try {
			$rows = 'xlsx' === $ext ? WR_Xlsx_Reader::rows( $file['tmp_name'] ) : self::csv_rows( $file['tmp_name'] );
			WR_Install::protect_private_dir();
			$out_path = trailingslashit( WR_Install::private_dir() ) . 'import-' . wp_generate_password( 20, false ) . '.csv';
			$out      = fopen( $out_path, 'w' );
			$map      = null;
			$total    = 0;
			foreach ( $rows as $row ) {
				if ( ! array_filter( $row, static fn( $c ) => '' !== $c ) ) {
					continue;
				}
				if ( null === $map ) {
					$map = self::detect_columns( $row );
					if ( $map['header'] ) {
						continue;
					}
				}
				fputcsv( $out, array( $row[ $map['serial'] ] ?? '', null === $map['model'] ? '' : ( $row[ $map['model'] ] ?? '' ), null === $map['sale_date'] ? '' : ( $row[ $map['sale_date'] ] ?? '' ) ), ',', '"', '' );
				++$total;
			}
			fclose( $out );
		} catch ( Throwable $e ) {
			return new WP_Error( 'wr_parse', $e->getMessage() );
		}

		if ( ! $total ) {
			wp_delete_file( $out_path );
			return new WP_Error( 'wr_empty', 'Το αρχείο δεν περιέχει γραμμές με σειριακούς αριθμούς.' );
		}

		global $wpdb;
		$wpdb->insert(
			WR_DB::table( 'batches' ),
			array(
				'filename'      => sanitize_file_name( (string) $file['name'] ),
				'user_id'       => get_current_user_id(),
				'status'        => 'pending',
				'auto_products' => $auto_products ? 1 : 0,
				'total_rows'    => $total,
				'errors'        => wp_json_encode( array() ),
				'created_at'    => WR_Util::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		update_option( 'wr_import_file_' . $id, $out_path, false );
		WR_Audit::log( 'import_start', 'batch', $id, array( 'file' => $file['name'], 'rows' => $total, 'columns' => $map ) );
		return $id;
	}

	/**
	 * Picks serial/model/date columns from a header row; falls back to A, B, C.
	 */
	public static function detect_columns( array $row ): array {
		$map = array(
			'serial'    => null,
			'model'     => null,
			'sale_date' => null,
			'header'    => false,
		);
		foreach ( $row as $i => $cell ) {
			$norm = mb_strtolower( trim( (string) $cell ) );
			foreach ( self::ALIASES as $field => $aliases ) {
				if ( null === $map[ $field ] && in_array( $norm, $aliases, true ) ) {
					$map[ $field ] = $i;
					$map['header'] = true;
				}
			}
		}
		if ( ! $map['header'] ) {
			return array(
				'serial'    => 0,
				'model'     => 1,
				'sale_date' => 2,
				'header'    => false,
			);
		}
		$map['serial'] ??= 0;
		return $map;
	}

	/**
	 * Reads a CSV exported by Excel: detects ; , or tab, strips the BOM and
	 * converts Windows-1253 (Greek Excel default) to UTF-8.
	 */
	public static function csv_rows( string $path ): Generator {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			throw new RuntimeException( 'Δεν ήταν δυνατή η ανάγνωση του αρχείου.' );
		}
		$first = (string) fgets( $fh );
		rewind( $fh );
		$delims = array( ';' => substr_count( $first, ';' ), ',' => substr_count( $first, ',' ), "\t" => substr_count( $first, "\t" ) );
		arsort( $delims );
		$delim = (string) array_key_first( $delims );
		$line  = 0;
		while ( false !== ( $row = fgetcsv( $fh, 0, $delim, '"', '' ) ) ) {
			if ( 0 === $line++ && isset( $row[0] ) ) {
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
			}
			yield array_map(
				static function ( $c ) {
					$c = (string) $c;
					if ( ! mb_check_encoding( $c, 'UTF-8' ) && function_exists( 'iconv' ) ) {
						$conv = @iconv( 'CP1253', 'UTF-8//IGNORE', $c );
						$c    = false === $conv ? '' : $conv;
					}
					return trim( $c );
				},
				$row
			);
		}
		fclose( $fh );
	}

	public static function get_batch( int $id ): ?object {
		global $wpdb;
		$b = WR_DB::table( 'batches' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $b WHERE id = %d", $id ) ) ?: null;
	}

	/**
	 * Processes the next chunk of a pending batch.
	 */
	public static function process_chunk( int $batch_id ): ?object {
		global $wpdb;
		$batch = self::get_batch( $batch_id );
		if ( ! $batch || 'pending' !== $batch->status ) {
			return $batch;
		}
		$path = (string) get_option( 'wr_import_file_' . $batch_id );
		if ( ! $path || ! is_readable( $path ) ) {
			$wpdb->update( WR_DB::table( 'batches' ), array( 'status' => 'failed' ), array( 'id' => $batch_id ) );
			return self::get_batch( $batch_id );
		}

		$fh = fopen( $path, 'r' );
		fseek( $fh, (int) $batch->file_pos );
		$errors   = json_decode( (string) $batch->errors, true ) ?: array();
		$products = self::product_map();
		$now      = WR_Util::now();
		$values   = array();
		$invalid  = 0;
		$read     = 0;
		$line_no  = (int) $batch->processed;

		while ( $read < self::CHUNK && false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			++$read;
			++$line_no;
			[ $serial, $model, $date ] = array_pad( $row, 3, '' );
			$serial = WR_Util::clean_serial( (string) $serial );
			$key    = WR_Util::serial_key( $serial );

			$problem = null;
			if ( '' === $key ) {
				$problem = 'κενός σειριακός';
			} elseif ( mb_strlen( $serial ) > 64 ) {
				$problem = 'σειριακός μεγαλύτερος από 64 χαρακτήρες';
			}

			$product_id = null;
			$model      = trim( (string) $model );
			if ( ! $problem && '' !== $model ) {
				$mkey = mb_strtoupper( $model );
				if ( isset( $products[ $mkey ] ) ) {
					$product_id = $products[ $mkey ];
				} elseif ( $batch->auto_products ) {
					$product_id         = self::create_product( $model );
					$products[ $mkey ] = $product_id;
				} else {
					$problem = 'άγνωστο μοντέλο «' . $model . '»';
				}
			}

			$sale = null;
			if ( ! $problem && '' !== trim( (string) $date ) ) {
				$sale = WR_Util::parse_date( $date );
				if ( ! $sale ) {
					$problem = 'μη έγκυρη ημερομηνία «' . $date . '»';
				}
			}

			if ( $problem ) {
				++$invalid;
				if ( count( $errors ) < self::MAX_ERRORS ) {
					$errors[] = sprintf( 'Γραμμή %d (%s): %s', $line_no, $serial, $problem );
				}
				continue;
			}
			$values[] = $wpdb->prepare( '(%s,%s,', $serial, $key )
				. ( $product_id ? (int) $product_id : 'NULL' ) . ','
				. ( $sale ? $wpdb->prepare( '%s', $sale ) : 'NULL' )
				. $wpdb->prepare( ",%d,'available',%s)", $batch_id, $now );
		}
		$pos = ftell( $fh );
		$eof = feof( $fh ) || false === fgetcsv( $fh );
		fclose( $fh );

		$inserted = 0;
		if ( $values ) {
			$s        = WR_DB::table( 'serials' );
			$inserted = (int) $wpdb->query( "INSERT IGNORE INTO $s (serial, serial_key, product_id, sale_date, batch_id, status, created_at) VALUES " . implode( ',', $values ) );
		}
		$duplicates = count( $values ) - $inserted;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . WR_DB::table( 'batches' ) . ' SET processed = processed + %d, inserted = inserted + %d, duplicates = duplicates + %d, invalid = invalid + %d, file_pos = %d, errors = %s, status = %s WHERE id = %d',
				$read,
				$inserted,
				$duplicates,
				$invalid,
				$pos,
				wp_json_encode( $errors, JSON_UNESCAPED_UNICODE ),
				$eof ? 'done' : 'pending',
				$batch_id
			)
		);
		if ( $eof ) {
			wp_delete_file( $path );
			delete_option( 'wr_import_file_' . $batch_id );
		}
		return self::get_batch( $batch_id );
	}

	/**
	 * Deletes the batch's serials that have not been activated yet.
	 *
	 * @return array{deleted:int,kept:int}
	 */
	public static function revert( int $batch_id ): array {
		global $wpdb;
		$s       = WR_DB::table( 'serials' );
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $s WHERE batch_id = %d AND status = 'available'", $batch_id ) );
		$kept    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $s WHERE batch_id = %d", $batch_id ) );
		$wpdb->update( WR_DB::table( 'batches' ), array( 'status' => 'reverted' ), array( 'id' => $batch_id ) );
		$path = (string) get_option( 'wr_import_file_' . $batch_id );
		if ( $path ) {
			wp_delete_file( $path );
			delete_option( 'wr_import_file_' . $batch_id );
		}
		WR_Audit::log( 'import_revert', 'batch', $batch_id, compact( 'deleted', 'kept' ) );
		return compact( 'deleted', 'kept' );
	}

	private static function product_map(): array {
		global $wpdb;
		$p   = WR_DB::table( 'products' );
		$map = array();
		foreach ( $wpdb->get_results( "SELECT id, code FROM $p" ) as $row ) {
			$map[ mb_strtoupper( $row->code ) ] = (int) $row->id;
		}
		return $map;
	}

	private static function create_product( string $code ): int {
		global $wpdb;
		$wpdb->insert(
			WR_DB::table( 'products' ),
			array(
				'code'            => mb_substr( $code, 0, 64 ),
				'name'            => mb_substr( $code, 0, 191 ),
				'duration_months' => (int) WR_Settings::get( 'default_duration_months' ),
				'created_at'      => WR_Util::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}
}
