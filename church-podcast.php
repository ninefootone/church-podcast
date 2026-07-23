<?php
/**
 * Plugin Name: Church Podcast
 * Description: Podcast feed, audio metadata extraction, and podcast settings for sermon resources.
 * Plugin URI: https://github.com/ninefootone/church-podcast
 * Version: 1.1.6
 * Author: ninefootone creative
 * Author URI: https://www.ninefootone.co.uk
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CP_VERSION', '1.1.6' );

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
    $church_terms = cp_get_church_terms();
    ?>
    <div class="wrap">
        <h1>Church Podcast Settings</h1>
        <p>These settings apply to the combined feed. If you have per-church feeds enabled, each church can override these defaults via its term settings in <strong>Sermons → Churches</strong>.</p>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'church_podcast' );
            do_settings_sections( 'church-podcast' );
            submit_button();
            ?>
        </form>
        <hr>
        <h2>Feed URLs</h2>
        <h3>Combined Feed</h3>
        <p>All sermon resources with podcast feed enabled, across all churches:</p>
        <code><?php echo esc_url( home_url( '/feed/podcast/' ) ); ?></code>
        <p class="description">This URL cannot change once submitted to Apple Podcasts or Spotify. Do not change your permalink structure after submission.</p>

        <?php if ( ! empty( $church_terms ) ) : ?>
        <h3>Per-Church Feeds</h3>
        <p>Sermons filtered by church. Settings for each feed are configured on the church taxonomy term.</p>
        <table class="widefat striped" style="max-width:600px;">
            <thead>
                <tr>
                    <th>Church</th>
                    <th>Feed URL</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $church_terms as $term ) :
                    $feed_url = cp_get_church_feed_url( $term->slug );
                ?>
                <tr>
                    <td><?php echo esc_html( $term->name ); ?></td>
                    <td><code><?php echo esc_url( $feed_url ); ?></code></td>
                    <td><a href="<?php echo esc_url( get_edit_term_link( $term->term_id, 'resource-church' ) ); ?>">Edit settings</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

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

// WP All Import does not fire acf/save_post — run population after each imported row.
add_action( 'pmxi_saved_post', 'cp_populate_audio_acf_fields', 10, 1 );

function cp_populate_audio_acf_fields( $post_id ) {

    if ( get_post_type( $post_id ) !== 'resource' ) return;

    $attachment_id = get_post_meta( $post_id, 'resource_audio', true );
    if ( ! $attachment_id ) return;

    $file_size = get_post_meta( $attachment_id, '_cp_audio_file_size', true );
    $duration  = get_post_meta( $attachment_id, '_cp_audio_duration', true );

    if ( $file_size ) {
        update_post_meta( $post_id, 'resource_audio_info_resource_audio_file_size', (int) $file_size );
    }
    if ( $duration ) {
        update_post_meta( $post_id, 'resource_audio_info_resource_audio_duration', $duration );
    }
}


// ============================================================
// 4. ACF FIELD GROUP — per-church feed settings on resource-church taxonomy
// ============================================================

add_action( 'acf/init', 'cp_register_church_feed_fields' );

function cp_register_church_feed_fields() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;
    if ( ! taxonomy_exists( 'resource-church' ) ) return;

    acf_add_local_field_group( [
        'key'      => 'group_cp_church_feed',
        'title'    => 'Podcast Feed Settings',
        'location' => [
            [
                [
                    'param'    => 'taxonomy',
                    'operator' => '==',
                    'value'    => 'resource-church',
                ],
            ],
        ],
        'fields' => [
            [
                'key'               => 'field_cp_church_podcast_title',
                'label'             => 'Podcast Title',
                'name'              => 'cp_church_podcast_title',
                'type'              => 'text',
                'instructions'      => 'Overrides the global podcast title for this church\'s feed. Leave blank to use the global default.',
                'required'          => 0,
            ],
            [
                'key'               => 'field_cp_church_podcast_description',
                'label'             => 'Podcast Description',
                'name'              => 'cp_church_podcast_description',
                'type'              => 'textarea',
                'instructions'      => 'Overrides the global podcast description for this church\'s feed.',
                'required'          => 0,
                'rows'              => 4,
            ],
            [
                'key'               => 'field_cp_church_podcast_email',
                'label'             => 'Contact Email',
                'name'              => 'cp_church_podcast_email',
                'type'              => 'email',
                'instructions'      => 'Not shown publicly. Required by Apple Podcasts. Overrides global email.',
                'required'          => 0,
            ],
            [
                'key'               => 'field_cp_church_podcast_artwork_url',
                'label'             => 'Artwork URL (3000×3000px JPEG or PNG)',
                'name'              => 'cp_church_podcast_artwork_url',
                'type'              => 'url',
                'instructions'      => 'Upload to Media Library and paste the URL here. Overrides global artwork.',
                'required'          => 0,
            ],
            [
                'key'               => 'field_cp_church_podcast_category',
                'label'             => 'Primary Category',
                'name'              => 'cp_church_podcast_category',
                'type'              => 'text',
                'instructions'      => 'e.g. Religion &amp; Spirituality. Must match Apple\'s category list exactly.',
                'required'          => 0,
            ],
            [
                'key'               => 'field_cp_church_podcast_subcategory',
                'label'             => 'Sub-category',
                'name'              => 'cp_church_podcast_subcategory',
                'type'              => 'text',
                'instructions'      => 'e.g. Christianity',
                'required'          => 0,
            ],
            [
                'key'               => 'field_cp_church_podcast_language',
                'label'             => 'Language',
                'name'              => 'cp_church_podcast_language',
                'type'              => 'text',
                'instructions'      => 'e.g. en-gb. Overrides global language setting.',
                'required'          => 0,
            ],
        ],
        'active' => true,
    ] );
}


// ============================================================
// 5. PODCAST FEEDS
// ============================================================

add_action( 'init', 'cp_register_podcast_feeds' );

function cp_register_podcast_feeds() {
    // Combined feed — always registered
    add_feed( 'podcast', 'cp_render_combined_feed' );

    // Per-church feeds — only if the taxonomy exists and has terms
    if ( ! taxonomy_exists( 'resource-church' ) ) return;

    $terms = cp_get_church_terms();
    if ( empty( $terms ) ) return;

    foreach ( $terms as $term ) {
        // Closure captures $term->term_id by value
        $term_id = $term->term_id;
        add_feed( 'podcast-church/' . $term->slug, function() use ( $term_id ) {
            cp_render_podcast_feed( $term_id );
        });
    }
}

function cp_render_combined_feed() {
    cp_render_podcast_feed( null );
}

/**
 * Render the podcast feed.
 *
 * @param int|null $church_term_id  Term ID from resource-church taxonomy, or null for the combined feed.
 */
