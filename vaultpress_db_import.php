<?php
/**
 * DB import script for VaultPress database.
 * Author - Vikas Sharma
 *
 * Usage: php vaultpress_db_import.php
 *
 * Recommended settings in php.ini
 * -------------------------------
 * display_error = On
 * memory_limit = 2G
 *
 *
 * Recommended settings in my.ini
 * ------------------------------
 * [mysqld]
 * max_allowed_packet = 2G
 *
 */

class VaultPress_DB_Import {

	/**
	 * Update database settings here:
	 */
	var $dbname    = "database_name";
	var $host      = "database_hostname";
	var $username  = "database_username";
	var $password  = "database_password";

	// No. of rows to insert per query (how fast we want the script to run).
	var $max_rows_per_insert = 500;

	var $db_object = null;
	var $sql_files = [];

	public static $is_cli = false;

	function __construct() {
		set_time_limit(0);
		ob_implicit_flush();
		error_reporting(E_ALL);

		self::$is_cli    = 'cli' === php_sapi_name();
		$this->db_object = $this->connect();
		$this->sql_files = $this->get_sql_files();

		$this->start_import();
	}

	function start_import() {

		if ( empty( $this->sql_files ) ) {
			self::print_message( 'Import error, sql files not found!', 'message' );
			exit;
		}

		foreach( $this->sql_files as $current_sql_file ) {

			self::print_message( 'Processing ' . $current_sql_file, 'heading' );

			// Get DB structure for the current table
			$structure = $this->get_structure( $current_sql_file );
			if( empty( $structure ) ) {

				self::print_message( 'Empty table structure found in' . $current_sql_file, 'error' );
				continue;
			}

			// Add if NOT EXISTS
			if ( strpos( $structure, 'CREATE TABLE `' ) !== FALSE ) {
				$structure = str_replace( 'CREATE TABLE `', 'CREATE TABLE IF NOT EXISTS `', $structure );
			}

			// Create Table structure (if not exists)
			if( ! mysqli_query( $this->db_object, $structure ) ) {
				self::print_message( 'Error in creating table structure - ' . mysqli_error( $this->db_object ), 'error' );
				continue;
			}

			// Get current table name
			$table_name = str_replace( '.sql', '', $current_sql_file );

			// Check how much data was inserted in previous attempt
			// To Do: will come up with a different logic to make the script resume.
			$skip_rows = 0;
			$query     = mysqli_query( $this->db_object, "SELECT COUNT(*) as total_rows FROM $table_name" );
			$res       = mysqli_fetch_array( $query );
			if ( ! empty( $res['total_rows'] ) ) {
				$skip_rows = $res['total_rows'];
			}

			// INSERT insert statements
			$this->insert_data( $current_sql_file, $skip_rows );
		}
	}

	/**
	 * Connect to the database.
	 */
	function connect() {
		$db_object = mysqli_connect( $this->host, $this->username, $this->password, $this->dbname );

		if( empty( $db_object ) ) {
			self::print_message( 'Error: Unable to connect to the database! ' . $this->db_object . ', ' . mysqli_error(), 'error' );
			exit;
		}

		return $db_object;
	}

	/**
	 * Scan current working directory and return the list of SQL file.
	 * @return array
	 */
	function get_sql_files() {

		$all_files = scandir( getcwd() );
		$sql_files = [];

		foreach( $all_files as $filename ) {
			if( substr( strtolower( $filename ), -4  ) == ".sql" ) {
				$sql_files[] = $filename;
			}
		}

		if ( empty( $sql_files ) ) {
			self::print_message( 'Error: no sql file found, nothing to do! <br />current directory :' . dirname( __FILE__ ), 'error' );
			exit;
		}

		return $sql_files;
	}

	/**
	 * Read the input file and extract DB structure.
	 * @param $input_file
	 * @return string
	 */
	function get_structure( $input_file ) {

		$fp = fopen( $input_file, "r" );
		if( ! $fp ) {
			self::print_message( "Could not open input file $input_file", 'error' );
		}

		$line_counter = 0;
		$structure = "";
		$save_structure = false;
		while( ! feof( $fp ) ) {

			$line_counter ++;
			if( $line_counter > 100 ) {

				self::print_message( "Error: something went wrong! table structure can't be 100 lines long :(", 'error' );
				exit;
			}

			$line = fgets( $fp );

			// Database Structure
			if( strpos( $line, "CREATE TABLE" ) !== FALSE ) {
				$save_structure = true;
			}
			if( $save_structure ) {
				$structure .= $line;
			}
			if( strpos( $line, "ENGINE=InnoDB" ) !== FALSE ) {
				break;
			}
		}
		fclose( $fp );
		return $structure;
	}

