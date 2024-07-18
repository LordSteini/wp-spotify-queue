<?php
/*
Plugin Name: Spotify Queue Plugin (EN-ENGLISH)
Description: Erlaubt es, sich im Backend bei Spotify anzumelden und im Frontend Songs zur Spotify-Queue hinzuzufügen.
Version: 4.1-final >>stable<<
Author: Paul Steins
*/

define('SPOTIFY_REDIRECT_URI', 'http://localhost:8888/wordpress/wp-admin/admin.php?page=spotify-queue&spotify-auth=1');

// Pufferung der Ausgabe starten
ob_start();

// Add menu item in admin panel
add_action('admin_menu', 'spotify_queue_menu');
function spotify_queue_menu() {
    add_menu_page('Spotify Queue Settings', 'Spotify Queue', 'manage_options', 'spotify-queue', 'spotify_queue_settings_page');
}

// Settings page content
function spotify_queue_settings_page() {
    if (isset($_GET['spotify-auth'])) {
        spotify_queue_handle_auth();
        return;
    }

    ?>
    <div class="wrap">
        <h1>Spotify Queue Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('spotify_queue_options_group');
            do_settings_sections('spotify_queue');
            submit_button();
            ?>
        </form>
        <a href="<?php echo spotify_queue_get_auth_url(); ?>">Mit Spotify verbinden</a>
    </div>
    <?php
}

// Register settings
add_action('admin_init', 'spotify_queue_register_settings');
function spotify_queue_register_settings() {
    register_setting('spotify_queue_options_group', 'spotify_client_id');
    register_setting('spotify_queue_options_group', 'spotify_client_secret');
    register_setting('spotify_queue_options_group', 'spotify_access_token');
    register_setting('spotify_queue_options_group', 'spotify_refresh_token');
    register_setting('spotify_queue_options_group', 'spotify_token_expires');
    register_setting('spotify_queue_options_group', 'spotify_queue_cooldown_time');

    add_settings_section('spotify_queue_section', 'Spotify API Einstellungen', null, 'spotify_queue');
    add_settings_field('spotify_client_id', 'Client ID', 'spotify_client_id_callback', 'spotify_queue', 'spotify_queue_section');
    add_settings_field('spotify_client_secret', 'Client Secret', 'spotify_client_secret_callback', 'spotify_queue', 'spotify_queue_section');
    add_settings_field('spotify_queue_cooldown_time', 'Cooldown Time (minutes)', 'spotify_queue_cooldown_time_callback', 'spotify_queue', 'spotify_queue_section');
}

function spotify_client_id_callback() {
    $client_id = get_option('spotify_client_id');
    echo '<input type="text" name="spotify_client_id" value="' . esc_attr($client_id) . '" />';
}

function spotify_client_secret_callback() {
    $client_secret = get_option('spotify_client_secret');
    echo '<input type="text" name="spotify_client_secret" value="' . esc_attr($client_secret) . '" />';
}

function spotify_queue_cooldown_time_callback() {
    $cooldown_time = get_option('spotify_queue_cooldown_time', 20); // Default to 20 minutes
    echo '<input type="number" min="1" name="spotify_queue_cooldown_time" value="' . esc_attr($cooldown_time) . '" /> minutes';
}

// Handle Spotify authentication
function spotify_queue_handle_auth() {
    $client_id = get_option('spotify_client_id');
    $client_secret = get_option('spotify_client_secret');
    $code = $_GET['code'];

    $response = wp_remote_post('https://accounts.spotify.com/api/token', array(
        'body' => array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => SPOTIFY_REDIRECT_URI,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ),
    ));

    if (is_wp_error($response)) {
        echo 'Error during authentication.';
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['access_token'])) {
        update_option('spotify_access_token', $data['access_token']);
        update_option('spotify_refresh_token', $data['refresh_token']);
        update_option('spotify_token_expires', time() + $data['expires_in']);
    }

    wp_redirect(admin_url('admin.php?page=spotify-queue'));
    exit;
}

// Get Spotify authentication URL
function spotify_queue_get_auth_url() {
    $client_id = get_option('spotify_client_id');
    $redirect_uri = SPOTIFY_REDIRECT_URI;
    $scopes = 'user-modify-playback-state user-read-playback-state';

    return 'https://accounts.spotify.com/authorize?response_type=code&client_id=' . $client_id . '&redirect_uri=' . urlencode($redirect_uri) . '&scope=' . urlencode($scopes);
}

