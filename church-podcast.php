<?php
/**
 * Plugin Name: Church Podcast
 * Description: Podcast feed, audio metadata extraction, and podcast settings for sermon resources.
 * Plugin URI: https://github.com/ninefootone/church-podcast
 * Version: 1.0.0
 * Author: ninefootone creative
 * Author URI: https://www.ninefootone.co.uk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CP_VERSION', '1.0.0' );

define( 'CP_VERSION', '1.0.0' );

// Plugin Update Checker v5.5 (vendored — do not upgrade without testing)
require_once plugin_dir_path( __FILE__ ) . 'lib/plugin-update-checker/plugin-update-checker.php';
$cp_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/ninefootone/church-podcast',
    __FILE__,
    'church-podcast'
);
$cp_update_checker->getVcsApi()->enableReleaseAssets();

// ============================================================
// 1. SETTINGS PAGE
// ============================================================

add_action( 'admin_menu', 'cp_add_settings_page' );

function cp_add_settings_page() {
    add_options_page(
        'Church Podcast Settings',
        'Church Podcast',
        'manage_options',
        'church-podcast',
        'cp_render_settings_page'
    );
}

add_action( 'admin_init', 'cp_register_settings' );

function cp_register_settings() {
    register_setting( 'church_podcast', 'cp_settings', [
        'sanitize_callback' => 'cp_sanitize_settings',
    ]);

    add_settings_section( 'cp_feed', 'Podcast Feed', '__return_false', 'church-podcast' );

    $fields = [
        'podcast_title'       => 'Podcast Title',
        'podcast_description' => 'Podcast Description',
        'podcast_email'       => 'Contact Email (not public — required by Apple)',
        'podcast_artwork_url' => 'Artwork URL (3000×3000px JPEG or PNG)',
        'podcast_category'    => 'Primary Category',
        'podcast_subcategory' => 'Sub-category',
        'podcast_language'    => 'Language',
    ];

    foreach ( $fields as $key => $label ) {
        add_settings_field(
            $key,
            $label,
            'cp_render_field',
            'church-podcast',
            'cp_feed',
            [ 'key' => $key ]
        );
    }
}

function cp_render_field( $args ) {
    $options = get_option( 'cp_settings', [] );
    $key     = $args['key'];
    $value   = $options[ $key ] ?? '';

    if ( $key === 'podcast_description' ) {
        echo '<textarea name="cp_settings[' . esc_attr( $key ) . ']" rows="4" cols="60">' . esc_textarea( $value ) . '</textarea>';
    } else {
        echo '<input type="text" name="cp_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" />';
    }

    // Helper text
    $hints = [
        'podcast_category'    => 'e.g. Religion &amp; Spirituality — must match <a href="https://podcasters.apple.com/support/1691-apple-podcasts-categories" target="_blank">Apple\'s category list</a> exactly',
        'podcast_subcategory' => 'e.g. Christianity',
        'podcast_language'    => 'Default: en-gb',
        'podcast_artwork_url' => 'Upload to Media Library and paste the URL here',
    ];

    if ( isset( $hints[ $key ] ) ) {
        echo '<p class="description">' . $hints[ $key ] . '</p>';
    }
}

function cp_sanitize_settings( $input ) {
    $clean = [];
    $text_fields = [
        'podcast_title',
        'podcast_description',
        'podcast_category',
        'podcast_subcategory',
        'podcast_language',
    ];
    foreach ( $text_fields as $field ) {
        $clean[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
    }
    $clean['podcast_email']       = isset( $input['podcast_email'] ) ? sanitize_email( $input['podcast_email'] ) : '';
    $clean['podcast_artwork_url'] = isset( $input['podcast_artwork_url'] ) ? esc_url_raw( $input['podcast_artwork_url'] ) : '';
    return $clean;
}

function cp_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Church Podcast Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'church_podcast' );
            do_settings_sections( 'church-podcast' );
            submit_button();
            ?>
        </form>
        <hr>
        <h2>Feed URL</h2>
        <p>Your podcast feed is available at:</p>
        <code><?php echo esc_url( home_url( '/feed/podcast/' ) ); ?></code>
        <p class="description">This URL cannot change once submitted to Apple Podcasts or Spotify. Do not change your permalink structure after submission.</p>
        <hr>
        <h2>Before You Submit</h2>
        <ol>
            <li>Validate the feed at <a href="https://www.castfeedvalidator.com" target="_blank">castfeedvalidator.com</a></li>
            <li>Artwork must be a square JPEG or PNG, exactly 3000×3000px</li>
            <li>Contact email is required by Apple but is not shown publicly</li>
        </ol>
    </div>
    <?php
}


// ============================================================
// 2. AUDIO METADATA — extract on upload before offload removes local file
// ============================================================

add_filter( 'wp_generate_attachment_metadata', 'cp_extract_audio_metadata_on_upload', 10, 2 );

function cp_extract_audio_metadata_on_upload( $metadata, $attachment_id ) {

    $mime = get_post_mime_type( $attachment_id );
    if ( strpos( $mime, 'audio/' ) === false ) return $metadata;

    $file_size = $metadata['filesize'] ?? 0;
    $length    = $metadata['length'] ?? 0;
    $duration  = $length ? cp_format_duration( (int) $length ) : '';

    if ( $file_size ) update_post_meta( $attachment_id, '_cp_audio_file_size', $file_size );
    if ( $duration )  update_post_meta( $attachment_id, '_cp_audio_duration', $duration );

    return $metadata;
}

function cp_format_duration( int $seconds ): string {
    $h = floor( $seconds / 3600 );
    $m = floor( ( $seconds % 3600 ) / 60 );
    $s = $seconds % 60;
    return sprintf( '%02d:%02d:%02d', $h, $m, $s );
}


// ============================================================
// 3. ACF SAVE — populate ACF fields from stored attachment meta
// ============================================================

add_action( 'acf/save_post', 'cp_populate_audio_acf_fields', 20 );

function cp_populate_audio_acf_fields( $post_id ) {

    if ( get_post_type( $post_id ) !== 'resource' ) return;

    $terms = get_the_terms( $post_id, 'resource-type' );
    if ( ! $terms || is_wp_error( $terms ) ) return;
    $term_slugs = wp_list_pluck( $terms, 'slug' );
    if ( ! in_array( 'sermon', $term_slugs, true ) ) return;

    $attachment_id = get_field( 'resource_audio', $post_id );
    if ( ! $attachment_id ) return;

    $file_size = get_post_meta( $attachment_id, '_cp_audio_file_size', true );
    $duration  = get_post_meta( $attachment_id, '_cp_audio_duration', true );

    error_log( 'cp debug — file_size: ' . $file_size . ' duration: ' . $duration . ' post_id: ' . $post_id . ' attachment_id: ' . $attachment_id );
    
    if ( $file_size ) {
    update_field( 'resource_audio_info_resource_audio_file_size', (int) $file_size, $post_id );
    }
    if ( $duration ) {
    update_field( 'resource_audio_info_resource_audio_duration', $duration, $post_id );
    }
}


// ============================================================
// 4. PODCAST FEED
// ============================================================

add_action( 'init', 'cp_register_podcast_feed' );

function cp_register_podcast_feed() {
    add_feed( 'podcast', 'cp_render_podcast_feed' );
}

function cp_render_podcast_feed() {
    ob_start();

    $options     = get_option( 'cp_settings', [] );
    $title       = $options['podcast_title']       ?? get_bloginfo( 'name' ) . ' Sermons';
    $description = $options['podcast_description'] ?? get_bloginfo( 'description' );
    $email       = $options['podcast_email']        ?? '';
    $artwork_url = $options['podcast_artwork_url']  ?? '';
    $category    = $options['podcast_category']     ?? 'Religion &amp; Spirituality';
    $subcategory = $options['podcast_subcategory']  ?? 'Christianity';
    $language    = $options['podcast_language']     ?? 'en-gb';
    $site_url    = home_url();
    $feed_url    = home_url( '/feed/podcast/' );

    $args = [
        'post_type'      => 'resource',
        'posts_per_page' => 100,
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'     => 'resource_include_in_podcast_feed',
                'value'   => 'Yes',
                'compare' => '=',
            ],
        ],
        'tax_query' => [
            [
                'taxonomy' => 'resource-type',
                'field'    => 'slug',
                'terms'    => 'sermon',
            ],
        ],
    ];

    $sermons = new WP_Query( $args );
    
    ob_end_clean();
    header( 'Content-Type: application/rss+xml; charset=UTF-8' );

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    ?>
    <rss version="2.0"
        xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
        xmlns:content="http://purl.org/rss/1.0/modules/content/"
        xmlns:atom="http://www.w3.org/2005/Atom">
        <channel>
            <title><?php echo esc_xml( $title ); ?></title>
            <link><?php echo esc_url( $site_url ); ?></link>
            <description><?php echo esc_xml( $description ); ?></description>
            <language><?php echo esc_xml( $language ); ?></language>
            <atom:link href="<?php echo esc_url( $feed_url ); ?>" rel="self" type="application/rss+xml" />
            <itunes:author><?php echo esc_xml( $title ); ?></itunes:author>
            <itunes:summary><?php echo esc_xml( $description ); ?></itunes:summary>
            <itunes:explicit>false</itunes:explicit>
            <itunes:category text="<?php echo esc_attr( $category ); ?>">
                <?php if ( $subcategory ) : ?>
                <itunes:category text="<?php echo esc_attr( $subcategory ); ?>" />
                <?php endif; ?>
            </itunes:category>
            <?php if ( $email ) : ?>
            <itunes:owner>
                <itunes:name><?php echo esc_xml( $title ); ?></itunes:name>
                <itunes:email><?php echo esc_xml( $email ); ?></itunes:email>
            </itunes:owner>
            <?php endif; ?>
            <?php if ( $artwork_url ) : ?>
            <itunes:image href="<?php echo esc_url( $artwork_url ); ?>" />
            <image>
                <url><?php echo esc_url( $artwork_url ); ?></url>
                <title><?php echo esc_xml( $title ); ?></title>
                <link><?php echo esc_url( $site_url ); ?></link>
            </image>
            <?php endif; ?>

            <?php if ( $sermons->have_posts() ) : while ( $sermons->have_posts() ) : $sermons->the_post();

                $post_id      = get_the_ID();
                $post_title   = get_the_title();
                $post_date    = get_the_date( 'r' );
                $post_url     = get_permalink();
                $post_desc    = get_field( 'resources_summary', $post_id );

                $audio_id     = get_field( 'resource_audio', $post_id );
                $audio_url    = $audio_id ? wp_get_attachment_url( $audio_id ) : '';
                $file_size   = (int) ( get_field( 'resource_audio_info_resource_audio_file_size', $post_id ) ?: 0 );
                $duration    = get_field( 'resource_audio_info_resource_audio_duration', $post_id ) ?: '';

                $contributors = get_the_terms( $post_id, 'resource-contributor' );
                $speaker      = ( $contributors && ! is_wp_error( $contributors ) )
                                ? $contributors[0]->name
                                : '';

                $passage      = get_field( 'resource_first_bible_passage', $post_id ) ?: '';

                $subtitle_parts = array_filter( [ $speaker, $passage ] );
                $subtitle       = implode( ' — ', $subtitle_parts );

                if ( ! $audio_url ) continue;

            ?>
                <item>
                    <title><?php echo esc_xml( $post_title ); ?></title>
                    <link><?php echo esc_url( $post_url ); ?></link>
                    <guid isPermaLink="true"><?php echo esc_url( $post_url ); ?></guid>
                    <pubDate><?php echo esc_xml( $post_date ); ?></pubDate>
                    <description><?php echo esc_xml( wp_strip_all_tags( $post_desc ) ); ?></description>
                    <content:encoded><![CDATA[<?php echo $post_desc; ?>]]></content:encoded>
                    <enclosure url="<?php echo esc_url( $audio_url ); ?>" length="<?php echo $file_size; ?>" type="audio/mpeg" />
                    <?php if ( $speaker ) : ?>
                    <itunes:author><?php echo esc_xml( $speaker ); ?></itunes:author>
                    <?php endif; ?>
                    <?php if ( $subtitle ) : ?>
                    <itunes:subtitle><?php echo esc_xml( $subtitle ); ?></itunes:subtitle>
                    <?php endif; ?>
                    <?php if ( $post_desc ) : ?>
                    <itunes:summary><?php echo esc_xml( wp_strip_all_tags( $post_desc ) ); ?></itunes:summary>
                    <?php endif; ?>
                    <?php if ( $duration ) : ?>
                    <itunes:duration><?php echo esc_xml( $duration ); ?></itunes:duration>
                    <?php endif; ?>
                    <?php if ( $artwork_url ) : ?>
                    <itunes:image href="<?php echo esc_url( $artwork_url ); ?>" />
                    <?php endif; ?>
                </item>

            <?php endwhile; wp_reset_postdata(); endif; ?>

        </channel>
    </rss>
    <?php
}