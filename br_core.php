<?php
define( 'license', trim( get_option( 'backup_rocks_license_key' ) ) );
define( 'server_path', trim( get_option( 'br_server_id' ) ).'/wp-admin/admin-ajax.php' );

/* CAPTURING EVENTS FOR WP_POST TABLE */
add_action( 'edit_post',                      'action_post_handler', 10, 2 );
add_action( 'wp_insert_post',                 'action_post_handler', 10, 2 );
add_action( 'add_attachment',                 'action_post_handler', 10, 2 );
add_action( 'edit_attachment',                'action_post_handler', 10, 2 );
add_action( 'delete_post',                    'action_post_handler', 10, 2 );
add_action( 'delete_attachment',              'action_post_handler', 10, 2 );

/* CAPTURING EVENTS FOR WP_POSTMETA TABLE */
add_action( 'added_post_meta',                'postmeta_insert_handler', 10, 4 );
add_action( 'update_post_meta',               'postmeta_update_handler', 10, 4 );
add_action( 'updated_post_meta',              'postmeta_update_handler', 10, 4 );
add_action( 'delete_post_meta',               'postmeta_delete_handler', 10, 4 );
add_action( 'deleted_post_meta',              'postmeta_delete_handler', 10, 4 );


/* CAPTURING EVENTS FOR WP_TERM AND WP_TERM_TAXONOMY TABLE */
add_action( 'created_term',                   'action_term_add', 10, 3 );
add_action( 'edited_term',                    'action_term_update', 10, 3 );

/* CAPTURING EVENTS FOR WP_COMMENTS TABLE */
add_action( 'wp_insert_comment',              'comment_action_handler', 99, 2 );
add_action( 'comment_post',                   'comment_action_update' );
add_action( 'wp_set_comment_status',          'comment_action_update' );
add_action( 'untrashed_comment',              'comment_action_update' );
add_action( 'trashed_comment',                'comment_action_update' );
add_action( 'edit_comment',                   'comment_action_update' );
add_action( 'delete_comment',                 'comment_action_update' );


/* CAPTURING EVENTS FOR WP_COMMENTMETA TABLE */
add_action('added_comment_meta',              'commentmeta_insert_handler', 10, 4);
add_action('updated_comment_meta',            'commentmeta_update_handler', 10, 4);
add_action('delete_comment_meta',             'commentmeta_delete_handler', 10, 4);

/* CAPTURING EVENTS FOR WP_OPTIONS */
add_action( 'updated_option',                 'option_update_handler' );
add_action( 'added_option',                   'option_insert_handler' );
add_action( 'delete_option',                  'option_delete_handler' );

/* CAPTURING EVENTS FOR WP_OPTIONS */
add_action( 'user_register',                  'user_insert_handler' );
add_action( 'password_reset',                 'user_update_handler' );
add_action( 'profile_update',                 'user_update_handler');
add_action( 'delete_user',                    'user_delete_handler' );

/* CAPTURING EVENTS FOR WP_USERMETA TABLE */
add_action( 'added_user_meta',                'usermeta_insert_handler', 10, 4);
add_action( 'updated_user_meta',              'usermeta_update_handler', 10, 4);
add_action( 'delete_user_meta',               'usermeta_delete_handler', 10, 4);