// Refresh the access token
function spotify_queue_refresh_access_token() {
    $client_id = get_option('spotify_client_id');
    $client_secret = get_option('spotify_client_secret');
    $refresh_token = get_option('spotify_refresh_token');

    $response = wp_remote_post('https://accounts.spotify.com/api/token', array(
        'body' => array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ),
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['access_token'])) {
        update_option('spotify_access_token', $data['access_token']);
        update_option('spotify_token_expires', time() + $data['expires_in']);
        return $data['access_token'];
    }

    return false;
}

// Get access token, refreshing if necessary
function spotify_queue_get_access_token() {
    $access_token = get_option('spotify_access_token');
    $expires = get_option('spotify_token_expires');

    if (time() > $expires) {
        $access_token = spotify_queue_refresh_access_token();
    }

    return $access_token;
}

// AJAX handler to search songs
add_action('wp_ajax_spotify_queue_search', 'spotify_queue_search');
add_action('wp_ajax_nopriv_spotify_queue_search', 'spotify_queue_search');
function spotify_queue_search() {
    $query = $_POST['query'];
    $access_token = spotify_queue_get_access_token();

    $response = wp_remote_get('https://api.spotify.com/v1/search?type=track&q=' . urlencode($query), array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        )
    ));

    if (is_wp_error($response)) {
        wp_send_json_error('Search request failed.');
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    wp_send_json_success(array('results' => $data['tracks']['items']));
}

// AJAX handler to add song to queue
add_action('wp_ajax_spotify_queue_add', 'spotify_queue_add');
add_action('wp_ajax_nopriv_spotify_queue_add', 'spotify_queue_add');
function spotify_queue_add() {
    $uri = $_POST['uri'];
    $song_name = sanitize_text_field($_POST['song_name']);
    $song_artist = sanitize_text_field($_POST['song_artist']);
    $access_token = spotify_queue_get_access_token();

    // Check if cooldown time option exists, default to 20 minutes if not set
    $cooldown_time = get_option('spotify_queue_cooldown_time', 20) * 60; //Convert minutes to seconds

    // Check if the song is in cooldown period
    $last_played = get_transient('spotify_last_played_' . $uri);
    if ($last_played !== false && (time() - $last_played) < $cooldown_time) {
        $remaining_time = ceil(($cooldown_time - (time() - $last_played)) / 60); // Convert remaining seconds to minutes
        $error_data = array(
            'song_name' => $song_name,
            'song_artist' => $song_artist,
            'remaining_time' => $remaining_time,
            'cooldown_minutes' => get_option('spotify_queue_cooldown_time', 20) // Pass the cooldown time to JavaScript
        );
        wp_send_json_error($error_data);
        return;
    }

    $response = wp_remote_post('https://api.spotify.com/v1/me/player/queue?uri=' . urlencode($uri), array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        )
    ));

    if (is_wp_error($response)) {
        wp_send_json_error('Add to queue request failed.');
        return;
    }

    // Set transient for the song URI to track last played time
    set_transient('spotify_last_played_' . $uri, time(), $cooldown_time);

    wp_send_json_success();
}

// Enqueue frontend script and style
add_action('wp_enqueue_scripts', 'spotify_queue_enqueue_assets');
function spotify_queue_enqueue_assets() {
    wp_enqueue_script('spotify-queue', plugin_dir_url(__FILE__) . 'spotify-queue.js', array('jquery'), null, true);
    wp_enqueue_style('spotify-queue', plugin_dir_url(__FILE__) . 'spotify-queue.css');
    wp_localize_script('spotify-queue', 'spotifyQueue', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'cooldown_minutes' => get_option('spotify_queue_cooldown_time', 20) // Pass the cooldown time to JavaScript
    ));
}

// Shortcode to display search form
add_shortcode('spotify_queue_search_form', 'spotify_queue_search_form');
function spotify_queue_search_form() {
    ob_start();
    ?>
    <div id="spotify-queue-search">
        <input type="text" id="spotify-search-input" placeholder="Suche nach einem Song oder Interpreten...">
        <button id="spotify-search-button">Search</button>
    </div>
    <div id="spotify-search-results"></div>
    <?php
    return ob_get_clean();
}

// Shortcode to display search and queue interface
add_shortcode('spotify_queue', 'spotify_queue_shortcode');
function spotify_queue_shortcode() {
    ob_start();
    ?>
    <div class="spotify-container">
        <input type="text" id="spotify-search-input" placeholder="Suche nach einem Song oder Interpreten...">
        <button id="spotify-search-button">Suchen</button>
        <div id="spotify-search-results"></div>
    </div>
    <?php
    return ob_get_clean();
}

?>
