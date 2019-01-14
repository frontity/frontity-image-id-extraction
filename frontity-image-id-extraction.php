<?php
/*
Plugin Name: Frontity Image ID Extraction
Plugin URI: 
Description: Wordpress plugin that adds data-attachment-id to images in a new field content.raw.
Version: 1.0.1
Author: Frontity
Author URI: https://frontity.com/
License: GPL v3
Copyright: Worona Labs SL
 */


function frontity_image_id_extraction_update_image_id_transient_keys( $new_transient_key ) {
  $transient_keys = get_option('image_id_transient_keys');
  $transient_keys[]= $new_transient_key;
  update_option( 'image_id_transient_keys', $transient_keys );
}

function frontity_image_id_extraction_purge_image_id_transient_keys() {
  $transient_keys = get_option( 'image_id_transient_keys' );
  foreach( $transient_keys as $t ) {
    delete_transient( $t );
  }
  update_option( 'image_id_transient_keys', array() );
} 

function frontity_image_id_extraction_get_attachment_id($url) {
  $transient_name = 'frt_' . md5( $url );
  $attachment_id = get_transient( $transient_name );
  $transient_miss = $attachment_id === false;

  if ( $transient_miss ) {
    $attachment_id = 0;
    $dir = wp_upload_dir();
    $uploadsPath = parse_url($dir['baseurl'])['path'];
    $isInUploadDirectory = strpos($url, $uploadsPath . '/') !== false;
    $wpHost = parse_url($dir['baseurl'])['host'];
    $isNotExternalDomain = strpos($url, $wpHost . '/') !== false;
    if ($isInUploadDirectory && $isNotExternalDomain) {
      $file = basename(urldecode($url));
      $query_args = array(
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'fields'      => 'ids',
        'meta_query'  => array(
          array(
            'value'   => $file,
            'compare' => 'LIKE',
            'key'     => '_wp_attachment_metadata',
          ),
        )
      );
      $query = new WP_Query( $query_args );
      if ( $query->have_posts() ) {
        foreach ( $query->posts as $post_id ) {
          $meta = wp_get_attachment_metadata( $post_id );
          $original_file       = basename( $meta['file'] );
          $cropped_image_files = wp_list_pluck( $meta['sizes'], 'file' );
          if ( $original_file === $file || in_array( $file, $cropped_image_files ) ) {
            $attachment_id = $post_id;
            break;
          }
        }
      }
    }

    set_transient( $transient_name, $attachment_id, 0 ); // never expires
    frontity_image_id_extraction_update_image_id_transient_keys( $transient_name );
  }

  return array(
    'id'   => intval($attachment_id),
    'miss' => $transient_miss,
  );
}

function frontity_image_id_extraction_add_image_ids($data, $post_type, $request) {
  global $wpdb;

  if(!class_exists('simple_html_dom')) { require_once('libs/simple_html_dom.php'); }
  
  // remove image ids stored in transients if requested
  if ($request->get_param('purgeContentMediaTransients') === 'true') {
    frontity_image_id_extraction_purge_image_id_transient_keys();
  }

  $dom = new simple_html_dom();

  $post = get_post($data->data['id']);
  $postContent = $post->post_content;

  $dom->load($postContent);
  $imgIds = [];
  foreach($dom->find('img') as $image) {
    $dataAttachmentId = $image->getAttribute('data-attachment-id');
    $class = $image->getAttribute('class');
    preg_match('/\bwp-image-(\d+)\b/', $class, $wpImage);
    if ($dataAttachmentId) {
      $imgIds[] = intval($dataAttachmentId);
    } elseif ($wpImage && isset($wpImage[1])) {
      $image->setAttribute('data-attachment-id', $wpImage[1]);
      // $image->setAttribute('data-attachment-id-source', 'wp-image-class');
      $imgIds[] = intval($wpImage[1]);
    } else {
      $result = frontity_image_id_extraction_get_attachment_id($image->src);
      $id = $result['id'];
      $miss = $result['miss'];
      // $image->setAttribute('data-attachment-id-source', 'wp-query-transient-' . ($miss ? 'miss' : 'hit'));
      if ($id !== 0) {
        $image->setAttribute('data-attachment-id', $id);
        $imgIds[] = intval($id);
      }
    }
  }
  if (sizeof($imgIds) > 0) {
    $media_url = add_query_arg(array(
      'include' => join(',', $imgIds),
      'per_page' => sizeof($imgIds),
    ),
      rest_url('wp/v2/media')
    );
    $data->add_links(array(
      'wp:contentmedia' => array(
        'href' => $media_url,
        'embeddable' => true,
      )
    ));
  }
  $html = $dom->save();
  if ($html) $data->data['content']['raw'] = $html;
  $data->data['content_media'] = $imgIds;
  return $data;
}

function frontity_image_id_extraction_add_custom_post_types_filters($post_type) {
  add_filter('rest_prepare_' . $post_type, 'frontity_image_id_extraction_add_image_ids', 9, 3);
}

add_action('registered_post_type', 'frontity_image_id_extraction_add_custom_post_types_filters');