function action_post_handler($post_id, $post = array() ) {
  if ( empty($post) ) $post = get_post($post_id);
  $action                   = strtotime($post->post_date) == strtotime($post->post_modified) ? 'insert_post' : 'insert_post_update';

  global $wpdb;
  $time                     = strtotime(date("Y-m-d H:i:s"));
  $term_relationships       = $wpdb->prefix.'term_relationships';
  $term_rel                 = $wpdb->get_results("select * from $term_relationships where object_id = $post_id");  

  $params                   = array (
    'action'                => $action,
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'post'                  => base64_encode(serialize($post)),
    'term_rel'              => base64_encode(serialize($term_rel)),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post(
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function postmeta_insert_handler($meta_id, $post_id, $meta_key, $meta_value) {
  if ( $meta_key == '_edit_lock' or $meta_key == '_edit_last' ) return false;
  global $wpdb; 
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_postmeta',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'postmeta'              => base64_encode( serialize( array($meta_id, $post_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );
  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function postmeta_update_handler($meta_id, $post_id, $meta_key, $meta_value) {
  global $wpdb;
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_postmeta_update',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'postmeta'              => base64_encode( serialize( array($meta_id, $post_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

    $rs                     = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout' => 10, 
                                  'sslverify' => false, 
                                  'body' => $params 
                                  )
                                );

}

function postmeta_delete_handler($meta_id, $post_id, $meta_key, $meta_value) {
  global $wpdb;
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_postmeta_delete',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'postmeta'              => base64_encode( serialize( array($meta_id, $post_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function action_term_add( $term_id, $tt_id, $taxonomy ) {
  global $wpdb;
  $terms                    = $wpdb->prefix.'terms';
  $term_taxonomy            = $wpdb->prefix.'term_taxonomy';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $terms                    = $wpdb->get_results("select * from $terms where term_id = $term_id");
  $term_tax                 = $wpdb->get_results("select * from $term_taxonomy where term_id = $term_id");
  $params                   = array (
    'action'                => 'insert_terms',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'terms'                 => base64_encode( serialize( $terms ) ),
    'term_tax'              => base64_encode( serialize( $term_tax ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function action_term_update( $term_id, $tt_id, $taxonomy ) {
  global $wpdb;
  $terms                    = $wpdb->prefix.'terms';
  $term_taxonomy            = $wpdb->prefix.'term_taxonomy';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $terms                    = $wpdb->get_results("select * from $terms where term_id = $term_id");
  $term_tax                 = $wpdb->get_results("select * from $term_taxonomy where term_id = $term_id");
  $params                   = array (
    'action'                => 'insert_terms_update',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'terms'                 => base64_encode( serialize( $terms ) ),
    'term_tax'              => base64_encode( serialize( $term_tax ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function comment_action_handler($comment_id, $comm_obj ) {
  global $wpdb;
  $commentmeta              = $wpdb->prefix.'commentmeta';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_comment',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'comm_obj'              => base64_encode( serialize( $comm_obj ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() ) 
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function comment_action_update($comment_id ) {
  global $wpdb;
  $comm_obj                 = $wpdb->prefix.'comments';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $comm_obj                 = $wpdb->get_results("select * from $comm_obj where comment_id = $comment_id"); 
  $params                   = array (
    'action'                => 'insert_comment_update',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'comm_obj'              => base64_encode( serialize( $comm_obj ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );
  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function commentmeta_insert_handler($meta_id, $comment_id, $meta_key, $meta_value) {
  global $wpdb; 
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_commentmeta',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'comm_obj'              => base64_encode( serialize( array($meta_id, $comment_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function commentmeta_update_handler($meta_id, $comment_id, $meta_key, $meta_value) {
  global $wpdb; 
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_commentmeta_update',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'comm_obj'              => base64_encode( serialize( array($meta_id, $comment_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function commentmeta_delete_handler($meta_id, $comment_id, $meta_key, $meta_value) {
  global $wpdb; 
  $time                     = strtotime( date("Y-m-d H:i:s") ); 
  $params                   = array (
    'action'                => 'insert_commentmeta_delete',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'comm_obj'              => base64_encode( serialize( array($meta_id, $comment_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function usermeta_insert_handler($meta_id, $user_id, $meta_key, $meta_value) {
  global $wpdb; 
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_usermeta',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'usermeta'              => base64_encode( serialize( array($meta_id, $user_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function usermeta_update_handler($meta_id, $user_id, $meta_key, $meta_value) {
  global $wpdb; 
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_usermeta_update',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'usermeta'              => base64_encode( serialize( array($meta_id, $user_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function usermeta_delete_handler($meta_id, $user_id, $meta_key, $meta_value) {
  global $wpdb;
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $params                   = array (
    'action'                => 'insert_usermeta_delete',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'usermeta' => base64_encode( serialize( array($meta_id, $user_id, $meta_key, $meta_value) ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}

function user_insert_handler($user_id) {
  global $wpdb;
  $user = $wpdb->prefix.'users';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $user = $wpdb->get_results( "select * from $user where ID = $user_id" );

  $params                   = array (    'action'                => 'insert_user',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'user' => base64_encode( serialize( $user ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );  
}

function user_update_handler($user_id) {
 global $wpdb;
  $user = $wpdb->prefix.'users';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $user = $wpdb->get_results( "select * from $user where ID = $user_id" );
  $params                   = array (
    'action'                => 'insert_user_update',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'user'                  => base64_encode( serialize( $user ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );   
}

function user_delete_handler($user_id) {
  global $wpdb;
  $user                     = $wpdb->prefix.'users';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $user                     = $wpdb->get_results( "select * from $user where ID = $user_id" );
  $params                   = array (
    'action'                => 'insert_user_delete',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'user'                  => base64_encode( serialize( $user ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}


function option_insert_handler($i) {
  global $wpdb;
  $opts                     = $wpdb->prefix.'options';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $opts                     = $wpdb->get_results( "select * from $opts where option_name = '$i'" );
  $params                   = array (
    'action'                => 'insert_option',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'opts'                  => base64_encode( serialize( $opts ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
  );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}


function option_update_handler($i) {
  global $wpdb;
  $opts                     = $wpdb->prefix.'options';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $opts                     = $wpdb->get_results( "select * from $opts where option_name = '$i'" );

  $params                   = array (
    'action'                => 'insert_option_update',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'opts'                  => base64_encode( serialize( $opts ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );
  
  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                );
}



function option_delete_handler($i) {
  global $wpdb;
  $opts                     = $wpdb->prefix.'options';
  $time                     = strtotime( date("Y-m-d H:i:s") );
  $opts                     = $wpdb->get_results( "select * from $opts where option_name = '$i'" );
  $params                   = array (
    'action'                => 'insert_option_delete',
    'prefix'                => $wpdb->prefix,
    'time'                  => $time,
    'opts'                  => base64_encode( serialize( $opts ) ),
    'license'               => urlencode( license ),
    'url'                   => urlencode( home_url() )
    );

  $rs                       = wp_remote_post( 
                                server_path, 
                                array( 
                                  'timeout'   => 10, 
                                  'sslverify' => false, 
                                  'body'      => $params 
                                  )
                                ); 
}