	/**
	 * Read the input file and execute all INSERT statements
	 * @param $input_file
	 * @param $skip_rows
	 * @return string
	 */
	function insert_data( $input_file, $skip_rows ) {

		$fp = fopen( $input_file, "r" );
		if( ! $fp ) {
			self::print_message( "Could not open input file $input_file", 'error' );
			exit;
		}

		$insert_row_counter = 0;
		$header_found       = false;
		$header_string      = "";
		$value_string       = "";
		$separator_position = 0;
		$total_inserts      = 0;
		$total_rows_skipped = 0;

		while( ! feof( $fp ) ) {
			$line = fgets( $fp );

			// Merge INSERT statements
			if( $header_found && strpos( $line, "INSERT INTO" ) !== FALSE ) {

				// Check if we need to skip some lines (because the data was inserted in a previous attempt).
				if ( $skip_rows > 0 && $total_rows_skipped < $skip_rows ) {
					$total_rows_skipped ++;
					continue;
				}

				$value_string  .= substr( $line, $separator_position, -2 ) . ",";
				$insert_row_counter ++;
			}

			// Find Header and separator_position
			if( ! $header_found && strpos( $line, "INSERT INTO" ) !== FALSE ) {
				$separator_position = strpos( $line, "`) VALUES (" );
				$header_string = substr( $line, 0, $separator_position );
				$separator_position += 10;
				$value_string  = substr( $line, $separator_position, -2 ) . ",";
				$header_found  = true;
			}

			if( $insert_row_counter == $this->max_rows_per_insert ) {

				// Remove coma at the end of the statement.
				$sql = substr( $header_string. "`) VALUES " . $value_string, 0, -1 );

				// Insert
				$results = mysqli_query( $this->db_object, $sql);
				if( empty( $results )  ) {

					self::print_message( "Error while inserting row: " . mysqli_error( $this->db_object ), 'error' );
					self::print_message( "Error at following Line: " . $line, 'error' );
				}

				if ( self::$is_cli ) {
					// print a dot for each insert.
					self::print_message('.');
				}

				$total_inserts += $insert_row_counter;
				// Reset variables.
				$value_string = "";
				$insert_row_counter = 0;
			}
		}

		// Insert Remaining Rows
		// Remove coma at the end of the statement.
		$sql = substr( $header_string. "`) VALUES " . $value_string, 0, -1 );
		if( ! mysqli_query( $this->db_object, $sql ) ) {

			self::print_message( "Mysql Error: " . mysqli_error( $this->db_object ), 'error' );
		}
		$total_inserts += $insert_row_counter;

		self::print_message( "Total $total_inserts rows inserted ", 'success' );

		fclose( $fp );
	}

	/**
	 * Print Message
	 * @param String $message
	 * @param String $type
	 */
	public static function print_message( String $message = '', String $type = '' ) : void {

		if ( self::$is_cli ) {
			switch( $type ) {
				case 'message':
					echo $message . PHP_EOL;
					break;

				case 'heading':
					echo PHP_EOL . $message . PHP_EOL;
					echo '............................';
					break;

				case 'success':
					echo "\033[32m {$message} \033[0m " . PHP_EOL;
					break;

				case 'error':
					echo "\033[31m {$message} \033[0m " . PHP_EOL;
					break;

				default:
					echo $message;
			}

		} else {
			switch( $type ) {
				case 'message':
					echo $message . '<br />';
					break;

				case 'heading':
					echo '<h1>' . $message . '</h1>';
					break;

				case 'success':
					echo '<div style="color: #008800">' . $message . '</div>';
					break;

				case 'error':
					echo '<div style="color: #ff0000">' . $message . '</div>';
					break;

				default:
					echo $message;
			}
		}
	}
}

new VaultPress_DB_Import();

// EOF
