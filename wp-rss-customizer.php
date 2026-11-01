<?php
/**
 * Plugin Name:       RSS Customizer
 * Plugin URI:        https://github.com/Azanumer/wp-rss-customizer
 * Description:       Take control of your WordPress feed: delay new posts, append a footer (copyright/attribution), exclude categories and cap the item count — so scrapers get less and readers get more.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wp-rss-customizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WRC_VERSION', '1.0.0' );
define( 'WRC_OPTION', 'wrc_settings' );

/**
 * Default settings.
 *
 * @return array
 */
function wrc_get_settings() {
	$defaults = array(
		'delay_minutes'     => 0,
		'footer'            => '',
		'excluded_cats'     => array(),
		'item_count'        => 0, // 0 = use the WordPress default (Settings → Reading).
		'disable_comments'  => 0,
	);
	$saved = get_option( WRC_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		return $defaults;
	}
	$merged = array_merge( $defaults, $saved );
	$merged['excluded_cats'] = array_map( 'intval', (array) $merged['excluded_cats'] );
	return $merged;
}

/**
 * Apply feed-only query tweaks: delay, category exclusion, item count.
 *
 * @param WP_Query $query The query object.
 */
function wrc_modify_feed_query( $query ) {
	if ( is_admin() || ! $query->is_feed() || ! $query->is_main_query() ) {
		return;
	}
	$settings = wrc_get_settings();

	if ( $settings['delay_minutes'] > 0 ) {
		$query->set( 'date_query', array(
			array(
				'before'    => gmdate( 'Y-m-d H:i:s', time() - $settings['delay_minutes'] * MINUTE_IN_SECONDS ),
				'inclusive' => true,
			),
		) );
	}

	if ( ! empty( $settings['excluded_cats'] ) ) {
		$query->set( 'category__not_in', $settings['excluded_cats'] );
	}

	if ( $settings['item_count'] > 0 ) {
		$query->set( 'posts_per_page', $settings['item_count'] );
	}
}
add_action( 'pre_get_posts', 'wrc_modify_feed_query' );

/**
 * Append the configured footer to every feed item's content.
 *
 * @param string $content Post content in the feed.
 * @return string
 */
function wrc_append_footer( $content ) {
	if ( ! is_feed() ) {
		return $content;
	}
	$settings = wrc_get_settings();
	if ( '' === trim( $settings['footer'] ) ) {
		return $content;
	}
	// Allow a small set of safe tags; the footer is admin-entered but we still
	// strip anything script-like before it reaches subscribers.
	$allowed = array(
		'a'      => array( 'href' => true, 'title' => true ),
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
		'p'      => array(),
	);
	return $content . "\n" . wp_kses( $settings['footer'], $allowed );
}
add_filter( 'the_content_feed', 'wrc_append_footer' );

/**
 * Optionally disable comment feeds entirely.
 */
function wrc_maybe_disable_comment_feeds() {
	$settings = wrc_get_settings();
	if ( $settings['disable_comments'] ) {
		remove_action( 'do_feed_rss2_comments', 'do_feed_rss2_comments', 10 );
		remove_action( 'do_feed_atom_comments', 'do_feed_atom_comments', 10 );
		add_action( 'do_feed_rss2_comments', 'wrc_comment_feed_disabled', 10, 1 );
		add_action( 'do_feed_atom_comments', 'wrc_comment_feed_disabled', 10, 1 );
	}
}
add_action( 'init', 'wrc_maybe_disable_comment_feeds' );

/**
 * Replacement handler for disabled comment feeds.
 */
function wrc_comment_feed_disabled() {
	status_header( 404 );
	esc_html_e( 'Comment feeds are disabled on this site.', 'wp-rss-customizer' );
}

/**
 * Settings page under Settings → RSS Customizer.
 */
function wrc_register_settings_page() {
	add_options_page(
		__( 'RSS Customizer', 'wp-rss-customizer' ),
		__( 'RSS Customizer', 'wp-rss-customizer' ),
		'manage_options',
		'wrc-settings',
		'wrc_render_settings_page'
	);
}
add_action( 'admin_menu', 'wrc_register_settings_page' );

/**
 * Register the option with sanitisation.
 */
function wrc_register_settings() {
	register_setting(
		'wrc_settings_group',
		WRC_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'wrc_sanitize_settings',
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', 'wrc_register_settings' );

/**
 * Sanitise settings input.
 *
 * @param array $input Raw input.
 * @return array
 */
function wrc_sanitize_settings( $input ) {
	$clean = array();
	$clean['delay_minutes'] = max( 0, (int) ( $input['delay_minutes'] ?? 0 ) );
	$clean['footer'] = isset( $input['footer'] ) ? wp_kses_post( wp_unslash( $input['footer'] ) ) : '';
	$clean['excluded_cats'] = isset( $input['excluded_cats'] ) && is_array( $input['excluded_cats'] )
		? array_map( 'intval', $input['excluded_cats'] ) : array();
	$clean['item_count'] = max( 0, (int) ( $input['item_count'] ?? 0 ) );
	$clean['disable_comments'] = ! empty( $input['disable_comments'] ) ? 1 : 0;
	return $clean;
}

/**
 * Render the settings page.
 */
function wrc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-rss-customizer' ) );
	}
	$settings = wrc_get_settings();
	$categories = get_categories( array( 'hide_empty' => false ) );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'RSS Customizer', 'wp-rss-customizer' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wrc_settings_group' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wrc-delay"><?php esc_html_e( 'Delay new posts in the feed (minutes)', 'wp-rss-customizer' ); ?></label></th>
					<td>
						<input id="wrc-delay" type="number" min="0" name="<?php echo esc_attr( WRC_OPTION ); ?>[delay_minutes]" value="<?php echo esc_attr( $settings['delay_minutes'] ); ?>">
						<p class="description"><?php esc_html_e( '0 = no delay. A delay gives you time to fix typos before scrapers grab the post.', 'wp-rss-customizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wrc-footer"><?php esc_html_e( 'Footer appended to every feed item', 'wp-rss-customizer' ); ?></label></th>
					<td>
						<textarea id="wrc-footer" rows="3" cols="60" name="<?php echo esc_attr( WRC_OPTION ); ?>[footer]"><?php echo esc_textarea( $settings['footer'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Example: © 2026 Your Site — originally published at yoursite.com. Useful for attribution when content is scraped.', 'wp-rss-customizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exclude categories from the feed', 'wp-rss-customizer' ); ?></th>
					<td>
						<?php foreach ( $categories as $cat ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( WRC_OPTION ); ?>[excluded_cats][]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( $cat->term_id, $settings['excluded_cats'], true ) ); ?>> <?php echo esc_html( $cat->name ); ?></label><br>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wrc-count"><?php esc_html_e( 'Feed item count (0 = WordPress default)', 'wp-rss-customizer' ); ?></label></th>
					<td><input id="wrc-count" type="number" min="0" name="<?php echo esc_attr( WRC_OPTION ); ?>[item_count]" value="<?php echo esc_attr( $settings['item_count'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Comment feeds', 'wp-rss-customizer' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( WRC_OPTION ); ?>[disable_comments]" value="1" <?php checked( $settings['disable_comments'], 1 ); ?>> <?php esc_html_e( 'Disable comment feeds (404)', 'wp-rss-customizer' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
