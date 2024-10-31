<?php
/*
Plugin Name: Really Simple Feedback
Plugin URI: https://wordpress.org/plugins/really-simple-feedback
Description: A really simple way to get feedback from your users.
Version: 1.0.0
Author: Push Labs
Author URI: https://pushlabs.co
Text Domain: really-simple-feedback
Domain Path: /languages
*/

// todo https://make.wordpress.org/core/2016/10/04/custom-bulk-actions/
// todo https://wordpress.stackexchange.com/questions/14973/row-actions-for-custom-post-types

namespace PushLabs\ReallySimpleFeedback;

/**
 * Exit if accessed directly
 */
if (!defined('ABSPATH')) {
  exit();
}

/**
 * Enqueue frontend scripts.
 *
 * @since 1.0.0
 * @return void
 */
function enqueue_scripts() {
  wp_enqueue_script(
    'really-simple-feedback',
    plugin_dir_url(__FILE__) . 'dist/really-simple-feedback.js',
    array(),
    '1.0.0',
    true
  );

  wp_enqueue_style(
    'really-simple-feedback',
    plugin_dir_url(__FILE__) . 'dist/really-simple-feedback.css',
    array(),
    '1.0.0'
  );

  wp_localize_script('really-simple-feedback', 'rsf_localized', array(
    'site_url' => get_site_url(),
    'feedback_button_text' => __('Feedback', 'really-simple-feedback'),
    'thank_you_message' => __(
      'Thank you for your feedback!',
      'really-simple-feedback'
    ),
    'widget_header_text' => __('Share Your Feedback', 'really-simple-feedback'),
    'satisfaction_message' => __(
      'Are you satisfied with this page?',
      'really-simple-feedback'
    ),
    'submit_text' => __('Send', 'really-simple-feedback'),
    'unsatisfied_placeholder_text' => __(
      'What can we do better?',
      'really-simple-feedback'
    ),
    'satisfied_placeholder_text' => __(
      'What do you like the most?',
      'really-simple-feedback'
    ),
    'comment_section_error_message' => __(
      'Your feedback is required.',
      'really-simple-feedback'
    ),
    'general_error_message' => __(
      'Something went wrong. Try again in a few minutes.',
      'really-simple-feedback'
    )
  ));
}
add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_scripts');

/**
 * Enqueue our admin scripts.
 *
 * @since 1.0.0
 * @param String $hook_suffix The page name.
 * @return void
 */
