<?php
/*
Plugin Name: Frontity Image ID Extraction
Plugin URI: 
Description: Adds attribute data-attachment-id to images in a new field content.raw.
Version: 0.1.0
Author: Frontity
Author URI: https://frontity.com/
License: GPL v3
Copyright: Worona Labs SL
 */


function frontity_image_id_extraction_add_image_ids($data, $post_type, $request) {
  global $post;
  $post = get_post($data->data['id']);
  $data->data['content']['raw'] = $post->post_content;

  return $data;
}

function frontity_image_id_extraction_add_custom_post_types_filters($post_type) {
  add_filter('rest_prepare_' . $post_type, 'frontity_image_id_extraction_add_image_ids', 9, 3);
}

add_action('registered_post_type', 'frontity_image_id_extraction_add_custom_post_types_filters');