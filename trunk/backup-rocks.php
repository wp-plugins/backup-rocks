<?php
/*
Plugin Name: Backup Rocks
Description: An all new unique, reliable automatic real-time cloud backup service for your websites, which can backup changes on your websites occurring even million times in a second! Call it as creating a clone-website of your real one, so that you never miss out any data ever!

Author: TeamMiFe
Version: 1.3
Author URI: http://backup.rocks
*/

set_time_limit(0);		
ini_set('memory_limit', '1000M');

define( 'BACKUP_ROCKS_PART', 'backup.rocks' );
define( 'BACKUP_ROCKS_AJAX', '/wp-admin/admin-ajax.php' );
define( 'BACKUP_ROCKS_PRODUCT', 'Backup Rocks' );
define( 'BACKUP_ROCKS_ADMIN', 'BRADMIN' );
define( 'BACKUP_ROCKS_VERSION', '1.2' );
define( 'BACKUP_ROCKS_DIR', plugin_dir_path(__FILE__) );
define( 'SCHEME', 'HTTPS' );

if( !defined( 'DS' ) ) {
	if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') {
		define('DS', '\\'); 
	} else { 
		define('DS', '/');
	} 
} 
register_activation_hook(__FILE__, 'backup_rocks_plugin_activate' );

	function backup_rocks_plugin_activate() {
	 
		$wpbr = new BACKUPROCKS();
		$wpbr-> backup_rocks_plugin_activate();
	}