function enqueue_admin_scripts($hook_suffix) {
  $post_type = 'rsf';

  if (in_array($hook_suffix, array('post.php', 'post-new.php', 'edit.php'))) {
    $screen = get_current_screen();

    if (is_object($screen) && $post_type == $screen->post_type) {
      wp_enqueue_script(
        'really-simple-feedback-admin',
        plugin_dir_url(__FILE__) . 'dist/really-simple-feedback-admin.js',
        array(),
        '1.0.0',
        true
      );

      wp_enqueue_style(
        'really-simple-feedback-admin',
        plugin_dir_url(__FILE__) . 'dist/really-simple-feedback-admin.css',
        array(),
        '1.0.0'
      );

      wp_localize_script('really-simple-feedback-admin', 'rsf_localized', array(
        'site_url' => get_site_url(),
        'nonce' => wp_create_nonce('wp_rest'),
        'mark_as_read_text' => 'Mark as Read',
        'mark_as_unread_text' => 'Mark as Unread'
      ));
    }
  }
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_scripts');

/**
 * Register our Custom Post Type: rsf
 *
 * @since 1.0.0
 * @return void
 */
function create_post_type() {
  $labels = array(
    'name' => _x(
      'Really Simple Feedback',
      'Post Type General Name',
      'really-simple-feedback'
    ),
    'singular_name' => _x(
      'Feedback',
      'Post Type Singular Name',
      'really-simple-feedback'
    ),
    'menu_name' => __('Feedback', 'really-simple-feedback'),
    'name_admin_bar' => __('Feedback', 'really-simple-feedback')
  );

  $args = array(
    'label' => __('Post Type', 'really-simple-feedback'),
    'description' => __('Post Type Description', 'really-simple-feedback'),
    'labels' => $labels,
    'supports' => array('custom-fields'),
    'public' => false,
    'show_ui' => true,
    'show_in_admin_bar' => false
  );

  register_post_type('rsf', $args);
}
add_action('init', __NAMESPACE__ . '\\create_post_type', 0);

/**
 * Create our feedback REST API endpoint for receiving feedback.
 *
 * @since 1.0.0
 * @param Request $request
 * @return WP_REST_Response $response The REST API response.
 */
function create_feedback_endpoint($request) {
  // Get the rating
  $rating = $request['rating'];

  // If the rating does not exist, quit.
  if (is_null($rating)) {
    return new \WP_Error(
      'no_rating',
      __(
        'There was no rating provided. The rating must be either "satisfied" or "unsatisfied"',
        'really-simple-feedback'
      ),
      array('status' => 400)
    );
  }

  // If the rating is not equal to "satisfied" or "unsatisfied", quit.
  if ($rating !== 'satisfied' && $rating !== 'unsatisfied') {
    return new \WP_Error(
      'incorrect_rating',
      __(
        'The rating must be either "satisfied" or "unsatisfied"',
        'really-simple-feedback'
      ),
      array('status' => 400)
    );
  }

  // Get the comment.
  $comment = $request['comment'];
  $comment = sanitize_text_field($request['comment']);

  // If the comment does not exist, quit.
  if (empty($comment)) {
    return new \WP_Error(
      'no_comment',
      __('There was no comment provided.', 'really-simple-feedback'),
      array('status' => 400)
    );
  }

  // Get the referred url and user agent if they exist.
  $url = $request->get_header('referer');
  $user_agent = $request['userAgent'];

  // The create post array
  $post = array(
    'post_type' => 'rsf',
    'post_status' => 'publish'
  );

  // Create the post.
  $post_id = wp_insert_post($post);

  // Add our data to the post.
  add_post_meta($post_id, 'rating', $rating, true);
  add_post_meta($post_id, 'comment', $comment, true);
  add_post_meta($post_id, 'url', $url, true);
  add_post_meta($post_id, 'user_agent', $user_agent, true);

  // Create our response to be sent to the client.
  $response = new \WP_REST_Response(array(
    'message' => 'Successfully created feedback #' . $post_id
  ));
  $response->set_status(201);

  return $response;
}

/**
 * Create our "Mark as read" REST API endpoint
 *
 * @since 1.0.0
 * @param Request $request
 * @return WP_REST_Response $response The REST API response.
 */
function create_mark_as_read_endpoint($request) {
  // Get the post ID
  $post = get_post($request['id']);

  // Make sure this post has the post type "rsf"
  if ($post->post_type !== 'rsf') {
    return new \WP_Error(
      'wrong_post_type',
      __('This post is not of post type "rsf"', 'really-simple-feedback'),
      array('status' => 400)
    );
  }

  // Set the "marked_as_read" post meta to true.
  add_post_meta($post->ID, 'marked_as_read', true);

  // Create our response to be sent to the client.
  $response = new \WP_REST_Response(array(
    'message' => 'Successfully marked feedback #' . $post->ID . ' as read.'
  ));
  $response->set_status(200);

  return $response;
}

/**
 * Create our "Mark as unread" REST API endpoint.
 *
 * @since 1.0.0
 * @param Request $request
 * @return WP_REST_Response $response The REST API response.
 */
function create_mark_as_unread_endpoint($request) {
  // Get the post ID`
  $post = get_post($request['id']);

  // Make sure this post has the post type "rsf"
  if ($post->post_type !== 'rsf') {
    return new \WP_Error(
      'wrong_post_type',
      __('This post is not of post type "rsf"', 'really-simple-feedback'),
      array('status' => 400)
    );
  }

  // Remove the "marked_as_read" post meta signifying the feedback post has no longer been read.
  delete_post_meta($post->ID, 'marked_as_read');

  // Create our response to be sent to the client.
  $response = new \WP_REST_Response(array(
    'message' => 'Successfully marked feedback #' . $post->ID . ' as unread.'
  ));
  $response->set_status(200);

  return $response;
}

add_action('rest_api_init', function () {
  // TODO setup args to sanitize.
  register_rest_route('really-simple-feedback/v1', '/feedback', array(
    'methods' => 'POST',
    'callback' => __NAMESPACE__ . '\\create_feedback_endpoint',
    'args' => array()
  ));

  register_rest_route(
    'really-simple-feedback/v1',
    '/mark_as_read/(?P<id>\d+)',
    array(
      'methods' => 'POST',
      'callback' => __NAMESPACE__ . '\\create_mark_as_read_endpoint',
      'args' => array(),
      'permission_callback' => function () {
        return current_user_can('edit_others_posts');
      }
    )
  );

  register_rest_route(
    'really-simple-feedback/v1',
    '/mark_as_unread/(?P<id>\d+)',
    array(
      'methods' => 'POST',
      'callback' => __NAMESPACE__ . '\\create_mark_as_unread_endpoint',
      'args' => array(),
      'permission_callback' => function () {
        return current_user_can('edit_others_posts');
      }
    )
  );
});

/**
 * Set the columns of the rsf custom post type.
 *
 * @since 1.0.0
 * @param Array $columns The array of columns in post type table.
 * @return Array $columns
 */
function set_rsf_columns($columns) {
  return array(
    'cb' => '<input type="checkbox" />',
    'rating' => __('Rating', 'really-simple-feedback'),
    'comment' => __('Comment', 'really-simple-feedback'),
    'url' => __('Referred URL', 'really-simple-feedback'),
    'user_agent' => __('User Agent', 'really-simple-feedback'),
    'date' => __('Date', 'really-simple-feedback')
  );
}
add_filter('manage_rsf_posts_columns', __NAMESPACE__ . '\\set_rsf_columns');

/**
 * Dictate what is displayed in each column for the rsf post type.
 *
 * @since 1.0.0
 * @param String $column The column name
 * @param Int $post_id The post ID
 * @return void
 */
function custom_rsf_column($column, $post_id) {
  switch ($column) {
    case 'rating':
      $rating = get_post_meta($post_id, 'rating', true);
      switch ($rating) {
        case 'unsatisfied':
          echo 'Unsatisfied';
          break;
        case 'satisfied':
          echo 'Satisfied';
          break;
      }
      break;
    case 'comment':
      echo get_post_meta($post_id, 'comment', true);
      break;
    case 'url':
      $url = get_post_meta($post_id, 'url', true);

      if ($url) {
        $url = '<a target="_blank" href="' . $url . '">' . $url . '</a>';
      }

      echo $url;
      break;
    case 'user_agent':
      $user_agent = get_post_meta($post_id, 'user_agent', true);
      echo $user_agent;
      break;
  }
}
add_action(
  'manage_rsf_posts_custom_column',
  __NAMESPACE__ . '\\custom_rsf_column',
  10,
  2
);

// function register_bulk_actions($bulk_actions) {
//   unset($bulk_actions['edit']);
//   $bulk_actions['mark_as_read'] = __('Mark as Read', 'really-simple-feedback');
//   $bulk_actions['mark_as_unread'] = __(
//     'Mark as Unread',
//     'really-simple-feedback'
//   );

//   return $bulk_actions;
// }

/**
 * Register/remove bulk actions from the rsf post type
 *
 * @since 1.0.0
 * @param Array $bulk_actions
 * @return Array $bulk_actions
 */
function register_bulk_actions($bulk_actions) {
  // Remove the edit option from the bulk actions menu.
  unset($bulk_actions['edit']);

  return $bulk_actions;
}
add_filter('bulk_actions-edit-rsf', __NAMESPACE__ . '\\register_bulk_actions');

// function my_bulk_action_handler($redirect_to, $action_name, $post_ids) {
//   switch ($action_name) {
//     case 'mark_as_read':
//       foreach ($post_ids as $post_id) {
//         add_post_meta($post_id, 'marked_as_read', true, true);
//       }

//       $redirect_to = add_query_arg(
//         'bulk_posts_processed',
//         count($post_ids),
//         $redirect_to
//       );
//       return $redirect_to;
//       break;
//     default:
//       return $redirect_to;
//   }
// }
// add_filter(
//   'handle_bulk_actions-edit-post',
//   __NAMESPACE__ . '\\my_bulk_action_handler'
// );

/**
 * The actions for the rsf post type
 *
 * @since 1.0.0
 * @param Array $actions Array of post type actions.
 * @param Object $post The post object
 * @return Array $actions
 */
function register_actions($actions, $post) {
  // If the post is not of type "rsf", quit.
  if ($post->post_type !== 'rsf') {
    return $actions;
  }

  // Remove the edit and quick edit actions.
  // unset($actions['edit']);
  unset($actions['inline hide-if-no-js']);

  // Get the marked as read post meta value
  $marked_as_read = get_post_meta($post->ID, 'marked_as_read', true);

  /**
   * These anchor classes have an event listener attached via our admin javascript.
   * Additionally, the data-postid is used to target the correct feedback post
   * when we are setting the state via the REST API.
   */
  if (empty($marked_as_read)) {
    $actions['mark_as_read'] =
      '<a class="rsf-js-mark-as-read" data-postid="' .
      $post->ID .
      '" href="#">Mark as Read</a>';
  } else {
    $actions['mark_as_unread'] =
      '<a class="rsf-js-mark-as-unread" data-postid="' .
      $post->ID .
      '" href="#">Mark as Unread</a>';
  }

  return $actions;
}
add_filter('post_row_actions', __NAMESPACE__ . '\\register_actions', 10, 2);

/**
 * Add classes to the posts in the rsf post type.
 *
 * @since 1.0.0
 * @param Array $classes Array of classes.
 * @return Array $classes
 */
function add_classes_to_posts_list($classes) {
  // If we are not in the admin view, quit.
  if (!is_admin()) {
    return $classes;
  }

  // Get the global $post object.
  global $post;

  // If the post is not of type "rsf", quit.
  if ($post->post_type !== 'rsf') {
    return $classes;
  }

  // Add the "rsf-post" class so we can modify the appearance via CSS.
  $classes[] = 'rsf-post';

  // Get the marked as read post meta value.
  $marked_as_read = get_post_meta($post->ID, 'marked_as_read', true);

  // If the marked_as_read post meta value is empty, add the 'rsf-post-unread' CSS class.
  if (empty($marked_as_read)) {
    $classes[] = 'rsf-post-unread';
  }

  return $classes;
}
add_filter('post_class', __NAMESPACE__ . '\\add_classes_to_posts_list');