function cp_render_podcast_feed( $church_term_id = null ) {
    ob_start();

    $global   = get_option( 'cp_settings', [] );
    $is_church_feed = ( $church_term_id !== null );

    // Resolve settings: per-church ACF term meta with global fallback
    $title       = cp_get_church_feed_setting( $church_term_id, 'cp_church_podcast_title',       $global['podcast_title']       ?? get_bloginfo( 'name' ) . ' Sermons' );
    $description = cp_get_church_feed_setting( $church_term_id, 'cp_church_podcast_description', $global['podcast_description'] ?? get_bloginfo( 'description' ) );
    $email       = cp_get_church_feed_setting( $church_term_id, 'cp_church_podcast_email',        $global['podcast_email']       ?? '' );
    $artwork_url = cp_get_church_feed_setting( $church_term_id, 'cp_church_podcast_artwork_url',  $global['podcast_artwork_url'] ?? '' );
    $category    = cp_get_church_feed_setting( $church_term_id, 'cp_church_podcast_category',     $global['podcast_category']    ?? 'Religion &amp; Spirituality' );
    $subcategory = cp_get_church_feed_setting( $church_term_id, 'cp_church_podcast_subcategory',  $global['podcast_subcategory'] ?? 'Christianity' );
    $language    = cp_get_church_feed_setting( $church_term_id, 'cp_church_podcast_language',     $global['podcast_language']    ?? 'en-gb' );

    $site_url = home_url();

    if ( $is_church_feed ) {
        $term     = get_term( $church_term_id, 'resource-church' );
        $feed_url = cp_get_church_feed_url( $term->slug );
    } else {
        $feed_url = home_url( '/feed/podcast/' );
    }

    $args = [
        'post_type'      => 'resource',
        'posts_per_page' => -1, // no cap within the date window below
        'post_status'    => 'publish',
        [
            'key'     => 'resource_date',
            'value'   => date( 'Ymd', strtotime( '-1 year' ) ),
            'compare' => '>=',
            'type'    => 'NUMERIC',
        ],
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

    // Narrow to a specific church when rendering a per-church feed
    if ( $is_church_feed ) {
        $args['tax_query'][] = [
            'taxonomy' => 'resource-church',
            'field'    => 'term_id',
            'terms'    => $church_term_id,
        ];
    }

    $sermons = new WP_Query( $args );

    ob_end_clean();
    header( 'Content-Type: application/rss+xml; charset=UTF-8' );

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    ?><rss version="2.0"
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
                $acf_date  = get_post_meta( $post_id, 'resource_date', true );
                $post_date = $acf_date
                    ? DateTime::createFromFormat( 'Ymd', $acf_date )->format( 'r' )
                    : get_the_date( 'r' );
                $post_url     = get_permalink();

                $audio_id    = get_field( 'resource_audio', $post_id );
                $audio_url   = $audio_id ? wp_get_attachment_url( $audio_id ) : '';
                $file_size   = (int) ( get_field( 'resource_audio_info_resource_audio_file_size', $post_id ) ?: 0 );
                $duration    = get_field( 'resource_audio_info_resource_audio_duration', $post_id ) ?: '';

                $contributors = get_the_terms( $post_id, 'resource-contributor' );
                $speaker      = ( $contributors && ! is_wp_error( $contributors ) )
                ? $contributors[0]->name
                : '';

                $passage      = get_field( 'resource_bible_passages', $post_id ) ?: '';

                $post_desc = get_field( 'resource_summary', $post_id );
                if ( ! $post_desc ) {
                    $post_desc = implode( ' — ', array_filter( [ $speaker, $passage ] ) );
                }
                if ( ! $post_desc ) {
                    $post_desc = $post_title;
                }

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


// ============================================================
// 6. HELPERS
// ============================================================

/**
 * Get all terms from resource-church taxonomy, or empty array if taxonomy doesn't exist.
 *
 * @return WP_Term[]
 */
function cp_get_church_terms(): array {
    if ( ! taxonomy_exists( 'resource-church' ) ) return [];

    $terms = get_terms( [
        'taxonomy'   => 'resource-church',
        'hide_empty' => false,
    ] );

    return ( is_wp_error( $terms ) || empty( $terms ) ) ? [] : $terms;
}

/**
 * Get the feed URL for a given church term slug.
 */
function cp_get_church_feed_url( string $slug ): string {
    return home_url( '/feed/podcast-church/' . $slug . '/' );
}

/**
 * Resolve a feed setting: ACF term meta if set and non-empty, otherwise the provided default.
 *
 * @param int|null $term_id   resource-church term ID, or null for combined feed.
 * @param string   $acf_key  ACF field name on the taxonomy term.
 * @param string   $default  Fallback value (typically from global cp_settings).
 */
function cp_get_church_feed_setting( $term_id, string $acf_key, string $default ): string {
    if ( $term_id === null ) return $default;
    if ( ! function_exists( 'get_field' ) ) return $default;

    $value = get_field( $acf_key, 'resource-church_' . $term_id );
    return ( $value && trim( $value ) !== '' ) ? $value : $default;
}