require_once 'br_core.php';
BACKUPROCKS::getInstance();
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
	protected $upload_dir;
	protected $return_zip;
	private static $instance;
	protected $serverpath;
	public static function getInstance() {
		if( !self::$instance ) {
			$class = __CLASS__;
			new $class;
		}
		return self::$instance;
	}
	function __construct() {
		parent::__construct(); 
		$this->serverpath = BACKUP_ROCKS_PART.'/'; 
		$this->max_insert_string_len = 50000;  
		add_action( 'plugins_loaded', array($this, 'wp_br_plugin_load') );
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
		$this->serverpath .= BACKUP_ROCKS_ADMIN;
		update_option( 'wpbr_sgnittes', $default_sgnittes );
		add_action( 'admin_menu', 			array($this, 'backup_rocks_license_menu'));
		add_action( 'admin_init', 			array($this, 'backup_rocks_register_option'));
		add_action( 'added_option', 		array($this, 'backup_rocks_rocking'));
		add_action( 'updated_option', 		array($this, 'backup_rocks_rocking'));
		add_action( 'admin_enqueue_scripts',array($this, 'backup_rocks_style'));
		add_action( 'wp_ajax_update_option_new', array( $this, 'update_option_new' ) );
		add_action( 'wp_ajax_nopriv_wpbr_br_api', array( $this, 'res_to_br_api' ) );
		add_action( 'wp_ajax_nopriv_wpbr_pr_br_api', array( $this, 'res_to_br_api_rq' ) );
		add_action( 'wp_ajax_nopriv_wpbr_api_br_last', array( $this, 'res_br_api_last' ) );
		add_action( 'wp_ajax_nopriv_wpbr_br_in_api', array( $this, 'br_in_api'));
		add_action( 'wp_ajax_nopriv_wpbr_elif_ini_qer', array( $this, 'elif_ini_qer') );
		add_action( 'wp_ajax_nopriv_wpbr_elif_ini_qer1', array( $this, 'elif_ini_qer1') );
		add_action( 'wp_ajax_nopriv_wpbr_br_api__fl', array( $this, 'br_api__fl') );
		add_action( 'wp_ajax_nopriv_wpbr_br_api__fl_ircni', array( $this, 'br_api__fl_ircni') );
		add_action( 'wp_ajax_nopriv_wpbr_br_in_elif', array( $this, 'br_in_elif') ); 
		add_action( 'wp_ajax_nopriv_wpbr_br_api_num', array( $this, 'br_in_num') );
		add_action( 'wp_ajax_nopriv_wpbr_br_api_nur', array( $this, 'br_api_nur') );
		add_action( 'wp_ajax_nopriv_wpbr_br_api_piz', array( $this, 'br_api_piz') );
		add_action('wp_ajax_nopriv_wpbr_br_api__fl_first', array($this, 'br_api__fl_first') );		
		add_action('wp_ajax_nopriv_rid_nacs', array($this, 'rid_nacs') );		
		add_action('wp_ajax_nopriv_htap', array($this, 'htap') );		
		add_action('wp_ajax_nopriv_initial_creds_wn', array($this, 'initial_creds_wn') );
		add_action('wp_ajax_nopriv_br_initiated_backup', array($this, 'br_initiated_backup') );		
		add_action( 'wp_ajax_nopriv_wpbr_br_api_led', array( $this, 'br_api_led') );
		add_action( 'wp_ajax_nopriv_wpbr_br_api__fl_led', array( $this, 'br_api__fl_led') );
		register_deactivation_hook( __FILE__, array( $this, 'backup_rocks_deactivate') );
		$this->serverpath .= BACKUP_ROCKS_AJAX; 
		$absolute_path = rtrim( ABSPATH, '\\/' );
		$site_url = rtrim( site_url( '', 'http' ), '\\/' );
		$home_url = rtrim( home_url( '', 'http' ), '\\/' );
		if ( $site_url != $home_url ) {
			$difference = str_replace( $home_url, '', $site_url );
			if( strpos( $absolute_path, $difference ) !== false ) {
				$absolute_path = rtrim( substr( $absolute_path, 0, -strlen( $difference ) ), '\\/' );
			}
		}
		$this->upload_dir = wp_upload_dir();
		$this->upload_dir = $this->upload_dir['basedir'];
		$this->absolute_root_file_path = $absolute_path;
		$this->rows_per_segment = apply_filters( 'wpbr_rows_per_segment', $this->rows_per_segment );
		$this->scheme = SCHEME.'://'; 
		$this->serverpath = strtolower($this->serverpath); 
	}
	function backup_rocks_plugin_activate() { 		
		$data = array(
				'action' 			=> 'br_activate',	
				'item_name' 		=> urlencode( BACKUP_ROCKS_PRODUCT ),
				'url' 				=> urlencode( home_url() ), 
				'version'			=> BACKUP_ROCKS_VERSION,
				'dir' 				=> BACKUP_ROCKS_DIR
			);
		$this->br_remote_post($data);
		add_option( 'backup_rocks_redirect_on_activate', true ); 
		$license = $this->generateRandomString(30);
		add_option( 'backup_rocks_license_key', $license );
		add_option( 'backup_rocks_license_status', false ); 
	} 
	function wp_br_plugin_load() {
		if ( !is_admin() ) return;
		global $wpbr, $br_sionver; 
    	$br_sionver = get_option( 'br_sionver' ); 
	    if ( $br_sionver != BACKUP_ROCKS_VERSION ) { 
	      $this->install_br();  
	    } 
	}
	function install_br() {		
		$license = trim( get_option( 'backup_rocks_license_key' ) );	
		$data = array(
			'action' 			=> 'ginplu_sionver',
			'version' 			=> BACKUP_ROCKS_VERSION,
			'license' 			=> urlencode( $license ), 
			'item_name' 		=> urlencode( BACKUP_ROCKS_PRODUCT ),
			'url' 				=> urlencode( home_url() ), 
			'wp_version' 		=> get_bloginfo('version'), 
			'path' 				=> rtrim( ABSPATH, '\\/' ), 
			'size' 				=> $this->get_total_size() 
		); 
		$r = $this->br_remote_post($data); 
		update_option('br_sionver', BACKUP_ROCKS_VERSION); 
	} 
	function br_api_nur() {
		global $wpdb;	
		$this->br_resrev_brbr();	
		$table = $_POST['table']; 
		$this->create_dir();
		$return = "SET NAMES utf8;
	            SET foreign_key_checks = 0;
	            SET time_zone = '-04:00';
	            SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';";
	    $files_to_zip = array();
			$f = $table.'.sql';
			$file = $this->upload_dir."/b.r/inc/sql/$f";
			if(!is_dir($this->upload_dir."/b.r/inc/sql")) {
				@mkdir($this->upload_dir."/b.r/inc/sql", 0777, true);
			}
			$files_to_zip[] = array (
				"src" => $file,
				"dest" => $f
				);
			$handle = fopen($file,'a');    	
	        $create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N); 
	        $create_table[0][1] = str_replace( 'CREATE TABLE `', 'CREATE TABLE IF NOT EXISTS `', $create_table[0][1] );
	        $create_table[0][1] = str_replace( 'TYPE=', 'ENGINE=', $create_table[0][1] );	       
	        $alter_table_query = '';
			$create_table[0][1] = $this->req_lq_br_api( $create_table[0][1], $table, $alter_table_query );	
			$return .= "\n\n".$create_table[0][1].";\n\n";			
			fwrite($handle, print_r($return, true));			
	        $result = $wpdb->get_results( 'SELECT * FROM '.$table, ARRAY_N ); 
	        foreach ($result as $vals ) {
	        	$return = 'REPLACE INTO '.$table.' VALUES(';
	        	foreach ( $vals as $k => $v ) {
	        		$return .= '"'. mysql_real_escape_string($v) .'", ';
	        	}
	        	$return = rtrim($return, ', ').");\n";
				fwrite($handle, print_r($return, true));				
	        }
	        $size = filesize($file);
	        fclose($handle);
	    $return = array('size' => $size, 'msg' => 'success' );
	    echo $return = serialize( $return );
	    die();  
	}
	function br_api_piz() {
		global $wpdb; 
		$this->br_resrev_brbr();
		$this->create_dir();
		$chunks = $_POST['chunks'];				
		$this->zip($chunks, $this->upload_dir."/b.r/inc/sql/", $this->upload_dir."/b.r/assets/");				
		$upload_dir = wp_upload_dir();
		$upload_dir = $upload_dir['baseurl'].'/b.r/assets/'.$this->return_zip;  
		$return = array('str' => $upload_dir , 'msg' => 'success' ); 
		echo $return = serialize( $return ); 
		die(); 
	}
	function br_api_led() { 
		// $this->br_resrev_brbr(); 
		$dirPath = $this->upload_dir.'/b.r';
		$this->deleteDir($dirPath);
		$return = array('msg' => 'success' );
	    echo $return = serialize( $return );
		die();
	}
	function br_in_api() {
		$this->br_resrev_brbr();
		$credentials=array();
		foreach ($_POST['cred'] as $key => $value) {
			if(file_exists(ABSPATH."$value") ){
				$credentials[$value] = sha1_file(ABSPATH."$value");
			}
		}
		if (is_dir(ABSPATH.$_POST['lang'])) {
		    	$dir=ABSPATH.$_POST['lang']; 
				$ffs = scandir($dir);
			    foreach($ffs as $ff)
			    {
				        if($ff != '.' && $ff != '..'){
				            $credentials[$ff]=sha1_file($dir.'/'.$ff);				            
				        }
				 }
    	}
    	echo serialize($credentials);
    	die();
	}
	function elif_ini_qer(){
		$htap = $_POST['redlof'];
		$server12 = new server();
		$server12->br_fl_api($htap);
		$return = $server12->send_br_req($htap);
		die();
	}
	function elif_ini_qer1(){
		$htap = $_POST['redlof'];
		$server12 = new server();
		$server12->br_fl_api($htap);
		$return = $server12->send_br_req($htap);
		die();
	}
	function br_in_elif() { 
		$this->br_resrev_brbr();
		global $wpdb;
		$chunks = $_POST['chunks'];	
		$this->create_dir();
		$this->zip( $chunks, WP_CONTENT_DIR, $this->upload_dir."/b.r/assets/" ); 
		die();
	}
	function br_in_num() {
        $mun_eifl = $_POST['mun_eifl']; 
        $dir = $this->upload_dir."/b.r/assets/".$mun_eifl.'.zip'; 
        unlink($dir);
        echo "$dir  = success =  $mun_eifl"; 
    }
	function create_dir($folder = '') {
		$folders[] = "b.r"; 
		$folders[] = "b.r/inc"; 
		$folders[] = "b.r/assets"; 
		if( $folder == '' ) {
			foreach ($folders as $folder) {
				$folder = $this->upload_dir."/".$folder;
				if(!is_dir($folder)) {
					@mkdir($folder, 0777, true);
				}
				$ss = fopen($folder.'/index.php', 'w');				
				fwrite($ss, "<?php \n // silence is gold! ");
				fclose($ss);
			}
		}
	}
	function deleteDir($dirPath) {
	    if ( !is_dir($dirPath) ) {
	        throw new InvalidArgumentException("$dirPath must be a directory");
	    }
	    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
	        $dirPath .= '/';
	    }
	    $files = glob($dirPath . '*', GLOB_MARK);
	    foreach ($files as $file) {
	        if (is_dir($file)) {
	            $this->deleteDir($file);
	        } else {
	            unlink($file);
	        }
	    }
	    rmdir($dirPath);
	}
	function br_api__fl() {
		$this->br_resrev_brbr();
		if(isset($_POST['file'])) {
			$nn = ABSPATH.$_POST['file'];
			echo file_get_contents($nn);
		    die();
		}
	}
		function br_api__fl_ircni() {
		// $this->br_resrev_brbr();
		if(isset($_POST['file'])) {
			$nn = ABSPATH.$_POST['file'];
			echo file_get_contents($nn);
		    die();
		}
	}
	function br_api__fl_first() {
		$this->br_resrev_brbr(); 
			$array = $_POST['file'];
			$part = $_POST['part'];
			$this->_zip ($array, $part, $this->upload_dir."/b.r/assets/");
			echo "done";
		    die();
	}
	function parse_accepted_data( $data ) {
		parse_str( $data, $form_data );		
		$form_data = array_intersect_key( $form_data, array_flip( $this->accepted_fields ) );
		unset( $form_data['replace_old'][0] );
		unset( $form_data['replace_new'][0] );
		return $form_data;
	} 
	private function br_resrev_brbr() { 
					$eek_lidv = get_option('backup_rocks_license_key', true);
					if( isset($_POST['br_niam_do']) && $_POST['br_niam_do'] == BACKUP_ROCKS_PART && $_POST['key'] == $eek_lidv ) {
						return;
					} else { 
						die(); 
					} 
	}
	protected function br_remote_post($api_params) { 
		$response = wp_remote_post( 
			$this->scheme.$this->serverpath, 
			array( 'timeout' => 30, 'body' => $api_params, 'cookies' => array() ) 
		);
		if( is_wp_error($response) ) { 
			$response = wp_remote_post( 
				'http://'.$this->serverpath, 
				array( 'timeout' => 30, 'body' => $api_params, 'cookies' => array() ) 
			); 	
		}
		return $response;
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
		$lic_set 	= get_option( 'backup_rocks_lic_set' ); 
		$email 		= get_option( 'admin_email' );

		if( is_array($lic_set) && $lic_set['user_email'] ) {
			$email = $lic_set['user_email'];
		}
		$status 	= get_option( 'backup_rocks_license_status' );		
 		$active 	= '';
 		$message 	= '';
		if( $status !== false && $status == 'valid' ) { 
			$message = '<span style="color: orange; font-size: 18px;">User license is active!</span>';
			$active = 'active';
		} else { 
			$message = '';
			$active = '';
		}
		?>
		<div class="wrap">
			<div class="top_navigation">
				<a href="https://backup.rocks/#join_now_id_pricing_table">
					<img src="<?php echo plugin_dir_url(__FILE__); ?>images/backup-rocks-300x761.png">
				</a> 
			</div>
			<div class="backup-wrap2 <?php echo $active; ?>"> 
				<div class="login_wrap_content"> 
					<a href="#" data-value="Login" class="login act_tab">Create an account</a>					
				</div> 
				<div class="reg_wrap_content"> 
					<a href="#" data-value="Register" class="register">Login</a>
				</div>
				<br/>
				<form id="b_r_form" class="br_form" method="post" action="options.php">	
					<?php wp_nonce_field( 'br_action', 'br_nonce' ); ?>	
					<a id="show_register">Already Have an Account?- Sign In</a> 
					<p id="ss_msg"><?php echo $message; ?></p> 
					<br/>
					<label for="user_email"> 
						Email ID
						<input type="text" name="user_email" value="<?php _e($email, 'b.r') ?>" placeholder="Email" />
					</label> 
					<br/> 
					<label for="user_pass"> 
						Password
						<input type="password" name="user_pass" value="" placeholder="Password" />
					</label> 
					<br/>						
					<?php if( $status === false || $status !== 'valid' ) { ?> 
					<p class="submit"> 
						<input id="submit" type="submit" value="Register" class="button-rocks submit" name="submit"> 
						<span class="spinner"></span>
						<!-- <span>Or</span> 
						<input type="submit" value="Login" class="button-rocks submit" name="submit">  -->
					</p> 
					<?php } ?> 
				</form>	
			</div> 
			<div class="backup-wrap-msg <?php echo $active; ?>"> 
				<span>
			    <a href="https://backup.rocks/dashboard/?c=dashboard" target="_blank">Click here</a> to manage your backups from the backup.rocks Dashboard. 
				</span>
			</div> 
			<div class="backup-wrap" style="display:none;"> 
				<h1>The backup.rocks plugin requires a valid subscription.</h1>
				<div class="desc">The backup.rocks plugin enables you to take real-time backup of your websites, accounting changes occuring in fractions of seconds! Explore the backup.rocks plugin to stay secured forever!</div>
				<?php if( $active=='' ) { ?>
				<a href="#b_r_form_reg" class="button-rocks show_form">Get started with a free plan!</a>
				<?php } ?>
				<br/> <br/>		
			</div>
			<script type="text/javascript">
				(jQuery)(function($) {
					var i = 0;
					$('#b_r_form').submit(function(e) { 
						e.preventDefault();
						var $this  = $(this); 
						$this.next('.ss_msg').html();
						$('.spinner').show();
						$('#submit').attr('disabled', true);
						$.ajax({
							url: "<?php echo admin_url( 'admin-ajax.php' ) ?>",
							type: 'POST', 
							dataType: 'json', 
							data: {
								action: 'update_option_new', 
								data : $this.serialize() 
							},
							success: function(res) { 
									if(res.hide) { 
										$('#b_r_form p.submit').hide(); 
										$('.backup-wrap2').fadeOut(200); 
										$('.show_form').fadeOut(200); 
										$('.backup-wrap-msg').fadeIn(200); 
									} else {
										$('#ss_msg').text(res.error).css({'color':'red'});
									} 
									$('.spinner').hide();
									$('#submit').attr('disabled', false);
							},
							error: function(res) {
								window.location.reload();
							} 
						});					  
					}); 
					$('.show_form').click(function(e) { 
						e.preventDefault();
						$('.reg_wrap_content').addClass('active'); 
						$('#b_r_form_reg').addClass('active');  
					}); 
					var f = 0;
					$('#show_register').click(function(e) { 
						e.preventDefault(); 
						if(f==0) { 
							$('.login_wrap_content').hide();  
							$('.reg_wrap_content').show(); 
							$('#b_r_form input[name="submit"]').val('Sign In');  
							$(this).html("Don't have an account, create new one"); 
							f = 1;
						} else { 
							$('.login_wrap_content').show();  
							$('.reg_wrap_content').hide(); 
							$('#b_r_form input[name="submit"]').val('Register');  
							$(this).html("Already Have an Account?- Sign In"); 
							f = 0;
						}
					}); 
				}); 
			</script>
			<style type="text/css">
				.backup-wrap2 .button-rocks {				    
				    top: 0 !important;
				} 				
			</style>
		<?php
	}
	// register backup rocks options 
	public function backup_rocks_register_option() { 	
		add_option( 'backup_rocks_lic_set', array() );
		if ( get_option('backup_rocks_redirect_on_activate', false ) ) {
	        delete_option('backup_rocks_redirect_on_activate');
	        wp_redirect(admin_url('admin.php?page=backup-rocks'));
	        exit;
	    }
	} 		
	//Backup rocks rocking
	function backup_rocks_rocking($option = '' ) { 
		if( strcmp($option,'backup_rocks_license_status') !== 0 ) return false;
		$value = get_option('backup_rocks_license_status');
		if( $value !== 'valid' ) return false;
		$license = trim( get_option( 'backup_rocks_license_key' ) );
		$data = array(
			'action' 			=> 'br_rocking',
			'license' 			=> urlencode( $license ),
			'item_name' 		=> urlencode( BACKUP_ROCKS_PRODUCT ),
			'url' 				=> urlencode( home_url() ),
			'license_status' 	=> $value,
			'wp_version' 		=> get_bloginfo('version'),
			'path' 				=> rtrim( ABSPATH, '\\/' ), 
			'size' 				=> $this->get_total_size(),
			'version'			=> BACKUP_ROCKS_VERSION,
			'dir' 				=> BACKUP_ROCKS_DIR	
		); 		
		$this->br_remote_post($data); 
		return false;
	}
	function get_total_size() {
		$dir_size = $this->dirsize(WP_CONTENT_DIR);
		$dir_size = $this->file_size($dir_size['size']);
		$db_size  = $this->get_table_sizes('size');

		return ($dir_size + $db_size);
	} 
	function dlihc_rid($dir) {
		$directories = glob($dir . '/*' , GLOB_ONLYDIR);
		return $directories;
	}
	function strposa($haystack, $needle, $offset=0) {
		if(!is_array($needle)) $needle = array($needle);
		    foreach($needle as $query) {
		        if(strpos($haystack, $query, $offset) !== false) return true;
		    }
		    return false;
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
	function htap() {
		$this->br_resrev_brbr();
		$startpath = WP_CONTENT_DIR;
		$htap = array();
	  	$edulcxe = array();
		$edulcxe = $_POST['edulcxe'];
	    // $folders = array($startpath);
	    $folders = array();
	    $total_sz=$this->dirsize($startpath);
    if($total_sz['size'] <= '1073741824')
    {
    	$htap[]=WP_CONTENT_DIR;
    	$htap['gb'] = 'bg';
    	echo serialize( $htap );
    } 
    else{
    $dirIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($startpath), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($dirIterator as $folder)
    {
        $id = substr($folder, strrpos($folder, '/') + 1);
        if(!$folder->isDir())  continue;
        $mm=explode("/..",$folder);
        $mn=explode("/.", $folder);
       
        if(count($mm) == '1' && count($mn) == '1' ){
    
        	if($this->strposa($folder, $edulcxe) ) { 
        	}
	           	else {
	            		$nm=$startpath . DIRECTORY_SEPARATOR . $dirIterator->getSubPathname();
	            		$an=$this->dirsize($nm);
	            		$folders[$nm] =$an['size'];
	            		} 
   	}   
    } 
    

    	$var = array();
    	$var = $folders;
    	
		foreach ($var as $key => $value) {
			if ($value >= '1073741824') {
				$ss = $this->dlihc_rid($key);
				if (!empty($ss)) {
			
				}
				else {
					$htap[] = $key;
				}
			}
			else {
				
					$ss = $this->dlihc_rid($key);
					
										
					if (!empty($ss)) {
					 	$parentdir=dirname($key);
					 	
						 $sz=$this->dirsize($parentdir);
							

						 if($sz['size'] <= '1073741824'){
					 	}
					 	else
					 	{

					 	$htap[]=$key;
					 }
				}
				if(empty($ss))
				{
					$parentdir1=dirname($key);
					$sz1=$this->dirsize($parentdir1);
					 if($sz1['size'] <= '1073741824'){
					 	}
					 	else
					 	{

					 	$htap[]=$key;
					 }



				}
				
		}
		}
	
        $api_params = array(
			'action' 			=> 'full_files_backup_folder_path1',
			'url' 				=> urlencode( home_url() ),
			'htape'			=> base64_encode(serialize($htap)),
			'big'			=> '1',	
		);
		$rs = wp_remote_post( server_path, array( 'timeout' => 0, 'sslverify' => false, 'body' => $api_params ) );
	}
}
function file_size($fsizebyte) {
		$fsize = round(((int)$fsizebyte/1048576), 2); 
	    return $fsize;
	}
	function all_done($option = '' ) { 
		$value = get_option('backup_rocks_license_status');
		if( $value !== 'valid' ) return false;
		$license = trim( get_option( 'backup_rocks_license_key' ) );			
		$data = array(
			'action' 			=> 'br_rocking',
			'license' 			=> urlencode( $license ),
			'item_name' 		=> urlencode( BACKUP_ROCKS_PRODUCT ),
			'url' 				=> urlencode( home_url() ),
			'license_status' 	=> $value,
			'wp_version' 		=> get_bloginfo('version'),
			'path' 				=> rtrim( ABSPATH, '\\/' ), 
			'size' 				=> $this->get_total_size()	
		); 		
		$this->br_remote_post($data); 
		return false;
	}
	function update_option_new() { 
		$lic_set = array();
		$status = get_option('backup_rocks_license_status', true); 
		$lic_set = get_option('backup_rocks_lic_set', true); 
		parse_str($_POST['data'], $output); 
		if ( ! wp_verify_nonce( $output['br_nonce'], 'br_action' ) ) {
			echo json_encode( array( 'success' => false, 'error' => 'No Kiddie..' ) );
			die();
		} 			
		if( !isset($output['user_email']) || $output['user_email'] === '' ) { 
			echo json_encode( array( 'success' => false, 'error' => 'Please fill out the Email ID!' ) );
			die();
		}	
		$password = ( isset( $output['user_pass'] ) ? sanitize_text_field( $output['user_pass'] ) : '' ); 
		if ( $pass_length = strlen( $password ) ) {				
			if ( $pass_length < 6 ) { 
				echo json_encode( array( 'success' => false, 'error' => 'Password must be 6 character long!' ) );
				die(); 				
			} 
		} else { 
			echo json_encode( array( 'success' => false, 'error' => 'Please enter Password and must be 6 character long!' ) );
			die();
		}
		if ( !is_email( $output[ 'user_email' ] ) ) { 
			echo json_encode( array( 'success' => false, 'error' => 'Please enter a valid email!' ) );			
			die();
		}	
		$lic_set['user_email'] = sanitize_email( $output['user_email'] );
		$lic_set['site_url'] = site_url();
		$lic_set['user_pass'] = $output['user_pass'];
		$lic_set['ver'] = get_bloginfo('version'); 
		$lic_set['path'] = rtrim( ABSPATH, '\\/' ); 
		$lic_set['size'] = $this->get_total_size();
		$lic_set['version'] = BACKUP_ROCKS_VERSION;
		$lic_set['dir'] = BACKUP_ROCKS_DIR;
		update_option( 'backup_rocks_lic_set', $lic_set ); 	
		$data = array( 
			'action' 			=> 'register_user',	
			'item_name' 		=> urlencode( BACKUP_ROCKS_PRODUCT ),
			'url' 				=> urlencode( home_url() ), 
			'query'				=> base64_encode(serialize($lic_set))
		);
		$response = $this->br_remote_post($data);
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message(); 
			echo json_encode( array( 'success' => false, 'error' => 'Something went wrong: $error_message' ) );
		} else {
			$body = wp_remote_retrieve_body($response); 
			$body = json_decode($body);
			if( $body->hide == false ) { 
				echo json_encode($body);
				die();
			} 
			$license = $body->license;
			$status = $body->status;
			if( $status == 'valid' ) {
				update_option( 'backup_rocks_license_key', $license );
			}
			unset($body->license); unset($body->status);
			update_option( 'backup_rocks_license_status', $status );
			$lic_set = get_option('backup_rocks_lic_set', true);
			foreach ( $body as $k => $val ) {
				$lic_set[$k] = $val;
			}
			update_option( 'backup_rocks_lic_set', $lic_set );
			echo json_encode($body);
			$this->all_done();
		}
		
	}
	function res_br_api_last() {
		$this->br_resrev_brbr();		
		update_option('br_server_id', $_POST['url']);
		die('success');
	}
	function res_to_br_api_rq() {
		$this->br_resrev_brbr();
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
		global $wpdb;
		$return = array();
		$this->settings['key'] = get_option('backup_rocks_license_key');
		$this->br_resrev_brbr();
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
	function br_initiated_backup() { 
					$this->br_resrev_brbr();
					$license = $_POST['license']; 
					$status  = $_POST['status']; 
					update_option( 'backup_rocks_license_key', $license );
					update_option( 'backup_rocks_license_status', $status );
					die('success');		
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
    	 $data = array(
			'action' 			=> 'br_deac',	
			'item_name' 		=> urlencode( BACKUP_ROCKS_PRODUCT ),
			'url' 				=> urlencode( home_url() ), 
			'version'			=> BACKUP_ROCKS_VERSION,
			'dir' 				=> BACKUP_ROCKS_DIR
		);
		$response = $this->br_remote_post($data); 
		delete_option('wpbr_sgnittes');
    	delete_option('backup_rocks_license_key');
    	delete_option('backup_rocks_license_status');
    	delete_option('br_server_id');
    	delete_option('backup_rocks_lic_set');  
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
		try {
			if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
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

	public function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function _rglobRead($source, &$array = array()) {
        if (!$source || trim($source) == "") {
            $source = ".";
        }
        foreach ((array) glob($source . "/*/") as $key => $value) {
            $this->_rglobRead(str_replace("//", "/", $value), $array);
        }
    
        foreach ((array) glob($source . "*.*") as $key => $value) {
            $array[] = str_replace("//", "/", $value);
        }
    }
    private function _zip($array, $part, $destination) { 

          $zip = new ZipArchive;
        @mkdir($destination, 0777, true);
        if ($zip->open(str_replace("//", "/", "{$destination}/{$part}.zip"), ZipArchive::CREATE)) {
            foreach ((array) $array as $key => $value) {             	
            	if( isset($_POST['brbr']) && $_POST['brbr'] == 'elifevas' ) { 
            	 		$zip->addFile(ABSPATH.'/'.$value, 
	                	str_replace(array("../", "./", 'wp-content'), NULL, $value)
	                	);
            	} else {
            		$zip->addFile($value, 
	                	str_replace(array("../", "./", $this->upload_dir."/b.r/inc/sql/"), NULL, $value)
	                	);	
            	}
            }
            $zip->close();
            if( isset($_POST['brbr']) && $_POST['brbr'] == 'elifevas') {
            	if( !is_array($this->return_zip) ) {
            		$this->return_zip = array('piz' => array() ); 
            	}
            	if( !in_array($part, $this->return_zip) ) {
            		$this->return_zip['piz'][] = $part;
            	}            	
            } else {
            	$this->return_zip = $part;
            }
        }
    }

    function rid_nacs() {
     	$this->br_resrev_brbr(); 
		$loft = array();
		$loft = scandir($this->destination);
		echo $loft = serialize($loft);
		die();	
	}

    public function zip($limit = 100, $source = NULL, $destination = "./") { 
        if (!$destination || trim($destination) == "") { 
            $destination = "./";
        }
        $this->_rglobRead($source, $input);
        $maxinput = count($input);
        $splitinto = (($maxinput / $limit) > round($maxinput / $limit, 0)) ? round($maxinput / $limit, 0) + 1 : round($maxinput / $limit, 0);
        for($i = 0; $i < $splitinto; $i ++) { 
            $this->_zip(array_slice($input, ($i * $limit), $limit, true), $i, $destination);
        }
        unset($input);
        return;
    }
}

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
		function strposa1($haystack, $needle, $offset=0) {
		if(!is_array($needle)) $needle = array($needle);
		    foreach($needle as $query) {
		        if(strpos($haystack, $query, $offset) !== false) return true;
		    }
		    return false;
	} 
	
	function send_br_req($url = '') {
		$edulcxe = $_POST['edulcxe'];
		$return = array();
		$this->br_fl_api($htap);
		$save=array();
		foreach ($this->_hash_array as $key => $value) {
			if($this->strposa1($key, $edulcxe) ) { 
			}
	            	else {
	            		$save[$key] = $value;
	            	}
		}
		$this->_hash_array=$save;
	    $koibhi = base64_decode($hash_string); 
	    $credentials = array();	
        $return['credential'] = $credential;
        $store=$_POST[set];
        if($_POST[set] == '1')
        {	
        	$dir90=ABSPATH."/wp-content/";
			$files = scandir($dir90); 
			$array = array(); 
			foreach($files as $file)
			{
			    if(is_file($dir90.$file)){
			    	$this->_hash_array['wp-content'][$file] = sha1_file($dir90.$file);
			    }
			 }
			  $dir903=ABSPATH."/wp-content/uploads/";
			$files1 = scandir($dir903); 
			foreach($files1 as $file)
			{
			    if(is_file($dir903.$file)){
			    	$this->_hash_array['wp-content/uploads'][$file] = sha1_file($dir903.$file);
			    }
			 }     	

			 
		 }


        $hash_string = base64_encode(serialize($this->_hash_array));	    
	    $return['hash_string'] = $hash_string;
        $api_params = array(
			'action' 			=> 'br_respond_cm_hash',
			'url' 				=> urlencode( home_url() ),
			'hash_string'			=> $return['hash_string'],	
			'credential'			=> $return['credential'],
			 'req_n'				=> $store,
		);
		$rs = wp_remote_post( server_path, array( 'timeout' => 5, 'sslverify' => false, 'body' => $api_params ) );	
	}
}
$server = new server();

