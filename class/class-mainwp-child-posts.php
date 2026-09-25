<?php
/**
 * MainWP Child posts handler
 *
 * This file handles all post & post plus actions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

//phpcs:disable Generic.Metrics.CyclomaticComplexity -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Posts
 *
 * Handle all post & post plus actions.
 */
class MainWP_Child_Posts { //phpcs:ignore -- NOSONAR - multi methods.

    /** Maximum durable receipts retained for each content protocol. */
    private const CONTENT_V2_MAX_RECORDS = 500;

    /** Minimum terminal-receipt retention in seconds. */
    private const CONTENT_V2_RETENTION = 7776000;

    /**
     * Public static variable to hold the single instance of MainWP_Child_Posts.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Comments and clauses.
     *
     * @var string Comments and clauses.
     */
    private $comments_and_clauses;

    /**
     * Posts with given suffix.
     *
     * @var string Posts with given suffix.
     */
    private $posts_where_suffix;

    /**
     * Get class name.
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * MainWP_Child_Posts constructor
     *
     * Run any time class is called.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::comments_and_clauses()
     * @uses \MainWP\Child\MainWP_Child_Posts::posts_where_suffix()
     */
    public function __construct() {
        $this->comments_and_clauses = '';
        $this->posts_where_suffix   = '';
    }

    /**
     * Create a public static instance of MainWP_Child_Posts.
     *
     * @return MainWP_Child_Posts|null
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }


    /**
     * Get recent posts.
     *
     * @param array  $pAllowedStatuses Array of allowed post statuses.
     * @param int    $pCount Number of posts.
     * @param string $type Post type.
     * @param null   $extra Extra tokens.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::get_recent_posts_int()
     *
     * @return array $allPost Return array of recent posts.
     */
    public function get_recent_posts( $pAllowedStatuses, $pCount, $type = 'post', $extra = null ) {
        $allPosts = array();
        if ( null !== $pAllowedStatuses ) {
            foreach ( $pAllowedStatuses as $status ) {
                $this->get_recent_posts_int( $status, $pCount, $type, $allPosts, $extra );
            }
        } else {
            $this->get_recent_posts_int( 'any', $pCount, $type, $allPosts, $extra );
        }

        return $allPosts;
    }

    /**
     * Initiate get recent posts.
     *
     * @param string $status Post status.
     * @param int    $pCount Number of posts.
     * @param string $type Post type.
     * @param array  $allPosts All posts array.
     * @param null   $extra Extra tokens.
     *
     * @uses \WPSEO_Link_Column_Count()
     * @uses \WPSEO_Meta()
     * @uses MainWP_WordPress_SEO::instance()::parse_column_score()
     * @uses MainWP_WordPress_SEO::instance()->parse_column_score_readability()
     * @uses \MainWP\Child\MainWP_Child_Posts::get_out_post()
     */
    public function get_recent_posts_int( $status, $pCount, $type, &$allPosts, $extra = null ) { //phpcs:ignore -- NOSONAR - complex.

        $args = array(
            'post_status'      => $status,
            'suppress_filters' => false,
            'post_type'        => $type,
        );

        $tokens = array();
        if ( is_array( $extra ) ) {
            if ( isset( $extra['tokens'] ) ) {
                $tokens = $extra['tokens'];
                if ( 1 === (int) $extra['extract_post_type'] ) {
                    $args['post_type'] = 'post';
                } elseif ( 2 === (int) $extra['extract_post_type'] ) {
                    $args['post_type'] = 'page';
                } elseif ( 3 === (int) $extra['extract_post_type'] ) {
                    $args['post_type'] = array( 'post', 'page' );
                }
            }
            if ( ! empty( $extra['ids'] ) && is_array( $extra['ids'] ) ) {
                $args['post__in']       = $extra['ids'];
                $args['posts_per_page'] = -1;
            }
        }
        $tokens = array_flip( $tokens );

        if ( 0 !== $pCount ) {
            $args['numberposts'] = $pCount;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        $wp_seo_enabled = false;
        if ( ! empty( $_POST['WPSEOEnabled'] ) && is_plugin_active( 'wordpress-seo/wp-seo.php' ) && class_exists( '\WPSEO_Link_Column_Count' ) && class_exists( '\WPSEO_Meta' ) ) {
            $wp_seo_enabled = true;
        }
        // phpcs:enable WordPress.Security.NonceVerification
        $posts = get_posts( $args );

        if ( is_array( $posts ) ) {
            if ( $wp_seo_enabled ) {
                $post_ids = array();
                foreach ( $posts as $post ) {
                    $post_ids[] = $post->ID;
                }

                /**
                * Credits
                *
                * Plugin-Name: Yoast SEO
                * Plugin URI: https://yoast.com/wordpress/plugins/seo/#utm_source=wpadmin&utm_medium=plugin&utm_campaign=wpseoplugin
                * Author: Team Yoast
                * Author URI: https://yoast.com/
                * Licence: GPL v3
                *
                * The code is used for the MainWP WordPress SEO Extension
                * Extension URL: https://mainwp.com/extension/wordpress-seo/
                */
                $link_count = new \WPSEO_Link_Column_Count();
                $link_count->set( $post_ids );
            }
            foreach ( $posts as $post ) {
                $outPost = $this->get_out_post( $post, $extra, $tokens );
                if ( $wp_seo_enabled ) {
                    $outPost['seo_data'] = array(
                        'count_seo_links'   => $link_count->get( $post->ID, 'internal_link_count' ),
                        'count_seo_linked'  => $link_count->get( $post->ID, 'incoming_link_count' ),
                        'seo_score'         => MainWP_WordPress_SEO::instance()->parse_column_score( $post->ID ),
                        'readability_score' => MainWP_WordPress_SEO::instance()->parse_column_score_readability( $post->ID ),
                    );
                }
                $allPosts[] = $outPost;
            }
        }
    }

    /**
     * Build Post.
     *
     * @param array  $post Post array.
     * @param string $extra Post date & time.
     * @param array  $tokens Post tokens.
     * @return array $outPost Return completed post.
     */
    private function get_out_post( $post, $extra, $tokens ) { //phpcs:ignore -- NOSONAR - complex.
        $outPost                  = array();
        $outPost['id']            = $post->ID;
        $outPost['post_type']     = $post->post_type;
        $outPost['status']        = $post->post_status;
        $outPost['title']         = $post->post_title;
        $outPost['comment_count'] = $post->comment_count;
        if ( isset( $extra['where_post_date'] ) && ! empty( $extra['where_post_date'] ) ) {
            $outPost['dts'] = strtotime( $post->post_date_gmt );
        } else {
            $outPost['dts'] = strtotime( $post->post_modified_gmt );
        }

        if ( 'page' === $post->post_type ) {
            $outPost['dts'] = strtotime( $post->post_modified_gmt ); // to order by modified date.
        }

        if ( 'future' === $post->post_status ) {
            $outPost['dts'] = strtotime( $post->post_date_gmt );
        }

        $usr                    = get_user_by( 'id', $post->post_author );
        $outPost['author']      = ! empty( $usr ) ? $usr->user_nicename : 'removed';
        $outPost['authorEmail'] = ! empty( $usr ) ? $usr->user_email : 'removed';
        $categoryObjects        = get_the_category( $post->ID );
        $categories             = '';
        foreach ( $categoryObjects as $cat ) {
            if ( '' !== $categories ) {
                $categories .= ', ';
            }
            $categories .= $cat->name;
        }
        $outPost['categories'] = $categories;

        $tagObjects = get_the_tags( $post->ID );
        $tags       = '';
        if ( is_array( $tagObjects ) ) {
            foreach ( $tagObjects as $tag ) {
                if ( '' !== $tags ) {
                    $tags .= ', ';
                }
                $tags .= $tag->name;
            }
        }
        $outPost['tags'] = $tags;
        if ( is_array( $tokens ) ) {
            if ( isset( $tokens['[post.url]'] ) ) {
                $outPost['[post.url]'] = get_permalink( $post->ID );
            }
            if ( isset( $tokens['[post.website.url]'] ) ) {
                $outPost['[post.website.url]'] = get_site_url();
            }
            if ( isset( $tokens['[post.website.name]'] ) ) {
                $outPost['[post.website.name]'] = get_bloginfo( 'name' );
            }
        }
        return $outPost;
    }

    /**
     * Get all posts.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::get_all_posts_by_type()
     */
    public function get_all_posts() {
        // phpcs:disable WordPress.Security.NonceVerification
        $post_type = ( isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post' );
        // phpcs:enable WordPress.Security.NonceVerification
        $this->get_all_posts_by_type( $post_type );
    }

    /**
     * Return one typed, bounded and cursor-bound post metadata page.
     *
     * @param mixed $request Decoded protocol request.
     * @return array Closed protocol response.
     */
    public function get_all_posts_v2( $request ) {
        $query = $this->normalize_posts_v2_request( $request );
        if ( false === $query ) {
            return $this->posts_v2_error( 'invalid_request' );
        }

        $generation = hash(
            'sha256',
            wp_json_encode(
                array_diff_key(
                    $query,
                    array(
                        'cursor' => true,
                        'offset' => true,
                    )
                )
            )
        );
        $offset     = 0;
        if ( null !== $query['cursor'] ) {
            $offset = $this->posts_v2_cursor_offset( $query['cursor'], $generation );
            if ( false === $offset ) {
                return $this->posts_v2_error( 'invalid_cursor' );
            }
        }

        $arguments = array(
            'post_type'           => $query['post_types'],
            'post_status'         => $query['statuses'],
            'posts_per_page'      => $query['page_size'] + 1,
            'offset'              => $offset,
            'orderby'             => 'ID',
            'order'               => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        );
        if ( null !== $query['keyword'] ) {
            $arguments['s'] = $query['keyword'];
        }
        if ( null !== $query['post_id'] ) {
            $arguments['post__in'] = array( $query['post_id'] );
        }
        if ( null !== $query['author_id'] ) {
            $arguments['author'] = $query['author_id'];
        }
        if ( null !== $query['date_from'] || null !== $query['date_to'] ) {
            $date = array(
                'inclusive' => true,
                'column'    => 'post_date_gmt',
            );
            if ( null !== $query['date_from'] ) {
                $date['after'] = $query['date_from'] . ' 00:00:00';
            }
            if ( null !== $query['date_to'] ) {
                $date['before'] = $query['date_to'] . ' 23:59:59';
            }
            $arguments['date_query'] = array( $date );
        }

        $posts = get_posts( $arguments );
        if ( ! is_array( $posts ) ) {
            return $this->posts_v2_error( 'query_failed' );
        }
        $complete = count( $posts ) <= $query['page_size'];
        if ( ! $complete ) {
            array_pop( $posts );
        }

        $records = array();
        foreach ( $posts as $post ) {
            $record = $this->posts_v2_record( $post );
            if ( false === $record ) {
                return $this->posts_v2_error( 'record_invalid' );
            }
            $records[] = $record;
        }

        $next_offset = $offset + count( $records );
        return array(
            'protocol'         => '2',
            'operation'        => 'get_all_posts_v2',
            'ok'               => true,
            'complete'         => $complete,
            'records'          => $records,
            'next_cursor'      => $complete ? null : $this->posts_v2_cursor( $next_offset, $generation ),
            'query_generation' => $generation,
        );
    }

    /**
     * Negotiate the Post Dripper Child protocol.
     *
     * Publishing and receipt status use a durable idempotency contract.
     *
     * @param mixed $request Decoded protocol request.
     * @return array Closed protocol response.
     */
    public function post_dripper_capabilities_v2( $request ) {
        if ( ! is_array( $request ) || ! $this->posts_v2_exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_string( $request['operation'] ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/D', $request['operation'] ) || ! is_array( $request['payload'] ) ) {
            return $this->post_dripper_v2_error( 'unknown', 'invalid_request' );
        }

        $operation = $request['operation'];
        if ( 'capabilities' === $operation && array() === $request['payload'] ) {
            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => array( 'post_dripper_delivery_v2', 'post_dripper_status_v2' ),
                'mutation_supported' => true,
            );
        }
        if ( 'post_dripper_delivery_v2' === $operation ) {
            return $this->content_v2_mutate( 'post_dripper', $operation, $request['payload'] );
        }
        if ( 'post_dripper_status_v2' === $operation ) {
            return $this->content_v2_status( 'post_dripper', $operation, $request['payload'], false );
        }

        return $this->post_dripper_v2_error( $operation, 'unsupported_operation' );
    }

    /**
     * Negotiate the Post Plus Child protocol.
     *
     * Post creation and receipt status use a durable idempotency contract.
     *
     * @param mixed $request Decoded protocol request.
     * @return array Closed protocol response.
     */
    public function post_plus_capabilities_v2( $request ) {
        if ( ! is_array( $request ) || ! $this->posts_v2_exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_string( $request['operation'] ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/D', $request['operation'] ) || ! is_array( $request['payload'] ) ) {
            return $this->post_plus_v2_error( 'unknown', 'invalid_request' );
        }

        $operation = $request['operation'];
        if ( 'capabilities' === $operation && array() === $request['payload'] ) {
            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => array( 'post_plus_newpost_v2', 'post_plus_status_v2', 'post_plus_readback_v2' ),
                'mutation_supported' => true,
            );
        }
        if ( 'post_plus_newpost_v2' === $operation ) {
            return $this->content_v2_mutate( 'post_plus', $operation, $request['payload'] );
        }
        if ( 'post_plus_status_v2' === $operation ) {
            return $this->content_v2_status( 'post_plus', $operation, $request['payload'], false );
        }
        if ( 'post_plus_readback_v2' === $operation ) {
            if ( $this->posts_v2_exact_keys( $request['payload'], array( 'dashboard_ref', 'source_post_id', 'source_type' ) ) ) {
                return $this->post_plus_v2_source( $operation, $request['payload'] );
            }
            return $this->content_v2_status( 'post_plus', $operation, $request['payload'], true );
        }

        return $this->post_plus_v2_error( $operation, 'unsupported_operation' );
    }

    /**
     * Return one bounded immutable core post/page source without taking an edit lock.
     *
     * @param string $operation Closed operation name.
     * @param array  $payload   Exact source selector.
     * @return array Closed private source response.
     */
    private function post_plus_v2_source( $operation, $payload ) {
        $dashboard_ref = $this->content_v2_dashboard_ref();
        if ( false === $dashboard_ref
            || ! $this->posts_v2_exact_keys( $payload, array( 'dashboard_ref', 'source_post_id', 'source_type' ) )
            || ! $this->content_v2_hash( $payload['dashboard_ref'] )
            || ! hash_equals( $dashboard_ref, $payload['dashboard_ref'] )
            || ! is_int( $payload['source_post_id'] )
            || 1 > $payload['source_post_id']
            || ! in_array( $payload['source_type'], array( 'post', 'page' ), true ) ) {
            return $this->post_plus_v2_error( $operation, 'invalid_request' );
        }

        $source = get_post( $payload['source_post_id'] );
        if ( ! $source instanceof \WP_Post || $source->post_type !== $payload['source_type'] ) {
            return $this->post_plus_v2_error( $operation, 'source_not_found' );
        }
        $categories = 'post' === $source->post_type ? wp_get_post_terms( $source->ID, 'category', array( 'fields' => 'slugs' ) ) : array();
        $tags       = 'post' === $source->post_type ? wp_get_post_terms( $source->ID, 'post_tag', array( 'fields' => 'slugs' ) ) : array();
        if ( is_wp_error( $categories ) || is_wp_error( $tags ) || ! is_array( $categories ) || ! is_array( $tags ) || 100 < count( $categories ) || 100 < count( $tags ) ) {
            return $this->post_plus_v2_error( $operation, 'storage_unavailable' );
        }
        sort( $categories, SORT_STRING );
        sort( $tags, SORT_STRING );
        $post          = array(
            'post_type'      => $source->post_type,
            'status'         => in_array( $source->post_status, array( 'draft', 'publish' ), true ) ? $source->post_status : 'draft',
            'title'          => $source->post_title,
            'content'        => $source->post_content,
            'excerpt'        => $source->post_excerpt,
            'slug'           => $source->post_name,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
            'categories'     => array_values( $categories ),
            'tags'           => array_values( $tags ),
        );
        $randomization = array(
            'roles'           => array(),
            'random_category' => false,
            'date_from'       => null,
            'date_to'         => null,
            'timezone'        => 'UTC',
        );
        if ( false === $this->content_v2_normalize_post( $post ) || false === $this->content_v2_normalize_randomization( $randomization ) ) {
            return $this->post_plus_v2_error( $operation, 'unsupported_content' );
        }

        $compatibility = 'supported';
        $meta          = get_post_meta( $source->ID );
        if ( ! is_array( $meta ) ) {
            return $this->post_plus_v2_error( $operation, 'storage_unavailable' );
        }
        // One post can trip several blockers at once and only one of them is reported, so the
        // ranking is builder, then media, then meta - never whichever meta row happened to be
        // read last. Media outranks meta because an attached media object tells the operator to
        // deal with the attachment, while arbitrary meta tells them to decide what that meta
        // means; reporting the weaker one hides the actionable answer.
        foreach ( array_keys( $meta ) as $meta_key ) {
            if ( in_array( $meta_key, array( '_edit_last', '_edit_lock', '_encloseme', '_pingme', '_wp_page_template' ), true ) ) {
                continue;
            }
            if ( 1 === preg_match( '/(?:elementor|et_pb|fl_builder|beaver|brizy|oxygen)/i', $meta_key ) ) {
                $compatibility = 'unsupported_builder';
                break;
            }
            if ( '_thumbnail_id' === $meta_key ) {
                $compatibility = 'unsupported_media';
            } elseif ( 'supported' === $compatibility ) {
                $compatibility = 'unsupported_meta';
            }
        }
        if ( 'supported' === $compatibility && ( false !== stripos( $source->post_content, '<img' ) || false !== stripos( $source->post_content, '[gallery' ) ) ) {
            $compatibility = 'unsupported_media';
        }
        if ( 'supported' === $compatibility && '' !== $source->post_password ) {
            $compatibility = 'unsupported_meta';
        }

        $content_digest              = hash( 'sha256', wp_json_encode( array( $post, $randomization ) ) );
        $source_ref                  = hash_hmac( 'sha256', 'post-plus-source-v1|' . $source->ID . '|' . $source->post_type, wp_salt( 'auth' ) );
        $response                    = array(
            'protocol'        => '2',
            'operation'       => $operation,
            'complete'        => true,
            'source_post_id'  => (int) $source->ID,
            'source_type'     => $source->post_type,
            'source_ref'      => $source_ref,
            'title'           => $source->post_title,
            'content_digest'  => $content_digest,
            'compatibility'   => $compatibility,
            'private_context' => wp_json_encode(
                array(
                    'post'          => $post,
                    'randomization' => $randomization,
                )
            ),
        );
        $response['source_revision'] = hash( 'sha256', wp_json_encode( $response ) );
        return $response;
    }

    /**
     * Reserve and apply one typed post creation or update exactly once.
     *
     * @param string $protocol  Closed protocol name.
     * @param string $operation Closed operation name.
     * @param array  $payload   Untrusted decoded payload.
     * @return array Closed operation result.
     */
    private function content_v2_mutate( $protocol, $operation, $payload ) {
        $normalized = $this->content_v2_normalize_mutation( $protocol, $payload );
        if ( false === $normalized ) {
            return $this->content_v2_error( $protocol, $operation, 'invalid_request' );
        }

        $effect_hash = hash( 'sha256', wp_json_encode( $normalized ) );
        if ( ! $this->content_v2_begin_lock( $protocol ) ) {
            return $this->content_v2_error( $protocol, $operation, 'lock_busy' );
        }

        try {
            $repaired = false;
            $records  = $this->content_v2_records( $protocol, $repaired );
            if ( false === $records ) {
                return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
            }
            // A quarantine is stamped at first observation, so an unpersisted repair is re-stamped on
            // every request and can never grow old enough to be evicted or pruned - a ledger of damaged
            // entries would refuse every mutation forever. Persisting it here freezes those timestamps
            // and lets the entries age out normally.
            $pruned = $this->content_v2_prune_records( $protocol, $records, $repaired );
            if ( false !== $pruned ) {
                $records = $pruned;
            }

            $operation_ref = $normalized['operation_ref'];
            if ( isset( $records[ $operation_ref ] ) ) {
                if ( ! hash_equals( $records[ $operation_ref ]['dashboard_ref'], $normalized['dashboard_ref'] ) || ! hash_equals( $records[ $operation_ref ]['effect_hash'], $effect_hash ) ) {
                    return $this->content_v2_error( $protocol, $operation, 'request_conflict' );
                }
                // A settled receipt is answered from the ledger already read, writing nothing and
                // changing nothing, so a repair that could not be stored has no bearing on it -
                // refusing to state an outcome we are holding would be the worse failure.
                if ( 'reserved' !== $records[ $operation_ref ]['state'] && ! $records[ $operation_ref ]['retryable'] ) {
                    return $this->content_v2_project( $protocol, $operation, $records[ $operation_ref ] );
                }
            }
            // Everything past here writes, and reserving on top of a ledger whose repair was refused is
            // what puts the store back on the re-stamping loop above.
            if ( false === $pruned ) {
                return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
            }

            if ( isset( $records[ $operation_ref ] ) ) {
                if ( 'reserved' === $records[ $operation_ref ]['state'] ) {
                    $record = $this->content_v2_reconcile_reserved( $protocol, $records[ $operation_ref ], $records, 'reserved' );
                    if ( false === $record ) {
                        return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
                    }
                    if ( 'reserved' !== $record['state'] ) {
                        return $this->content_v2_project( $protocol, $operation, $record );
                    }
                } else {
                    $records[ $operation_ref ]['state']      = 'reserved';
                    $records[ $operation_ref ]['retryable']  = false;
                    $records[ $operation_ref ]['updated_at'] = time();
                    if ( ! $this->content_v2_write_records( $protocol, $records ) ) {
                        return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
                    }
                }
            } elseif ( $normalized['expires_at'] < time() - 60 ) {
                return $this->content_v2_error( $protocol, $operation, 'expired_request' );
            } elseif ( self::CONTENT_V2_MAX_RECORDS <= count( $records ) ) {
                $records = $this->content_v2_evict_records( $protocol, $records );
                if ( false === $records ) {
                    return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
                }
            }

            $target = null;
            if ( 'update' === $normalized['mode'] ) {
                $target = get_post( $normalized['target_post_id'] );
                if ( ! $target instanceof \WP_Post || $target->post_type !== $normalized['post']['post_type'] ) {
                    return $this->content_v2_error( $protocol, $operation, 'target_not_found' );
                }
                $current_revision = $this->content_v2_post_revision( $target->ID );
                if ( false === $current_revision || ! hash_equals( $normalized['expected_revision'], $current_revision ) ) {
                    return $this->content_v2_error( $protocol, $operation, 'stale_revision' );
                }
            }

            if ( ! isset( $records[ $operation_ref ] ) ) {
                $choices = 'post_plus' === $protocol ? $this->content_v2_random_choices( $normalized ) : array(
                    'author_id'     => $target instanceof \WP_Post ? (int) $target->post_author : get_current_user_id(),
                    'category_id'   => null,
                    'post_date_gmt' => null,
                );
                if ( false === $choices ) {
                    return $this->content_v2_error( $protocol, $operation, 'unsupported_content' );
                }
                if ( ! $this->content_v2_choices_available( $choices ) ) {
                    return $this->content_v2_error( $protocol, $operation, 'unsupported_content' );
                }
                $now                       = time();
                $records[ $operation_ref ] = array(
                    'dashboard_ref'     => $normalized['dashboard_ref'],
                    'operation_ref'     => $operation_ref,
                    'effect_hash'       => $effect_hash,
                    'content_digest'    => $normalized['content_digest'],
                    'mode'              => $normalized['mode'],
                    'target_post_id'    => $normalized['target_post_id'],
                    'expected_revision' => $normalized['expected_revision'],
                    'post_id'           => null,
                    'state'             => 'reserved',
                    'post_revision'     => null,
                    'remote_post_ref'   => null,
                    'retryable'         => false,
                    'choices'           => $choices,
                    'accepted_at'       => $now,
                    'updated_at'        => $now,
                );
                if ( ! $this->content_v2_write_records( $protocol, $records ) ) {
                    return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
                }
            } else {
                $choices = $records[ $operation_ref ]['choices'];
            }

            return $this->content_v2_apply( $protocol, $operation, $normalized, $records, $choices );
        } finally {
            $this->content_v2_end_lock( $protocol );
        }
    }

    /**
     * Return or repair one durable post-operation result.
     *
     * @param string $protocol  Closed protocol name.
     * @param string $operation Closed operation name.
     * @param array  $payload   Untrusted identity payload.
     * @param bool   $readback  Whether to add current redacted readback.
     * @return array Closed operation result.
     */
    private function content_v2_status( $protocol, $operation, $payload, $readback ) {
        $dashboard_ref = $this->content_v2_dashboard_ref();
        if ( ! is_array( $payload ) || ! $this->posts_v2_exact_keys( $payload, array( 'dashboard_ref', 'operation_ref' ) ) || ! $this->content_v2_hash( $payload['dashboard_ref'] ) || false === $dashboard_ref || ! hash_equals( $dashboard_ref, $payload['dashboard_ref'] ) || ! $this->content_v2_uuid( $payload['operation_ref'] ) ) {
            return $this->content_v2_error( $protocol, $operation, 'invalid_request' );
        }
        if ( ! $this->content_v2_begin_lock( $protocol ) ) {
            return $this->content_v2_error( $protocol, $operation, 'lock_busy' );
        }

        try {
            $records = $this->content_v2_records( $protocol );
            if ( false === $records ) {
                return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
            }
            $records = $this->content_v2_prune_records( $protocol, $records );
            if ( false === $records ) {
                return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
            }
            if ( ! isset( $records[ $payload['operation_ref'] ] ) || ! hash_equals( $records[ $payload['operation_ref'] ]['dashboard_ref'], $payload['dashboard_ref'] ) ) {
                return $this->content_v2_error( $protocol, $operation, 'operation_not_found' );
            }

            $record = $records[ $payload['operation_ref'] ];
            if ( 'reserved' === $record['state'] ) {
                $record = $this->content_v2_reconcile_reserved( $protocol, $record, $records, 'not_applied' );
                if ( false === $record ) {
                    return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
                }
            }

            return $this->content_v2_project( $protocol, $operation, $record, $readback );
        } finally {
            $this->content_v2_end_lock( $protocol );
        }
    }

    /**
     * Reconcile one durable reservation without repeating a proven effect.
     *
     * @param string $protocol   Closed protocol name.
     * @param array  $record     Valid reservation.
     * @param array  $records    Valid ledger, updated by reference.
     * @param string $zero_state State used when no committed post exists.
     * @return array|false Reconciled record or false on storage failure.
     */
    private function content_v2_reconcile_reserved( $protocol, $record, &$records, $zero_state ) {
        $matches = get_posts(
            array(
                'post_type'      => array( 'post', 'page' ),
                'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
                'meta_key'       => '_mainwp_child_content_operation_v2', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded private reconciliation key.
                'meta_value'     => $record['operation_ref'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact operation receipt binding.
                'posts_per_page' => 2,
            )
        );
        if ( ! is_array( $matches ) || 2 < count( $matches ) ) {
            return false;
        }
        $match_count = count( $matches );
        if ( 1 === $match_count ) {
            $revision = $this->content_v2_post_revision( $matches[0]->ID );
            if ( false === $revision ) {
                $record['state'] = 'unknown';
            } else {
                $record['post_id']         = $matches[0]->ID;
                $record['state']           = 'applied';
                $record['post_revision']   = $revision;
                $record['remote_post_ref'] = $this->content_v2_remote_ref( $protocol, $record['dashboard_ref'], $matches[0]->ID );
            }
        } elseif ( 0 === $match_count && 'not_applied' === $zero_state ) {
            $record['state']     = 'not_applied';
            $record['retryable'] = true;
        } elseif ( 0 === $match_count ) {
            return $record;
        } else {
            $record['state'] = 'unknown';
        }
        if ( 'unknown' === $record['state'] ) {
            $record['retryable'] = false;
        }
        $record['updated_at']                = time();
        $records[ $record['operation_ref'] ] = $record;
        return $this->content_v2_write_records( $protocol, $records ) ? $record : false;
    }

    /**
     * Normalize one closed mutation payload and verify its caller digest.
     *
     * @param string $protocol Closed protocol name.
     * @param mixed  $payload  Untrusted mutation payload.
     * @return array|false Normalized payload or false.
     */
    private function content_v2_normalize_mutation( $protocol, $payload ) {
        $keys = array( 'dashboard_ref', 'operation_ref', 'mode', 'target_post_id', 'expected_revision', 'content_digest', 'expires_at', 'post' );
        if ( 'post_plus' === $protocol ) {
            $keys[] = 'randomization';
        }
        $dashboard_ref = is_array( $payload ) && isset( $payload['dashboard_ref'] ) ? $this->content_v2_dashboard_ref() : false;
        if ( ! is_array( $payload ) || ! $this->posts_v2_exact_keys( $payload, $keys ) || ! $this->content_v2_hash( $payload['dashboard_ref'] ) || false === $dashboard_ref || ! hash_equals( $dashboard_ref, $payload['dashboard_ref'] ) || ! $this->content_v2_uuid( $payload['operation_ref'] ) || ! in_array( $payload['mode'], array( 'create', 'update' ), true ) || ! $this->content_v2_hash( $payload['content_digest'] ) || ! is_int( $payload['expires_at'] ) || time() + DAY_IN_SECONDS < $payload['expires_at'] ) {
            return false;
        }
        if ( ( 'create' === $payload['mode'] && ( null !== $payload['target_post_id'] || null !== $payload['expected_revision'] ) ) || ( 'update' === $payload['mode'] && ( ! is_int( $payload['target_post_id'] ) || 1 > $payload['target_post_id'] || ! $this->content_v2_hash( $payload['expected_revision'] ) ) ) ) {
            return false;
        }

        $post = $this->content_v2_normalize_post( $payload['post'] );
        if ( false === $post ) {
            return false;
        }
        $randomization = null;
        if ( 'post_plus' === $protocol ) {
            $randomization = $this->content_v2_normalize_randomization( $payload['randomization'] );
            if ( false === $randomization || ( 'page' === $post['post_type'] && $randomization['random_category'] ) ) {
                return false;
            }
        }
        $digest_value = 'post_plus' === $protocol ? array( $post, $randomization ) : $post;
        if ( ! hash_equals( $payload['content_digest'], hash( 'sha256', wp_json_encode( $digest_value ) ) ) ) {
            return false;
        }

        $normalized = array(
            'dashboard_ref'     => $payload['dashboard_ref'],
            'operation_ref'     => strtolower( $payload['operation_ref'] ),
            'mode'              => $payload['mode'],
            'target_post_id'    => $payload['target_post_id'],
            'expected_revision' => $payload['expected_revision'],
            'content_digest'    => $payload['content_digest'],
            'expires_at'        => $payload['expires_at'],
            'post'              => $post,
        );
        if ( 'post_plus' === $protocol ) {
            $normalized['randomization'] = $randomization;
        }
        return $normalized;
    }

    /**
     * Normalize one bounded core post payload.
     *
     * @param mixed $post Untrusted post payload.
     * @return array|false Normalized payload or false.
     */
    private function content_v2_normalize_post( $post ) {
        $keys = array( 'post_type', 'status', 'title', 'content', 'excerpt', 'slug', 'comment_status', 'ping_status', 'categories', 'tags' );
        if ( ! is_array( $post ) || ! $this->posts_v2_exact_keys( $post, $keys ) || ! in_array( $post['post_type'], array( 'post', 'page' ), true ) || ! in_array( $post['status'], array( 'draft', 'publish' ), true ) || ! in_array( $post['comment_status'], array( 'open', 'closed' ), true ) || ! in_array( $post['ping_status'], array( 'open', 'closed' ), true ) ) {
            return false;
        }
        foreach (
            array(
                'title'   => 512,
                'content' => 200000,
                'excerpt' => 5000,
                'slug'    => 200,
            ) as $key => $limit
        ) {
            if ( ! $this->content_v2_bounded_string( $post[ $key ], $limit ) ) {
                return false;
            }
        }
        if ( '' !== $post['slug'] && sanitize_title( $post['slug'] ) !== $post['slug'] ) {
            return false;
        }
        $categories = $this->content_v2_slug_list( $post['categories'] );
        $tags       = $this->content_v2_slug_list( $post['tags'] );
        if ( false === $categories || false === $tags ) {
            return false;
        }
        if ( 'page' === $post['post_type'] && ( ! empty( $categories ) || ! empty( $tags ) ) ) {
            return false;
        }
        $post['categories'] = $categories;
        $post['tags']       = $tags;
        return $post;
    }

    /**
     * Normalize Post Plus randomization without making a choice.
     *
     * @param mixed $randomization Untrusted randomization payload.
     * @return array|false Normalized randomization or false.
     */
    private function content_v2_normalize_randomization( $randomization ) {
        if ( ! is_array( $randomization ) || ! $this->posts_v2_exact_keys( $randomization, array( 'roles', 'random_category', 'date_from', 'date_to', 'timezone' ) ) || ! is_array( $randomization['roles'] ) || 4 < count( $randomization['roles'] ) || ! is_bool( $randomization['random_category'] ) || ! is_string( $randomization['timezone'] ) || '' === $randomization['timezone'] || 64 < strlen( $randomization['timezone'] ) ) {
            return false;
        }
        $roles = array_values( array_unique( $randomization['roles'] ) );
        sort( $roles, SORT_STRING );
        if ( count( $roles ) !== count( $randomization['roles'] ) || array_diff( $roles, array( 'administrator', 'editor', 'author', 'contributor' ) ) ) {
            return false;
        }
        try {
            $timezone = new \DateTimeZone( $randomization['timezone'] );
        } catch ( \Exception $error ) {
            unset( $error );
            return false;
        }
        $from = $this->content_v2_date( $randomization['date_from'], $timezone );
        $to   = $this->content_v2_date( $randomization['date_to'], $timezone );
        if ( false === $from || false === $to || ( null === $from ) !== ( null === $to ) || ( null !== $from && ( $to < $from || 366 < (int) $from->diff( $to )->format( '%a' ) ) ) ) {
            return false;
        }
        return array(
            'roles'           => $roles,
            'random_category' => $randomization['random_category'],
            'date_from'       => null === $from ? null : $from->format( 'Y-m-d' ),
            'date_to'         => null === $to ? null : $to->format( 'Y-m-d' ),
            'timezone'        => $timezone->getName(),
        );
    }

    /**
     * Resolve and freeze deterministic Post Plus author/category/date choices.
     *
     * @param array $normalized Valid normalized mutation.
     * @return array|false Frozen choices or false.
     */
    private function content_v2_random_choices( $normalized ) {
        $randomization = $normalized['randomization'];
        $seed          = hash( 'sha256', $normalized['dashboard_ref'] . '|' . $normalized['operation_ref'] . '|' . $normalized['content_digest'] );
        $author_ids    = array();
        if ( empty( $randomization['roles'] ) ) {
            $author_ids[] = get_current_user_id();
        } else {
            $users = get_users(
                array(
                    'role__in' => $randomization['roles'],
                    'fields'   => 'ids',
                    'number'   => 1001,
                )
            );
            if ( ! is_array( $users ) || 1000 < count( $users ) ) {
                return false;
            }
            foreach ( $users as $user_id ) {
                if ( is_int( $user_id ) && 1 <= $user_id ) {
                    $author_ids[] = $user_id;
                } elseif ( is_string( $user_id ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $user_id ) && PHP_INT_MAX >= (float) $user_id ) {
                    $author_ids[] = (int) $user_id;
                }
            }
        }
        $author_ids = array_values( array_unique( $author_ids ) );
        sort( $author_ids, SORT_NUMERIC );
        if ( empty( $author_ids ) ) {
            return false;
        }

        $category_id = null;
        if ( $randomization['random_category'] ) {
            $categories = get_categories(
                array(
                    'hide_empty' => false,
                    'number'     => 1001,
                    'fields'     => 'ids',
                )
            );
            if ( ! is_array( $categories ) || empty( $categories ) || 1000 < count( $categories ) ) {
                return false;
            }
            $categories = array_values( array_unique( array_map( 'intval', $categories ) ) );
            if (
                array_filter(
                    $categories,
                    static function ( $category_id ) {
                        return 1 > $category_id;
                    }
                )
            ) {
                return false;
            }
            sort( $categories, SORT_NUMERIC );
            $category_id = $categories[ hexdec( substr( $seed, 8, 8 ) ) % count( $categories ) ];
        }

        $post_date_gmt = null;
        if ( null !== $randomization['date_from'] ) {
            $timezone      = new \DateTimeZone( $randomization['timezone'] );
            $from          = new \DateTimeImmutable( $randomization['date_from'] . ' 00:00:00', $timezone );
            $to            = new \DateTimeImmutable( $randomization['date_to'] . ' 23:59:59', $timezone );
            $seconds       = $to->getTimestamp() - $from->getTimestamp();
            $chosen        = $from->getTimestamp() + ( hexdec( substr( $seed, 16, 8 ) ) % ( $seconds + 1 ) );
            $post_date_gmt = gmdate( 'Y-m-d H:i:s', $chosen );
        }

        return array(
            'author_id'     => $author_ids[ hexdec( substr( $seed, 0, 8 ) ) % count( $author_ids ) ],
            'category_id'   => $category_id,
            'post_date_gmt' => $post_date_gmt,
        );
    }

    /**
     * Apply one reserved operation inside a checked database transaction.
     *
     * @param string $protocol   Closed protocol name.
     * @param string $operation  Closed operation name.
     * @param array  $normalized Valid normalized mutation.
     * @param array  $records    Valid durable ledger.
     * @param array  $choices    Frozen deterministic choices.
     * @return array Closed operation result.
     */
    private function content_v2_apply( $protocol, $operation, $normalized, $records, $choices ) {
        global $wpdb;

        $record = $records[ $normalized['operation_ref'] ];
        if ( ! $this->content_v2_choices_available( $choices ) ) {
            return $this->content_v2_error( $protocol, $operation, 'unsupported_content' );
        }
        $old_post   = 'update' === $normalized['mode'] ? get_post( $normalized['target_post_id'] ) : null;
        $old_status = $old_post instanceof \WP_Post ? $old_post->post_status : '';
        // Taken here because this is the last moment before the operation writes anything; the term
        // IDs content_v2_rollback() collects for its cache purge are read after the writes and cannot
        // stand in for it. The digest is snapshotted for the same reason and not compared against the
        // value this operation means to write: two Dashboards sending identical content produce the
        // same digest, so an equality check would convict every honest repeat of an unchanged update.
        $before = array(
            'post'   => $old_post,
            'terms'  => $old_post instanceof \WP_Post ? $this->content_v2_term_snapshot( $old_post->ID ) : null,
            'digest' => $old_post instanceof \WP_Post ? get_post_meta( $old_post->ID, '_mainwp_child_content_digest_v2', true ) : null,
        );
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return $this->content_v2_error( $protocol, $operation, 'storage_unavailable' );
        }

        $postarr = array(
            'post_type'      => $normalized['post']['post_type'],
            'post_status'    => $normalized['post']['status'],
            'post_title'     => $normalized['post']['title'],
            'post_content'   => $normalized['post']['content'],
            'post_excerpt'   => $normalized['post']['excerpt'],
            'post_name'      => $normalized['post']['slug'],
            'comment_status' => $normalized['post']['comment_status'],
            'ping_status'    => $normalized['post']['ping_status'],
            'post_author'    => $choices['author_id'],
            'meta_input'     => array(
                '_mainwp_child_content_operation_v2' => $normalized['operation_ref'],
                '_mainwp_child_content_digest_v2'    => $normalized['content_digest'],
            ),
        );
        if ( null !== $choices['post_date_gmt'] ) {
            $postarr['post_date_gmt'] = $choices['post_date_gmt'];
            $postarr['post_date']     = get_date_from_gmt( $choices['post_date_gmt'] );
        }
        if ( 'update' === $normalized['mode'] ) {
            $postarr['ID'] = $normalized['target_post_id'];
        }

        // Known hazard: a site listener that issues DDL trips MySQL's implicit commit, after which
        // content_v2_rollback() is a no-op. Firing the hook outside the transaction is not the fix -
        // the listener's own writes would then survive the rollback and run again on the Dashboard
        // retry, which is a common cost paid for a rare one. Instead the rollback reports whether it
        // actually landed, and the failures below stop claiming "no effect" when it did not.
        $hook_post = $postarr;
        unset( $hook_post['meta_input'] );
        do_action(
            'mainwp_before_post_update',
            $hook_post,
            array(),
            implode( ',', $normalized['post']['categories'] ),
            implode( ',', $normalized['post']['tags'] ),
            array()
        );

        $post_id = wp_insert_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $post_id ) || ! is_int( $post_id ) || 1 > $post_id ) {
            $rolled_back = $this->content_v2_rollback( null, $normalized, $before );
            return $this->content_v2_error( $protocol, $operation, $this->content_v2_failure_code( $rolled_back ) );
        }
        if ( ! empty( $normalized['post']['categories'] ) ) {
            $category_ids = $this->content_v2_category_ids( $normalized['post']['categories'] );
            $terms        = false === $category_ids ? false : wp_set_post_categories( $post_id, $category_ids, false );
            if ( false === $terms || is_wp_error( $terms ) ) {
                $rolled_back = $this->content_v2_rollback( $post_id, $normalized, $before );
                return $this->content_v2_error( $protocol, $operation, $this->content_v2_failure_code( $rolled_back ) );
            }
        }
        if ( null !== $choices['category_id'] ) {
            $terms = wp_set_post_categories( $post_id, array( $choices['category_id'] ), true );
            if ( is_wp_error( $terms ) ) {
                $rolled_back = $this->content_v2_rollback( $post_id, $normalized, $before );
                return $this->content_v2_error( $protocol, $operation, $this->content_v2_failure_code( $rolled_back ) );
            }
        }
        if ( ! empty( $normalized['post']['tags'] ) ) {
            $terms = wp_set_post_terms( $post_id, $normalized['post']['tags'], 'post_tag', false );
            if ( is_wp_error( $terms ) ) {
                $rolled_back = $this->content_v2_rollback( $post_id, $normalized, $before );
                return $this->content_v2_error( $protocol, $operation, $this->content_v2_failure_code( $rolled_back ) );
            }
        }

        $revision = $this->content_v2_post_revision( $post_id );
        if ( false === $revision ) {
            $this->content_v2_rollback( $post_id, $normalized, $before );
            return $this->content_v2_error( $protocol, $operation, 'outcome_unknown' );
        }
        $record['post_id']                   = $post_id;
        $record['state']                     = 'applied';
        $record['post_revision']             = $revision;
        $record['remote_post_ref']           = $this->content_v2_remote_ref( $protocol, $record['dashboard_ref'], $post_id );
        $record['updated_at']                = time();
        $records[ $record['operation_ref'] ] = $record;
        if ( ! $this->content_v2_write_records( $protocol, $records ) || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->content_v2_rollback( $post_id, $normalized, $before );
            wp_cache_delete( $this->content_v2_option( $protocol ), 'options' );
            return $this->content_v2_error( $protocol, $operation, 'outcome_unknown' );
        }

        $post = get_post( $post_id );
        do_action(
            'mainwp_child_after_newpost',
            array(
                'success'       => true,
                'link'          => get_permalink( $post_id ),
                'added_id'      => $post_id,
                'new_post_data' => $post instanceof \WP_Post ? array(
                    'post_id'       => $post_id,
                    'post_type'     => $post->post_type,
                    'post_title'    => $post->post_title,
                    'post_date'     => $post->post_date,
                    'post_date_gmt' => $post->post_date_gmt,
                    'new_status'    => $post->post_status,
                    'old_status'    => $old_status,
                    'singular_name' => strtolower( $this->get_post_type_name( $post->post_type ) ),
                    'is_editing'    => 'update' === $normalized['mode'] ? 1 : 0,
                ) : array(),
            )
        );
        return $this->content_v2_project( $protocol, $operation, $record );
    }

    /**
     * Roll the mutation transaction back and forget every post it warmed.
     *
     * The post writers prime the object cache before the transaction is decided, so a
     * persistent cache keeps serving the mutated row and meta after the database has
     * discarded them - while the Dashboard is told the mutation failed.
     *
     * The term writers do the same to the taxonomy side: wp_set_object_terms() moves term counts
     * and anything reading a term while the transaction is open caches the in-transaction row, so
     * the terms the mutation attached are collected before the ROLLBACK and forgotten after it.
     * Categories and tags are the whole taxonomy surface this writer touches.
     *
     * @param int|null $post_id    Post touched inside the transaction, when one exists.
     * @param array    $normalized Valid normalized mutation.
     * @param array    $before     Update target as it stood before the transaction opened: 'post'
     *                             (\WP_Post|null), 'terms' (array|null), 'digest' (string|null).
     * @return bool True when no trace of this operation survived, false when one did.
     */
    private function content_v2_rollback( $post_id, $normalized, $before ) {
        global $wpdb;

        $touched = array_values(
            array_unique(
                array_filter(
                    array( $post_id, 'update' === $normalized['mode'] ? $normalized['target_post_id'] : null ),
                    static function ( $id ) {
                        return is_int( $id ) && 0 < $id;
                    }
                )
            )
        );

        $term_ids = array();
        foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
            $ids = array() === $touched ? array() : wp_get_object_terms( $touched, $taxonomy, array( 'fields' => 'ids' ) );
            if ( is_array( $ids ) && array() !== $ids ) {
                $term_ids[ $taxonomy ] = $ids;
            }
        }

        $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction control has no wpdb wrapper, and caching a ROLLBACK is meaningless.

        foreach ( $touched as $id ) {
            clean_post_cache( $id );
        }
        foreach ( $term_ids as $taxonomy => $ids ) {
            clean_term_cache( $ids, $taxonomy );
        }

        // Whether the ROLLBACK landed is not knowable from its return value once a hook listener may
        // have closed the transaction under it, so the answer comes from storage instead. Only the
        // purge above makes that re-read honest - before it, the in-transaction row is still cached.
        // Every check below may only convict, never acquit: any one of them finding a trace is the
        // answer, and reaching the end without one is the only way to report a landed rollback. That
        // is why the operation stamp appears among them but never clears anything - wp_insert_post()
        // drops what update_post_meta() returns, so a post the implicit commit made durable can be
        // carrying no stamp at all.
        foreach ( $touched as $id ) {
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }
            if ( 'update' !== $normalized['mode'] || (int) $id !== (int) $normalized['target_post_id'] ) {
                // Nothing but this operation's own insert could have created the row, so finding one
                // still there is the whole trace.
                return false;
            }
            if ( ! $before['post'] instanceof \WP_Post ) {
                // The witness is the pre-operation row, and without it there is nothing to compare
                // against. Guessing in the site's favour is the guess that sends the Dashboard back
                // to retry a write that may already be durable.
                return false;
            }
            if ( get_post_meta( $id, '_mainwp_child_content_operation_v2', true ) === $normalized['operation_ref'] ) {
                // Positive evidence only, which is not the check that was taken out of here. What was
                // wrong was reading a *missing* stamp as proof the rollback landed: wp_insert_post()
                // throws away what update_post_meta() returns, so a durable row can carry no stamp at
                // all. Finding this operation's own ref stored has no such hole - the stamp is written
                // inside the transaction and nothing outside this operation writes that value - so it
                // is the write itself outliving the ROLLBACK. An update target that was not restamped
                // still carries some earlier operation's ref, which never equals this one.
                return false;
            }
            // The stamp is not the only meta this operation writes, and meta_input writes each entry
            // on its own: the digest can land durably in the same breath the stamp write is refused,
            // which leaves the check above silent. It is compared against the snapshot rather than
            // against what this operation meant to store because a digest is not unique to an
            // operation the way a ref is - re-sending identical content produces the identical digest,
            // so an equality check would report outcome_unknown for every clean repeat. A snapshot
            // too broken to compare is a state that cannot be cleared, the same as a missing row.
            $stored_digest = get_post_meta( $id, '_mainwp_child_content_digest_v2', true );
            if ( ! is_string( $before['digest'] ) || $before['digest'] !== $stored_digest ) {
                return false;
            }
            // The question is whether the row is what it was before this operation, never whether
            // what the operation meant to write is there. Anything on wp_insert_post()'s own path -
            // wp_insert_post_data above all - may rewrite a field between the payload and storage,
            // so intended values can match nothing in the row while the write is perfectly durable;
            // the snapshot answers without reading the payload at all. The fields are the columns
            // wp_insert_post() writes, so no surviving part of this write can land outside them,
            // and post_modified_gmt is among them because the update path restamps it even when the
            // content it stored was unchanged.
            //
            // Equal columns end nothing on their own: an update that stored the values the row
            // already held leaves them all equal (down to a restamp inside the same second) while
            // the meta and term writes this operation also makes may be sitting there durable, so
            // the checks around this one are what answer for the rest of the write.
            $columns = array(
                'post_author',
                'post_date',
                'post_date_gmt',
                'post_content',
                'post_content_filtered',
                'post_title',
                'post_excerpt',
                'post_status',
                'post_type',
                'comment_status',
                'ping_status',
                'post_password',
                'post_name',
                'to_ping',
                'pinged',
                'post_modified',
                'post_modified_gmt',
                'post_parent',
                'menu_order',
                'post_mime_type',
                'guid',
            );
            foreach ( $columns as $field ) {
                if ( (string) $before['post']->$field !== (string) $post->$field ) {
                    return false;
                }
            }
            // The categories and tags are written after the row and are the rest of what a closed
            // transaction can leave behind, so the same question has to be put to them: is the
            // taxonomy what it was before this operation ran? Sets of IDs, sorted, because the order
            // wp_get_object_terms() returns is not a change. An unreadable state on either side is
            // a state that cannot be cleared, which is outcome_unknown for the same reason a missing
            // post snapshot is.
            $new_terms = $this->content_v2_term_snapshot( $id );
            if ( null === $before['terms'] || null === $new_terms || $before['terms'] !== $new_terms ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Read one post's category and tag membership as a comparable set of term IDs.
     *
     * @param int $post_id Positive post ID.
     * @return array|null Sorted term IDs per taxonomy, or null when a taxonomy could not be read.
     */
    private function content_v2_term_snapshot( $post_id ) {
        $snapshot = array();
        foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
            $ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_array( $ids ) ) {
                return null;
            }
            $ids = array_map( 'intval', $ids );
            sort( $ids, SORT_NUMERIC );
            $snapshot[ $taxonomy ] = $ids;
        }
        return $snapshot;
    }

    /**
     * Name the failure a rolled-back mutation may honestly claim.
     *
     * The Dashboard retries on mutation_failed, so that code may only be given when the site really
     * is untouched.
     *
     * @param bool $rolled_back Whether the rollback demonstrably left no trace.
     * @return string Closed error code.
     */
    private function content_v2_failure_code( $rolled_back ) {
        return $rolled_back ? 'mutation_failed' : 'outcome_unknown';
    }

    /**
     * Return one canonical revision for the actual stored post fields.
     *
     * @param int $post_id Positive post ID.
     * @return string|false Revision or false.
     */
    private function content_v2_post_revision( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
            return false;
        }
        $categories = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
        $tags       = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'slugs' ) );
        if ( is_wp_error( $categories ) || is_wp_error( $tags ) || ! is_array( $categories ) || ! is_array( $tags ) ) {
            return false;
        }
        sort( $categories, SORT_STRING );
        sort( $tags, SORT_STRING );
        return hash(
            'sha256',
            wp_json_encode(
                array(
                    'post_type'      => $post->post_type,
                    'status'         => $post->post_status,
                    'title'          => $post->post_title,
                    'content'        => $post->post_content,
                    'excerpt'        => $post->post_excerpt,
                    'slug'           => $post->post_name,
                    'comment_status' => $post->comment_status,
                    'ping_status'    => $post->ping_status,
                    'author_id'      => (int) $post->post_author,
                    'post_date_gmt'  => $post->post_date_gmt,
                    'categories'     => $categories,
                    'tags'           => $tags,
                )
            )
        );
    }

    /**
     * Return one redacted operation response, optionally with current readback.
     *
     * @param string $protocol  Closed protocol name.
     * @param string $operation Closed operation name.
     * @param array  $record    Valid operation record.
     * @param bool   $readback  Whether to add current readback.
     * @return array Closed response.
     */
    private function content_v2_project( $protocol, $operation, $record, $readback = false ) {
        $response = array(
            'protocol'        => '2',
            'operation'       => $operation,
            'ok'              => true,
            'state'           => $record['state'],
            'operation_ref'   => $record['operation_ref'],
            'content_digest'  => $record['content_digest'],
            'post_revision'   => $record['post_revision'],
            'remote_post_ref' => $record['remote_post_ref'],
            'retryable'       => $record['retryable'],
            'updated_at'      => $record['updated_at'],
        );
        if ( $readback ) {
            $post     = is_int( $record['post_id'] ) ? get_post( $record['post_id'] ) : null;
            $revision = $post instanceof \WP_Post ? $this->content_v2_post_revision( $post->ID ) : false;
            if ( ! $post instanceof \WP_Post || false === $revision ) {
                return $this->content_v2_error( $protocol, $operation, 'readback_unavailable' );
            }
            $response['readback'] = array(
                'post_type' => $post->post_type,
                'status'    => $post->post_status,
                'revision'  => $revision,
            );
        }
        return $response;
    }

    /**
     * Load one bounded private operation ledger, keeping every entry that still means something.
     *
     * The option is untrusted input, and failing the whole ledger over one damaged entry would
     * cost every later mutation on this site. Dropping the entry is not free either: an entry
     * filed under a real operation reference is the evidence that this operation already ran
     * here, and without it the still-live original request reserves again and commits a second
     * post. So a damaged entry under a valid reference is quarantined rather than deleted, and
     * only an entry whose key is not an operation reference - which no request can ever replay
     * against - is dropped. Reserved receipts that still validate are untouched.
     *
     * @param string $protocol Closed protocol name.
     * @param bool   $repaired Receives whether the stored ledger differs from the returned one.
     * @return array|false Valid ledger or false.
     */
    private function content_v2_records( $protocol, &$repaired = null ) {
        $repaired = false;
        $records  = get_option( $this->content_v2_option( $protocol ), array() );
        if ( ! is_array( $records ) ) {
            return false;
        }
        $valid = array();
        foreach ( $records as $operation_ref => $record ) {
            if ( is_string( $operation_ref ) && $this->content_v2_record( $record ) && hash_equals( $operation_ref, $record['operation_ref'] ) ) {
                $valid[ $operation_ref ] = $record;
            } elseif ( $this->content_v2_uuid( $operation_ref ) ) {
                $valid[ $operation_ref ] = $this->content_v2_quarantine_record( $operation_ref, $record );
                $repaired                = true;
            } else {
                $repaired = true;
            }
        }
        return self::CONTENT_V2_MAX_RECORDS < count( $valid ) ? false : $valid;
    }

    /**
     * Rebuild one damaged entry as a quarantined unknown outcome under the same reference.
     *
     * The reference is what a retry is matched against, so keeping it is what stops the retry
     * from reserving a second time. What the damaged entry no longer proves is the outcome, so
     * everything it could have claimed about the effect is reset to the unknown, non-retryable
     * reading: no post id, no revision, no remote reference, and no route back into the apply.
     * The identity fields are kept where they still read, so a genuine retry is answered with
     * that unknown state instead of a conflict it cannot act on.
     *
     * @param string $operation_ref Valid operation reference taken from the ledger key.
     * @param mixed  $record        Damaged entry.
     * @return array Valid quarantined record.
     */
    private function content_v2_quarantine_record( $operation_ref, $record ) {
        $source      = is_array( $record ) ? $record : array();
        $unknown     = str_repeat( '0', 64 );
        $now         = time();
        $accepted_at = isset( $source['accepted_at'] ) && is_int( $source['accepted_at'] ) && 1 <= $source['accepted_at'] && $now >= $source['accepted_at'] ? $source['accepted_at'] : $now;
        $updated_at  = isset( $source['updated_at'] ) && is_int( $source['updated_at'] ) && $accepted_at <= $source['updated_at'] && $now >= $source['updated_at'] ? $source['updated_at'] : $now;
        return array(
            'dashboard_ref'     => isset( $source['dashboard_ref'] ) && $this->content_v2_hash( $source['dashboard_ref'] ) ? $source['dashboard_ref'] : $unknown,
            'operation_ref'     => $operation_ref,
            'effect_hash'       => isset( $source['effect_hash'] ) && $this->content_v2_hash( $source['effect_hash'] ) ? $source['effect_hash'] : $unknown,
            'content_digest'    => isset( $source['content_digest'] ) && $this->content_v2_hash( $source['content_digest'] ) ? $source['content_digest'] : $unknown,
            'mode'              => 'create',
            'target_post_id'    => null,
            'expected_revision' => null,
            'post_id'           => null,
            'state'             => 'unknown',
            'post_revision'     => null,
            'remote_post_ref'   => null,
            'retryable'         => false,
            'choices'           => array(
                'author_id'     => 1,
                'category_id'   => null,
                'post_date_gmt' => null,
            ),
            'accepted_at'       => $accepted_at,
            'updated_at'        => $updated_at,
        );
    }

    /**
     * Remove only receipts older than the required retry-retention window.
     *
     * @param string $protocol       Closed protocol name.
     * @param array  $records        Valid durable ledger.
     * @param bool   $persist_repair Whether the caller's load repaired the ledger and must store it.
     * @return array|false Pruned ledger or false.
     */
    private function content_v2_prune_records( $protocol, $records, $persist_repair = false ) {
        $minimum = time() - self::CONTENT_V2_RETENTION;
        $changed = $persist_repair;
        foreach ( $records as $operation_ref => $record ) {
            if ( $record['updated_at'] < $minimum ) {
                unset( $records[ $operation_ref ] );
                $changed = true;
            }
        }
        return ( ! $changed || $this->content_v2_write_records( $protocol, $records ) ) ? $records : false;
    }

    /**
     * Free one ledger slot by dropping only receipts that can no longer be replayed.
     *
     * The cap is reached long before the retention window expires, so pruning by age
     * alone leaves a full ledger rejecting every new mutation for the rest of the
     * ninety days. A request is accepted for at most a day (see the expires_at bound in
     * the payload validator), so a settled receipt older than that can only ever be
     * answered expired_request - dropping it cannot turn a replay into a second post.
     * Reserved receipts are never dropped: their effect is still unconfirmed, and losing
     * one is exactly what would let a retry duplicate content.
     *
     * @param string $protocol Closed protocol name.
     * @param array  $records  Valid durable ledger.
     * @return array|false Ledger below the cap, or false when no slot may be freed.
     */
    private function content_v2_evict_records( $protocol, $records ) {
        $horizon    = time() - ( DAY_IN_SECONDS + 60 );
        $candidates = array();
        foreach ( $records as $operation_ref => $record ) {
            if ( 'reserved' !== $record['state'] && $record['accepted_at'] < $horizon ) {
                $candidates[ $operation_ref ] = $record['accepted_at'];
            }
        }
        asort( $candidates, SORT_NUMERIC );
        foreach ( array_keys( $candidates ) as $operation_ref ) {
            if ( count( $records ) < self::CONTENT_V2_MAX_RECORDS ) {
                break;
            }
            unset( $records[ $operation_ref ] );
        }
        if ( self::CONTENT_V2_MAX_RECORDS <= count( $records ) ) {
            return false;
        }
        return $this->content_v2_write_records( $protocol, $records ) ? $records : false;
    }

    /**
     * Validate one closed durable content-operation record.
     *
     * @param mixed $record Candidate record.
     * @return bool Whether the record is valid.
     */
    private function content_v2_record( $record ) {
        $keys = array( 'dashboard_ref', 'operation_ref', 'effect_hash', 'content_digest', 'mode', 'target_post_id', 'expected_revision', 'post_id', 'state', 'post_revision', 'remote_post_ref', 'retryable', 'choices', 'accepted_at', 'updated_at' );
        if ( ! is_array( $record ) || array_keys( $record ) !== $keys || ! $this->content_v2_hash( $record['dashboard_ref'] ) || ! $this->content_v2_uuid( $record['operation_ref'] ) || ! $this->content_v2_hash( $record['effect_hash'] ) || ! $this->content_v2_hash( $record['content_digest'] ) || ! in_array( $record['mode'], array( 'create', 'update' ), true ) || ! in_array( $record['state'], array( 'reserved', 'applied', 'not_applied', 'unknown' ), true ) || ! is_bool( $record['retryable'] ) || ! is_int( $record['accepted_at'] ) || 1 > $record['accepted_at'] || ! is_int( $record['updated_at'] ) || $record['updated_at'] < $record['accepted_at'] ) {
            return false;
        }
        if ( ( 'create' === $record['mode'] && ( null !== $record['target_post_id'] || null !== $record['expected_revision'] ) ) || ( 'update' === $record['mode'] && ( ! is_int( $record['target_post_id'] ) || 1 > $record['target_post_id'] || ! $this->content_v2_hash( $record['expected_revision'] ) ) ) ) {
            return false;
        }
        if ( ! $this->content_v2_choice_shape( $record['choices'] ) ) {
            return false;
        }
        if ( 'applied' === $record['state'] ) {
            return is_int( $record['post_id'] ) && 1 <= $record['post_id'] && $this->content_v2_hash( $record['post_revision'] ) && $this->content_v2_hash( $record['remote_post_ref'] ) && false === $record['retryable'];
        }
        return null === $record['post_id'] && null === $record['post_revision'] && null === $record['remote_post_ref'] && ( ( 'not_applied' === $record['state'] ) === $record['retryable'] );
    }

    /**
     * Validate one persisted deterministic-choice tuple.
     *
     * @param mixed $choices Candidate choices.
     * @return bool Whether the choices are valid.
     */
    private function content_v2_choice_shape( $choices ) {
        return is_array( $choices ) && array_keys( $choices ) === array( 'author_id', 'category_id', 'post_date_gmt' ) && is_int( $choices['author_id'] ) && 1 <= $choices['author_id'] && ( null === $choices['category_id'] || ( is_int( $choices['category_id'] ) && 1 <= $choices['category_id'] ) ) && ( null === $choices['post_date_gmt'] || ( is_string( $choices['post_date_gmt'] ) && 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $choices['post_date_gmt'] ) ) );
    }

    /**
     * Prove that frozen author/category choices still resolve before mutation.
     *
     * @param mixed $choices Candidate choices.
     * @return bool Whether all choices resolve.
     */
    private function content_v2_choices_available( $choices ) {
        if ( ! $this->content_v2_choice_shape( $choices ) || ! get_userdata( $choices['author_id'] ) instanceof \WP_User ) {
            return false;
        }
        if ( null === $choices['category_id'] ) {
            return true;
        }
        $category = get_term( $choices['category_id'], 'category' );
        return $category instanceof \WP_Term;
    }

    /**
     * Persist one changed operation ledger with nonautoloaded checked readback.
     *
     * @param string $protocol Closed protocol name.
     * @param array  $records  Valid changed ledger.
     * @return bool Whether exact persistence was proven.
     */
    private function content_v2_write_records( $protocol, $records ) {
        $option = $this->content_v2_option( $protocol );
        $wrote  = update_option( $option, $records, false );
        $stored = get_option( $option, null );
        return ( $wrote || $stored === $records ) && $stored === $records;
    }

    /**
     * Acquire the protocol's cross-request mutation lock.
     *
     * @param string $protocol Closed protocol name.
     * @return bool Whether the lock is held.
     */
    private function content_v2_begin_lock( $protocol ) {
        global $wpdb;
        $wpdb->last_error = '';
        $locked           = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', 'mainwp_child_' . $protocol . '_v2' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return empty( $wpdb->last_error ) && '1' === (string) $locked;
    }

    /**
     * Release the protocol lock and forget any unproved ownership.
     *
     * @param string $protocol Closed protocol name.
     * @return bool Whether release was proven.
     */
    private function content_v2_end_lock( $protocol ) {
        global $wpdb;
        $wpdb->last_error = '';
        $released         = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'mainwp_child_' . $protocol . '_v2' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return empty( $wpdb->last_error ) && '1' === (string) $released;
    }

    /**
     * Return the closed option name for one protocol.
     *
     * @param string $protocol Closed protocol name.
     * @return string Private option name.
     */
    private function content_v2_option( $protocol ) {
        return 'post_plus' === $protocol ? 'mainwp_child_post_plus_operations_v2' : 'mainwp_child_post_dripper_operations_v2';
    }

    /** Return the current connected Dashboard's canonical local identity digest. */
    private function content_v2_dashboard_ref() {
        $server = MainWP_Child_Keys_Manager::get_encrypted_option( 'mainwp_child_server', '' );
        if ( ! is_string( $server ) || '' === $server || 2048 < strlen( $server ) ) {
            return false;
        }
        $parts = wp_parse_url( $server );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return false;
        }
        $port = isset( $parts['port'] ) && is_int( $parts['port'] ) && 1 <= $parts['port'] && 65535 >= $parts['port'] ? ':' . $parts['port'] : '';
        $path = isset( $parts['path'] ) && is_string( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '';
        if ( '/wp-admin' === substr( $path, -9 ) ) {
            $path = substr( $path, 0, -9 );
        }
        return hash( 'sha256', strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . $port . rtrim( $path, '/' ) );
    }

    /**
     * Return one installation-bound opaque remote post reference.
     *
     * @param string $protocol      Closed protocol name.
     * @param string $dashboard_ref Current Dashboard digest.
     * @param int    $post_id       Positive post ID.
     * @return string Opaque post reference.
     */
    private function content_v2_remote_ref( $protocol, $dashboard_ref, $post_id ) {
        return hash_hmac( 'sha256', $protocol . '|' . $dashboard_ref . '|' . $post_id, wp_salt( 'auth' ) );
    }

    /**
     * Parse one exact calendar date without accepting PHP normalization.
     *
     * @param mixed         $value    Candidate date.
     * @param \DateTimeZone $timezone Valid timezone.
     * @return \DateTimeImmutable|null|false Exact date, null, or false.
     */
    private function content_v2_date( $value, $timezone ) {
        if ( null === $value ) {
            return null;
        }
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value ) ) {
            return false;
        }
        $date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
        $errors = \DateTimeImmutable::getLastErrors();
        return false === $date || ( is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ? false : $date;
    }

    /**
     * Normalize one unique bounded taxonomy-slug list.
     *
     * @param mixed $values Candidate list.
     * @return array|false Normalized list or false.
     */
    private function content_v2_slug_list( $values ) {
        if ( ! is_array( $values ) || 100 < count( $values ) ) {
            return false;
        }
        $out = array();
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) || '' === $value || 200 < strlen( $value ) || sanitize_title( $value ) !== $value || isset( $out[ $value ] ) ) {
                return false;
            }
            $out[ $value ] = true;
        }
        $values = array_keys( $out );
        sort( $values, SORT_STRING );
        return $values;
    }

    /**
     * Resolve or create bounded category slugs as positive term IDs.
     *
     * @param array $slugs Valid canonical category slugs.
     * @return array|false Positive IDs or false.
     */
    private function content_v2_category_ids( $slugs ) {
        $ids = array();
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, 'category' );
            if ( false === $term ) {
                $created = wp_insert_term( $slug, 'category', array( 'slug' => $slug ) );
                if ( is_wp_error( $created ) ) {
                    if ( 'term_exists' !== $created->get_error_code() || ! is_int( $created->get_error_data() ) ) {
                        return false;
                    }
                    $term_id = $created->get_error_data();
                } elseif ( ! is_array( $created ) || ! isset( $created['term_id'] ) || ! is_int( $created['term_id'] ) ) {
                    return false;
                } else {
                    $term_id = $created['term_id'];
                }
            } else {
                $term_id = $term instanceof \WP_Term ? (int) $term->term_id : 0;
            }
            if ( 1 > $term_id ) {
                return false;
            }
            $ids[] = $term_id;
        }
        return $ids;
    }

    /**
     * Validate one bounded UTF-8 string with no NUL byte.
     *
     * @param mixed $value Candidate string.
     * @param int   $max   Maximum bytes.
     * @return bool Whether the string is valid.
     */
    private function content_v2_bounded_string( $value, $max ) {
        return is_string( $value ) && $max >= strlen( $value ) && false === strpos( $value, "\0" ) && wp_check_invalid_utf8( $value ) === $value;
    }

    /**
     * Validate one SHA-256 hexadecimal value.
     *
     * @param mixed $value Candidate digest.
     * @return bool Whether the digest is valid.
     */
    private function content_v2_hash( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /**
     * Validate one canonical RFC 4122 UUID.
     *
     * @param mixed $value Candidate reference.
     * @return bool Whether the reference is valid.
     */
    private function content_v2_uuid( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value );
    }

    /**
     * Build one closed content-protocol error.
     *
     * @param string $protocol  Closed protocol name.
     * @param string $operation Closed operation name.
     * @param string $code      Stable error code.
     * @return array Closed error.
     */
    private function content_v2_error( $protocol, $operation, $code ) {
        return 'post_plus' === $protocol ? $this->post_plus_v2_error( $operation, $code ) : $this->post_dripper_v2_error( $operation, $code );
    }

    /**
     * Normalize one closed v2 query.
     *
     * @param mixed $request Decoded request.
     * @return array|false Normalized query or false.
     */
    private function normalize_posts_v2_request( $request ) {
        if ( ! is_array( $request ) || ! $this->posts_v2_exact_keys( $request, array( 'protocol', 'operation', 'query' ) ) || '2' !== $request['protocol'] || 'get_all_posts_v2' !== $request['operation'] || ! is_array( $request['query'] ) || ! $this->posts_v2_exact_keys( $request['query'], array( 'post_types', 'statuses', 'keyword', 'date_from', 'date_to', 'post_id', 'author_id', 'page_size', 'cursor' ) ) ) {
            return false;
        }

        $query = $request['query'];
        if ( ! $this->posts_v2_enum_list( $query['post_types'], array( 'post', 'page' ), 2 ) || ! $this->posts_v2_enum_list( $query['statuses'], array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ), 6 ) || ! is_int( $query['page_size'] ) || 1 > $query['page_size'] || 100 < $query['page_size'] || ( null !== $query['cursor'] && ( ! is_string( $query['cursor'] ) || 80 < strlen( $query['cursor'] ) ) ) ) {
            return false;
        }
        if ( null !== $query['keyword'] && ( ! is_string( $query['keyword'] ) || 200 < $this->posts_v2_length( $query['keyword'] ) || wp_check_invalid_utf8( $query['keyword'] ) !== $query['keyword'] ) ) {
            return false;
        }
        if ( ! $this->posts_v2_nullable_positive_integer( $query['post_id'] ) || ! $this->posts_v2_nullable_positive_integer( $query['author_id'] ) || ( null !== $query['post_id'] && null !== $query['author_id'] ) ) {
            return false;
        }
        if ( ! $this->posts_v2_date( $query['date_from'] ) || ! $this->posts_v2_date( $query['date_to'] ) ) {
            return false;
        }
        if ( null !== $query['date_from'] && null !== $query['date_to'] ) {
            $from = strtotime( $query['date_from'] . ' 00:00:00 UTC' );
            $to   = strtotime( $query['date_to'] . ' 23:59:59 UTC' );
            if ( false === $from || false === $to || $from > $to || 366 * DAY_IN_SECONDS < $to - $from ) {
                return false;
            }
        }

        sort( $query['post_types'] );
        sort( $query['statuses'] );
        return $query;
    }

    /**
     * Project one bounded record.
     *
     * @param \WP_Post $post Post row.
     * @return array|false Record or false.
     */
    private function posts_v2_record( $post ) {
        if ( ! $post instanceof \WP_Post || 1 > (int) $post->ID || ! in_array( $post->post_status, array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ), true ) ) {
            return false;
        }
        $url    = $this->posts_v2_url( get_permalink( $post ) );
        $title  = $this->posts_v2_string( get_the_title( $post ), 500 );
        $author = $this->posts_v2_string( get_the_author_meta( 'display_name', $post->post_author ), 200 );
        $date   = get_post_time( 'Y-m-d\TH:i:s\Z', true, $post );
        if ( false === $url || false === $title || false === $author || ! is_string( $date ) ) {
            return false;
        }
        return array(
            'post_id' => (int) $post->ID,
            'title'   => $title,
            'url'     => $url,
            'date'    => $date,
            'status'  => $post->post_status,
            'author'  => $author,
        );
    }

    /**
     * Remove userinfo and common secret query fields from a post URL.
     *
     * @param mixed $url Candidate URL.
     * @return string|false Safe URL or false.
     */
    private function posts_v2_url( $url ) {
        if ( ! is_string( $url ) || 2048 < strlen( $url ) ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return false;
        }
        return remove_query_arg( array( '_wpnonce', 'nonce', 'token', 'access_token', 'key', 'signature', 'password', 'auth', 'code' ), $url );
    }

    /**
     * Build an authenticated opaque page cursor.
     *
     * @param int    $offset Next result offset.
     * @param string $generation Query generation.
     * @return string Cursor.
     */
    private function posts_v2_cursor( $offset, $generation ) {
        $mac = hash_hmac( 'sha256', $generation . ':' . $offset, wp_salt( 'auth' ), true );
        return $offset . '.' . rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url-encodes an HMAC for the cursor, not code.
    }

    /**
     * Verify one cursor and return its offset.
     *
     * @param string $cursor Cursor.
     * @param string $generation Query generation.
     * @return int|false Offset or false.
     */
    private function posts_v2_cursor_offset( $cursor, $generation ) {
        if ( 1 !== preg_match( '/^([1-9][0-9]{0,4})\.([A-Za-z0-9_-]{43})$/D', $cursor, $matches ) ) {
            return false;
        }
        $offset   = (int) $matches[1];
        $expected = $this->posts_v2_cursor( $offset, $generation );
        return 10000 >= $offset && hash_equals( $expected, $cursor ) ? $offset : false;
    }

    /**
     * Whether a list holds unique values drawn only from the allowed set.
     *
     * @param mixed $values  Values to check.
     * @param array $allowed Allowed values.
     * @param int   $maximum Maximum number of values.
     * @return bool
     */
    private function posts_v2_enum_list( $values, $allowed, $maximum ) {
        if ( ! is_array( $values ) || empty( $values ) || $maximum < count( $values ) || count( $values ) !== count( array_unique( $values, SORT_REGULAR ) ) ) {
            return false;
        }
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a value is null or a positive integer.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private function posts_v2_nullable_positive_integer( $value ) {
        return null === $value || ( is_int( $value ) && 1 <= $value );
    }

    /**
     * Whether a value is null or a real calendar date in Y-m-d form.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private function posts_v2_date( $value ) {
        if ( null === $value ) {
            return true;
        }
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value ) ) {
            return false;
        }
        $parts = array_map( 'intval', explode( '-', $value ) );
        return checkdate( $parts[1], $parts[2], $parts[0] );
    }

    /**
     * Character length of a string, falling back to bytes without mbstring.
     *
     * @param string $value String to measure.
     * @return int
     */
    private function posts_v2_length( $value ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
    }

    /**
     * Accept a UTF-8 string no longer than the given character limit.
     *
     * @param mixed $value   Value to check.
     * @param int   $maximum Maximum length in characters.
     * @return string|false
     */
    private function posts_v2_string( $value, $maximum ) {
        return is_string( $value ) && wp_check_invalid_utf8( $value ) === $value && $maximum >= $this->posts_v2_length( $value ) ? $value : false;
    }

    /**
     * Whether an array holds exactly the given keys, no more and no fewer.
     *
     * @param mixed $value Value to inspect.
     * @param array $keys  Required keys.
     * @return bool
     */
    private function posts_v2_exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual );
        sort( $keys );
        return $actual === $keys;
    }

    /**
     * Build a closed get_all_posts_v2 protocol error.
     *
     * @param string $code Stable error code.
     * @return array
     */
    private function posts_v2_error( $code ) {
        return array(
            'protocol'  => '2',
            'operation' => 'get_all_posts_v2',
            'ok'        => false,
            'code'      => $code,
        );
    }

    /**
     * Build a closed Post Dripper protocol error.
     *
     * @param mixed  $operation Operation name.
     * @param string $code      Stable error code.
     * @return array
     */
    private function post_dripper_v2_error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => is_string( $operation ) && '' !== $operation ? $operation : 'unknown',
            'ok'        => false,
            'code'      => $code,
        );
    }

    /**
     * Build a closed Post Plus protocol error.
     *
     * @param mixed  $operation Operation name.
     * @param string $code      Stable error code.
     * @return array
     */
    private function post_plus_v2_error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => is_string( $operation ) && '' !== $operation ? $operation : 'unknown',
            'ok'        => false,
            'code'      => $code,
        );
    }

    /**
     * Get all pages.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::get_all_posts_by_type()
     */
    public function get_all_pages() {
        $this->get_all_posts_by_type( 'page' );
    }

    /**
     * Append the Post's SQL WHERE clause suffix.
     *
     * @param string $where Post's SQL WHERE clause.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::posts_where_suffix()
     *
     * @return string $where The full SQL WHERE clause with the appended suffix.
     */
    public function posts_where( $where ) {
        if ( $this->posts_where_suffix ) {
            $where .= ' ' . $this->posts_where_suffix;
        }

        return $where;
    }

    /**
     * Get all posts by type.
     *
     * @param string $type Post type.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::posts_where_suffix()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function get_all_posts_by_type( $type ) { //phpcs:ignore -- NOSONAR - complex.

        /**
         * Object, providing access to the WordPress database.
         *
         * @global object $wpdb WordPress Database instance.
         */
        global $wpdb;

        // phpcs:disable WordPress.Security.NonceVerification
        add_filter( 'posts_where', array( &$this, 'posts_where' ) );
        $where_post_date = isset( $_POST['where_post_date'] ) && ! empty( $_POST['where_post_date'] ) ? true : false;
        if ( isset( $_POST['postId'] ) ) {
            $this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.ID = %d ", sanitize_text_field( wp_unslash( $_POST['postId'] ) ) );
        } elseif ( isset( $_POST['userId'] ) ) {
            $this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_author = %d ", sanitize_text_field( wp_unslash( $_POST['userId'] ) ) );
        } else {
            if ( isset( $_POST['keyword'] ) && '' !== $_POST['keyword'] ) {
                $search_on = isset( $_POST['search_on'] ) ? sanitize_text_field( wp_unslash( $_POST['search_on'] ) ) : '';
                if ( 'title' === $search_on ) {
                    $this->posts_where_suffix .= $wpdb->prepare( " AND ( $wpdb->posts.post_title LIKE %s ) ", '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%' );
                } elseif ( 'content' === $search_on ) {
                    $this->posts_where_suffix .= $wpdb->prepare( " AND ( $wpdb->posts.post_content LIKE %s )", '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%' );
                } else {
                    $this->posts_where_suffix .= $wpdb->prepare( " AND ( $wpdb->posts.post_content LIKE %s OR $wpdb->posts.post_title LIKE  %s )", '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%', '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%' );
                }
            }
            if ( isset( $_POST['dtsstart'] ) && '' !== $_POST['dtsstart'] ) {
                if ( $where_post_date ) {
                    $this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_date > %s", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstart'] ) ) ) );
                } else {
                    $this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_modified > %s", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstart'] ) ) ) );
                }
            }
            if ( isset( $_POST['dtsstop'] ) && '' !== $_POST['dtsstop'] ) {
                if ( $where_post_date ) {
                    $this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_date < %s ", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstop'] ) ) ) );
                } else {
                    $this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_modified < %s", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstop'] ) ) ) );
                }
            }

            if ( isset( $_POST['exclude_page_type'] ) && sanitize_text_field( wp_unslash( $_POST['exclude_page_type'] ) ) ) {
                $this->posts_where_suffix .= " AND $wpdb->posts.post_type NOT IN ('page')";
            }
        }

        $maxPages = 50;
        if ( defined( 'MAINWP_CHILD_NR_OF_PAGES' ) ) {
            $maxPages = MAINWP_CHILD_NR_OF_PAGES;
        }

        if ( isset( $_POST['maxRecords'] ) ) {
            $maxPages = ! empty( $_POST['maxRecords'] ) ? intval( wp_unslash( $_POST['maxRecords'] ) ) : 0;
        }
        if ( 0 === $maxPages ) {
            $maxPages = 99999;
        }

        $extra = array();
        if ( isset( $_POST['extract_tokens'] ) ) {
            $extra['tokens']            = isset( $_POST['extract_tokens'] ) ? json_decode( base64_decode( wp_unslash( $_POST['extract_tokens'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- base64_encode function is used for http encode compatible..
            $extra['extract_post_type'] = isset( $_POST['extract_post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['extract_post_type'] ) ) : '';
        }

        $extra['where_post_date'] = $where_post_date;
        $rslt                     = isset( $_POST['status'] ) ? $this->get_recent_posts( explode( ',', sanitize_text_field( wp_unslash( $_POST['status'] ) ) ), $maxPages, $type, $extra ) : '';
        $this->posts_where_suffix = '';
        // phpcs:enable WordPress.Security.NonceVerification

        MainWP_Helper::write( $rslt );
    }

    /**
     * Build New Post.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::create_post()
     * @uses \MainWP\Child\MainWP_Helper::instance()->error()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function new_post() {
        // phpcs:disable WordPress.Security.NonceVerification
        $new_post            = isset( $_POST['new_post'] ) ? json_decode( base64_decode( wp_unslash( $_POST['new_post'] ) ), true ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        $post_custom         = isset( $_POST['post_custom'] ) ? json_decode( base64_decode( wp_unslash( $_POST['post_custom'] ) ), true ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        $post_category       = isset( $_POST['post_category'] ) ? rawurldecode( base64_decode( wp_unslash( $_POST['post_category'] ) ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        $post_tags           = isset( $new_post['post_tags'] ) ? rawurldecode( $new_post['post_tags'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $post_featured_image = isset( $_POST['post_featured_image'] ) && ! empty( $_POST['post_featured_image'] ) ? base64_decode( wp_unslash( $_POST['post_featured_image'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        $upload_dir          = isset( $_POST['mainwp_upload_dir'] ) ? json_decode( base64_decode( wp_unslash( $_POST['mainwp_upload_dir'] ) ), true ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

        $others = array();
        if ( isset( $_POST['featured_image_data'] ) ) {
            $others['featured_image_data'] = ! empty( $_POST['featured_image_data'] ) ? json_decode( base64_decode( wp_unslash( $_POST['featured_image_data'] ) ), true ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        }
        // phpcs:enable WordPress.Security.NonceVerification
        $result = $this->create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $others );

        if ( is_array( $result ) && isset( $result['error'] ) ) {
            MainWP_Helper::instance()->error( $result['error'] );
        }

        $created = $result['success'];
        if ( true !== $created ) {
            MainWP_Helper::instance()->error( 'Undefined error' );
        }

        do_action( 'mainwp_child_after_newpost', $result );

        $information['added']      = true;
        $information['added_id']   = $result['added_id'];
        $information['link']       = $result['link'];
        $information['other_data'] = array(
            'new_post_data' => is_array( $result ) && isset( $result['new_post_data'] ) ? $result['new_post_data'] : array(),
        );
        MainWP_Helper::write( $information );
    }

    /**
     * Post Action.
     *
     * @uses \MainWP\Child\MainWP_Child_Links_Checker()
     * @uses \MainWP\Child\MainWP_Child_Posts::get_post_edit()
     * @uses \MainWP\Child\MainWP_Child_Posts::get_page_edit()
     * @uses \MainWP\Child\MainWP_Helper::write()
     * @uses \MainWP\Child\MainWP_Child_Links_Checker::get_class_name()
     */
    public function post_action() { //phpcs:ignore -- NOSONAR - complex.
        // phpcs:disable WordPress.Security.NonceVerification
        $action  = MainWP_System::instance()->validate_params( 'action' );
        $postId  = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
        $my_post = array();

        $old_post_type_name = '';
        $post_current       = false;
        if ( $postId ) {
            $post_current = get_post( $postId );
            if ( $post_current ) {
                $old_post_type_name = strtolower( $this->get_post_type_name( $post_current->post_type ) );
            }
        }

        if ( 'publish' === $action ) {
            if ( empty( $post_current ) ) {
                $information['status'] = 'FAIL';
            } elseif ( 'future' === $post_current->post_status ) {
                    wp_publish_post( $postId );
                    wp_update_post(
                        array(
                            'ID'            => $postId,
                            'post_date'     => current_time( 'mysql', false ),
                            'post_date_gmt' => current_time( 'mysql', true ),
                        )
                    );
            } else {
                wp_update_post(
                    array(
                        'ID'          => $postId,
                        'post_status' => 'publish',
                    )
                );
            }
        } elseif ( 'update' === $action ) {
            $postData = isset( $_POST['post_data'] ) ? wp_unslash( $_POST['post_data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $my_post  = is_array( $postData ) ? $postData : array();
            wp_update_post( $my_post );
        } elseif ( 'unpublish' === $action ) {
            $my_post['ID']          = $postId;
            $my_post['post_status'] = 'draft';
            wp_update_post( $my_post );
        } elseif ( 'trash' === $action ) {
            add_action( 'trash_post', array( MainWP_Child_Links_Checker::get_class_name(), 'hook_post_deleted' ) );
            wp_trash_post( $postId );
        } elseif ( 'delete' === $action ) {
            add_action( 'delete_post', array( MainWP_Child_Links_Checker::get_class_name(), 'hook_post_deleted' ) );
            $delete_result = $this->delete_post_with_result( $postId );
            if ( ! $delete_result['deleted'] && ! $delete_result['already_absent'] ) {
                $information['status'] = 'FAIL';
            }
        } elseif ( 'restore' === $action ) {
            wp_untrash_post( $postId );
        } elseif ( 'update_meta' === $action ) {
            $values     = isset( $_POST['values'] ) ? json_decode( base64_decode( wp_unslash( $_POST['values'] ) ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
            $meta_key   = $values['meta_key'];
            $meta_value = $values['meta_value'];
            $check_prev = $values['check_prev'];

            foreach ( $meta_key as $i => $key ) {
                if ( 1 === intval( $check_prev[ $i ] ) ) {
                    update_post_meta( $postId, $key, get_post_meta( $postId, $key, true ) ? get_post_meta( $postId, $key, true ) : $meta_value[ $i ] );
                } else {
                    update_post_meta( $postId, $key, $meta_value[ $i ] );
                }
            }
        } elseif ( 'get_edit' === $action ) {
            $postId    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
            $post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
            if ( 'post' === $post_type ) {
                $my_post = $this->get_post_edit( $postId );
            } else {
                $my_post = $this->get_page_edit( $postId );
            }
        } else {
            $information['status'] = 'FAIL';
        }

        if ( ! isset( $information['status'] ) ) {
            $information['status'] = 'SUCCESS';
        }
        // phpcs:enable WordPress.Security.NonceVerification

        $post_dt = array();
        // support logging.
        if ( 'delete' === $action ) {
            if ( $post_current ) {
                $post_dt = array(
                    'post_type'     => $post_current->post_type,
                    'post_title'    => $post_current->post_title,
                    'singular_name' => $old_post_type_name,
                );
            }
        } else {
            $post_updated = get_post( $postId );
            if ( $post_updated ) {
                $post_type_name = strtolower( $this->get_post_type_name( $post_updated->post_type ) );
                $post_dt        = array(
                    'post_id'       => $postId,
                    'post_type'     => $post_updated->post_type,
                    'post_title'    => $post_updated->post_title,
                    'post_date'     => $post_updated->post_date,
                    'post_date_gmt' => $post_updated->post_date_gmt,
                    'new_status'    => $post_updated->post_status,
                    'old_status'    => $post_current->post_status,
                    'singular_name' => $post_type_name,
                );
            }
        }

        $information['other_data'] = array(
            'post_action_data' => $post_dt,
        );
        $information['my_post']    = $my_post;
        MainWP_Helper::write( $information );
    }

    /**
     * Permanently delete one exact post and prove the terminal result.
     *
     * A missing post is an idempotent terminal success. A present post is
     * successful only when WordPress reports a deleted post and a fresh read
     * proves absence. The closed result is intentionally not written directly
     * to the legacy wire response, whose established shape remains unchanged.
     *
     * @param mixed $post_id Candidate post identity.
     * @return array{deleted:bool,already_absent:bool}
     */
    public function delete_post_with_result( $post_id ) {
        if ( ! is_int( $post_id ) && ! ( is_string( $post_id ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $post_id ) ) ) {
            return array(
                'deleted'        => false,
                'already_absent' => false,
            );
        }

        $post_id = (int) $post_id;
        if ( 1 > $post_id ) {
            return array(
                'deleted'        => false,
                'already_absent' => false,
            );
        }

        if ( null === get_post( $post_id ) ) {
            return array(
                'deleted'        => false,
                'already_absent' => true,
            );
        }

        $deleted = wp_delete_post( $post_id, true );
        return array(
            'deleted'        => $deleted instanceof \WP_Post && null === get_post( $post_id ),
            'already_absent' => false,
        );
    }

    /**
     * Gets the singular post type label
     *
     * @param string $post_type_slug Post type slug.
     *
     * @return string Post type label
     */
    public function get_post_type_name( $post_type_slug ) {
        $name = esc_html__( 'Post', 'mainwp-child' ); // Default.

        if ( post_type_exists( $post_type_slug ) ) {
            $post_type = get_post_type_object( $post_type_slug );
            $name      = $post_type->labels->singular_name;
        }

        return $name;
    }

    /**
     * Get post edit data.
     *
     * @param string $id Post ID.
     *
     * @return array|bool Return $post_data or FALSE on failure.
     */
    private function get_post_edit( $id ) { //phpcs:ignore -- NOSONAR - complex.
        $post = get_post( $id );
        if ( $post ) {
            $categoryObjects = get_the_category( $post->ID );
            $categories      = '';
            foreach ( $categoryObjects as $cat ) {
                if ( '' !== $categories ) {
                    $categories .= ',';
                }
                $categories .= $cat->name;
            }
            $post_category = $categories;

            $tagObjects = get_the_tags( $post->ID );
            $tags       = '';
            if ( is_array( $tagObjects ) ) {
                foreach ( $tagObjects as $tag ) {
                    if ( '' !== $tags ) {
                        $tags .= ',';
                    }
                    $tags .= $tag->name;
                }
            }
            $post_tags = $tags;

            $post_custom = get_post_custom( $id );

            $galleries           = get_post_gallery( $id, false );
            $post_gallery_images = array();

            if ( is_array( $galleries ) && isset( $galleries['ids'] ) ) {
                $attached_images = explode( ',', $galleries['ids'] );
                foreach ( $attached_images as $attachment_id ) {
                    $attachment = get_post( $attachment_id );
                    if ( $attachment ) {
                        $post_gallery_images[] = array(
                            'id'          => $attachment_id,
                            'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
                            'caption'     => $attachment->post_excerpt,
                            'description' => $attachment->post_content,
                            'src'         => $attachment->guid,
                            'title'       => $attachment->post_title,
                        );
                    }
                }
            }

            include_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php'; // NOSONAR -- WP compatible.
            $post_featured_image = get_post_thumbnail_id( $id );
            $child_upload_dir    = wp_upload_dir();
            $new_post            = array(
                'edit_id'        => $id,
                'is_sticky'      => is_sticky( $id ) ? 1 : 0,
                'post_title'     => $post->post_title,
                'post_content'   => $post->post_content,
                'post_status'    => $post->post_status,
                'post_date'      => $post->post_date,
                'post_date_gmt'  => $post->post_date_gmt,
                'post_tags'      => $post_tags,
                'post_name'      => $post->post_name,
                'post_excerpt'   => $post->post_excerpt,
                'comment_status' => $post->comment_status,
                'ping_status'    => $post->ping_status,
                'post_type'      => $post->post_type,
                'post_password'  => $post->post_password,
            );

            if ( ! empty( $post_featured_image ) ) { // Featured image is set, retrieve URL.
                $img                 = wp_get_attachment_image_src( $post_featured_image, 'full' );
                $post_featured_image = $img[0];
            }

            require_once ABSPATH . 'wp-admin/includes/post.php'; // NOSONAR - WP compatible.
            wp_set_post_lock( $id );

            // prepare $post_custom values.
            $new_post_custom = array();
            foreach ( $post_custom as $meta_key => $meta_values ) {
                $new_meta_values = array();
                foreach ( $meta_values as $key_value => $meta_value ) {
                    if ( is_serialized( $meta_value ) ) {
                        $meta_value = unserialize( $meta_value ); // phpcs:ignore --  safe internal value.
                    }
                    $new_meta_values[ $key_value ] = $meta_value;
                }
                $new_post_custom[ $meta_key ] = $new_meta_values;
            }

            return array(
                'new_post'            => base64_encode( wp_json_encode( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'post_custom'         => base64_encode( wp_json_encode( $new_post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'post_category'       => base64_encode( $post_category ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'post_featured_image' => base64_encode( $post_featured_image ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'post_gallery_images' => base64_encode( wp_json_encode( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'child_upload_dir'    => base64_encode( wp_json_encode( $child_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
            );
        }
        return false;
    }

    /**
     * Get page edit data.
     *
     * @param string $id Page ID.
     *
     * @return array|bool Return $post_data or FALSE on failure.
     */
    private function get_page_edit( $id ) { //phpcs:ignore -- NOSONAR - complex.
        $post = get_post( $id );
        if ( $post ) {
            $post_custom = get_post_custom( $id );
            include_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php'; // NOSONAR -- WP compatible.
            $post_featured_image = get_post_thumbnail_id( $id );
            $child_upload_dir    = wp_upload_dir();

            $new_post = array(
                'edit_id'        => $id,
                'post_title'     => $post->post_title,
                'post_content'   => $post->post_content,
                'post_status'    => $post->post_status,
                'post_date'      => $post->post_date,
                'post_date_gmt'  => $post->post_date_gmt,
                'post_type'      => 'page',
                'post_name'      => $post->post_name,
                'post_excerpt'   => $post->post_excerpt,
                'comment_status' => $post->comment_status,
                'ping_status'    => $post->ping_status,
                'post_password'  => $post->post_password,
            );

            if ( ! empty( $post_featured_image ) ) {
                    $img                 = wp_get_attachment_image_src( $post_featured_image, 'full' );
                    $post_featured_image = $img[0];
            }

            $galleries           = get_post_gallery( $id, false );
            $post_gallery_images = array();

            if ( is_array( $galleries ) && isset( $galleries['ids'] ) ) {
                $attached_images = explode( ',', $galleries['ids'] );
                foreach ( $attached_images as $attachment_id ) {
                    $attachment = get_post( $attachment_id );
                    if ( $attachment ) {
                        $post_gallery_images[] = array(
                            'id'          => $attachment_id,
                            'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
                            'caption'     => $attachment->post_excerpt,
                            'description' => $attachment->post_content,
                            'src'         => $attachment->guid,
                            'title'       => $attachment->post_title,
                        );
                    }
                }
            }

            require_once ABSPATH . 'wp-admin/includes/post.php'; // NOSONAR - WP compatible.
            wp_set_post_lock( $id );

            // prepare $post_custom values.
            $new_post_custom = array();
            foreach ( $post_custom as $meta_key => $meta_values ) {
                $new_meta_values = array();
                foreach ( $meta_values as $key_value => $meta_value ) {
                    if ( is_serialized( $meta_value ) ) {
                        $meta_value = unserialize( $meta_value ); // phpcs:ignore -- safe internal value.
                    }
                    $new_meta_values[ $key_value ] = $meta_value;
                }
                $new_post_custom[ $meta_key ] = $new_meta_values;
            }

            return array(
                'new_post'            => base64_encode( wp_json_encode( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'post_custom'         => base64_encode( wp_json_encode( $new_post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'post_featured_image' => base64_encode( $post_featured_image ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'post_gallery_images' => base64_encode( wp_json_encode( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                'child_upload_dir'    => base64_encode( wp_json_encode( $child_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
            );
        }
        return false;
    }

    /**
     * Create new post.
     *
     * @param array  $new_post            Post data array.
     * @param array  $post_custom         Post custom meta data.
     * @param string $post_category       Post categories.
     * @param string $post_featured_image Post featured image.
     * @param string $upload_dir          Upload directory.
     * @param string $post_tags           Post tags.
     * @param array  $others              Other data.
     *
     * @return array|string[] $ret Return success array, permalink & Post ID.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::set_post_custom_data()
     * @uses \MainWP\Child\MainWP_Child_Posts::update_found_images()
     * @uses \MainWP\Child\MainWP_Child_Posts::create_has_shortcode_gallery()
     * @uses \MainWP\Child\MainWP_Child_Posts::create_post_plus()
     * @uses \MainWP\Child\MainWP_Child_Posts::update_post_data()
     */
    private function create_post( //phpcs:ignore -- NOSONAR - complex.
        $new_post,
        $post_custom,
        $post_category,
        $post_featured_image,
        $upload_dir,
        $post_tags,
        $others = array()
    ) {

        /**
        * Hook: `mainwp_before_post_update`
        *
        * Runs before creating or updating a post via MainWP dashboard.
        *
        * @param array  $new_post      � Post data array.
        * @param array  $post_custom   � Post custom meta data.
        * @param string $post_category � Post categories.
        * @param string $post_tags     � Post tags.
        */
        do_action( 'mainwp_before_post_update', $new_post, $post_custom, $post_category, $post_tags, $others );

        $edit_post_id = 0;
        $is_post_plus = false;

        $this->set_post_custom_data( $new_post, $post_custom, $post_tags, $edit_post_id, $is_post_plus, $others );

        require_once ABSPATH . 'wp-admin/includes/post.php'; // NOSONAR - WP compatible.

        if ( $edit_post_id ) {
            $user_id = wp_check_post_lock( $edit_post_id );
            if ( $user_id ) {
                $user = get_userdata( $user_id );
                // translators: %s: User display name.
                $error = sprintf( esc_html__( 'This content is currently locked. %s is currently editing.', 'mainwp-child' ), $user->display_name );
                return array( 'error' => $error );
            }
        }

        // if editing post then will check if image existed.
        $check_image_existed = $edit_post_id ? true : false;

        $this->update_found_images( $new_post, $upload_dir, $check_image_existed );
        $this->create_has_shortcode_gallery( $new_post );

        if ( $is_post_plus ) {
            $this->create_post_plus( $new_post, $post_custom );
        }

        if ( isset( $post_custom['_mainwp_replace_advance_img'] ) && ! empty( $post_custom['_mainwp_replace_advance_img'][0] ) ) {
            $new_post['post_content'] = static::replace_advanced_image( $new_post['post_content'], $upload_dir );
            $new_post['post_content'] = static::replace_advanced_image( $new_post['post_content'], $upload_dir, true ); // to fix images url with slashes.
            unset( $post_custom['_mainwp_replace_advance_img'] );
        }

        // Save the post to the WP.
        remove_filter( 'content_save_pre', 'wp_filter_post_kses' );  // to fix brake scripts or html.
        $post_status             = $new_post['post_status']; // save post_status.
        $new_post['post_status'] = 'auto-draft'; // to fix reports, to log as created post.

        $current_post = false;
        // Update post.
        if ( $edit_post_id ) {
            // check if post existed.
            $current_post = get_post( $edit_post_id );
            if ( $current_post && ( ( ! isset( $new_post['post_type'] ) && 'post' === $current_post->post_type ) || ( isset( $new_post['post_type'] ) && $new_post['post_type'] === $current_post->post_type ) ) ) {
                $new_post['ID'] = $edit_post_id;
            }
            $new_post['post_status'] = $post_status; // child reports: to logging as update post.
        }
        $wp_error = false;
        if ( $edit_post_id ) {
            $new_post_id = wp_update_post( $new_post, $wp_error ); // to fix: update post.
        } else {
            $new_post_id = wp_insert_post( $new_post, $wp_error ); // insert post.
        }
        // Show errors if something went wrong.
        if ( is_wp_error( $wp_error ) ) {
            return $wp_error->get_error_message();
        }
        if ( empty( $new_post_id ) ) {
            return array( 'error' => 'Empty post id' );
        }

        if ( ! $edit_post_id ) {
            wp_update_post(
                array(
                    'ID'          => $new_post_id,
                    'post_status' => $post_status,
                )
            );
        }

        if ( is_array( $post_custom ) && isset( $post_custom['_mainwp_edit_post_save_to_post_type'] ) ) {
            $saved_post_type = $post_custom['_mainwp_edit_post_save_to_post_type'];
            $saved_post_type = is_array( $saved_post_type ) ? current( $saved_post_type ) : $saved_post_type;
            if ( ! empty( $saved_post_type ) ) {
                wp_update_post(
                    array(
                        'ID'        => $new_post_id,
                        'post_type' => $saved_post_type,
                    )
                );
            }
            unset( $post_custom['_mainwp_edit_post_save_to_post_type'] );
        }

        $this->update_post_data( $new_post_id, $post_custom, $post_category, $post_featured_image, $check_image_existed, $is_post_plus, $others );

        // unlock if edit post.
        if ( $edit_post_id ) {
            update_post_meta( $edit_post_id, '_edit_lock', '' );
        }

        $post_added = get_post( $new_post_id );
        $post_dt    = array();
        if ( $post_added ) {
            $post_type_name = strtolower( $this->get_post_type_name( $post_added->post_type ) );
            $post_dt        = array(
                'post_id'       => $new_post_id,
                'post_type'     => $post_added->post_type,
                'post_title'    => $post_added->post_title,
                'post_date'     => $post_added->post_date,
                'post_date_gmt' => $post_added->post_date_gmt,
                'new_status'    => $post_added->post_status,
                'old_status'    => $current_post ? $current_post->post_status : '',
                'singular_name' => $post_type_name,
                'is_editing'    => $edit_post_id ? 1 : 0,
            );
        }

        $result                  = array();
        $result['success']       = true;
        $result['link']          = get_permalink( $new_post_id );
        $result['added_id']      = $new_post_id;
        $result['new_post_data'] = $post_dt;
        return $result;
    }

    /**
     * Set custom post data.
     *
     * @param array  $new_post            Post data array.
     * @param array  $post_custom         Post custom meta data.
     * @param string $post_tags           Post tags.
     * @param string $edit_post_id        Edit Post ID.
     * @param bool   $is_post_plus        TRUE|FALSE, Whether or not this came from MainWP Post Plus Extension.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::update_wp_rocket_custom_post()
     */
    private function set_post_custom_data( &$new_post, $post_custom, $post_tags, &$edit_post_id, &$is_post_plus ) { //phpcs:ignore -- NOSONAR - complex.

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        $this->update_wp_rocket_custom_post( $post_custom );

        // current user may be connected admin or alternative admin.
        $current_uid = $current_user->ID;

        // Set up a new post (adding additional information).
        $new_post['post_author'] = isset( $new_post['post_author'] ) && ! empty( $new_post['post_author'] ) ? $new_post['post_author'] : $current_uid;

        if ( isset( $new_post['post_title'] ) ) {
            $new_post['post_title'] = MainWP_Utility::esc_content( $new_post['post_title'], 'mixed' );
        }

        if ( isset( $new_post['post_excerpt'] ) ) {
            $new_post['post_excerpt'] = MainWP_Utility::esc_content( $new_post['post_excerpt'], 'mixed' );
        }

        if ( isset( $new_post['custom_post_author'] ) && ! empty( $new_post['custom_post_author'] ) ) {
            $_author = get_user_by( 'login', $new_post['custom_post_author'] );
            if ( ! empty( $_author ) ) {
                $new_post['post_author'] = $_author->ID;
            }
            unset( $new_post['custom_post_author'] );
        }

        // post plus extension process.
        $is_post_plus = isset( $post_custom['_mainwp_post_plus'] ) ? true : false;

        if ( $is_post_plus && isset( $new_post['post_date_gmt'] ) && ! empty( $new_post['post_date_gmt'] ) && '0000-00-00 00:00:00' !== $new_post['post_date_gmt'] ) {
            $post_date_timestamp   = strtotime( $new_post['post_date_gmt'] ) + get_option( 'gmt_offset' ) * 60 * 60;
            $new_post['post_date'] = date( 'Y-m-d H:i:s', $post_date_timestamp ); // phpcs:ignore -- local time.
        }

        if ( isset( $post_tags ) && '' !== $post_tags ) {
            $new_post['tags_input'] = $post_tags;
        }

        if ( isset( $post_custom['_mainwp_edit_post_id'] ) && $post_custom['_mainwp_edit_post_id'] ) {
            $edit_post_id = current( $post_custom['_mainwp_edit_post_id'] );
        } elseif ( isset( $new_post['ID'] ) && $new_post['ID'] ) {
            $edit_post_id = $new_post['ID'];
        }
    }

    /**
     * Update post data.
     *
     * @param string $new_post_id         New post ID.
     * @param array  $post_custom         Post custom meta data.
     * @param string $post_category       Post categories.
     * @param string $post_featured_image Post featured image.
     * @param bool   $check_image_existed TRUE|FALSE, Whether or not featured image already exists.
     * @param bool   $is_post_plus        TRUE|FALSE, Whether or not this came from MainWP Post Plus Extension.
     * @param array  $others        Others data.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::set_custom_post_fields()
     * @uses \MainWP\Child\MainWP_Child_Posts::update_seo_meta()
     * @uses \MainWP\Child\MainWP_Child_Posts::create_set_categories()
     * @uses \MainWP\Child\MainWP_Child_Posts::create_featured_image()
     * @uses \MainWP\Child\MainWP_Child_Posts::post_plus_update_author()
     * @uses \MainWP\Child\MainWP_Child_Posts::post_plus_update_categories()
     */
    private function update_post_data( $new_post_id, $post_custom, $post_category, $post_featured_image, $check_image_existed, $is_post_plus, $others ) {
        unset( $check_image_existed );
        $seo_ext_activated = false;
        if ( class_exists( '\WPSEO_Meta' ) && class_exists( '\WPSEO_Admin' ) ) {
            $seo_ext_activated = true;
        }

        $post_to_only_existing_categories = false;

        $this->set_custom_post_fields( $new_post_id, $post_custom, $seo_ext_activated, $post_to_only_existing_categories );

        // yoast seo plugin activated.
        if ( $seo_ext_activated ) {
            $this->update_seo_meta( $new_post_id, $post_custom );
        }

        $this->create_set_categories( $new_post_id, $post_category, $post_to_only_existing_categories );
        $this->create_featured_image( $new_post_id, $post_featured_image, true, $others ); // always checks featured img.

        // post plus extension process.
        if ( $is_post_plus ) {
            $this->post_plus_update_author( $new_post_id, $post_custom );
            $this->post_plus_update_categories( $new_post_id, $post_custom );
        }

        // to support custom post author.
        $custom_post_author = apply_filters( 'mainwp_create_post_custom_author', false, $new_post_id );
        if ( ! empty( $custom_post_author ) ) {
            wp_update_post(
                array(
                    'ID'          => $new_post_id,
                    'post_author' => $custom_post_author,
                )
            );
        }
    }

    /**
     * Update WPRocket custom post.
     *
     * @param array $post_custom Post custom meta data.
     *
     * @uses \get_rocket_option()
     * @see https://github.com/wp-media/wp-rocket/blob/master/inc/functions/options.php
     *
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::instance()::is_activated()
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::is_activated()
     */
    private function update_wp_rocket_custom_post( &$post_custom ) { //phpcs:ignore -- NOSONAR - complex.
        // Options fields.
        $wprocket_fields = array(
            'lazyload',
            'lazyload_iframes',
            'minify_html',
            'minify_css',
            'minify_js',
            'cdn',
            'async_css',
            'defer_all_js',
        );

        $wprocket_activated = false;
        if ( MainWP_Child_WP_Rocket::instance()->is_activated() && function_exists( '\get_rocket_option' ) ) {
            $wprocket_activated = true;
            foreach ( $wprocket_fields as $field ) {
                if ( ! isset( $post_custom[ '_rocket_exclude_' . $field ] ) && ! \get_rocket_option( $field ) ) {
                    $post_custom[ '_rocket_exclude_' . $field ] = array( true );
                }
            }
        }
        if ( ! $wprocket_activated ) {
            foreach ( $wprocket_fields as $field ) {
                if ( isset( $post_custom[ '_rocket_exclude_' . $field ] ) ) {
                    unset( $post_custom[ '_rocket_exclude_' . $field ] );
                }
            }
        }
    }

    /**
     * Search for all the images added to the new post.
     *
     * @param array  $new_post            Post data array.
     * @param string $upload_dir          Upload directory.
     * @param bool   $check_image_existed TRUE|FALSE, Whether or not featured image already exists.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()
     * @uses \MainWP\Child\MainWP_Helper::log_debug()
     */
    private function update_found_images( &$new_post, $upload_dir, $check_image_existed ) { //phpcs:ignore -- NOSONAR - complex.

        // Some images have a href tag to click to navigate to the image.. we need to replace this too.
        $foundMatches = preg_match_all( '/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg|avif))\")/ix', $new_post['post_content'], $matches, PREG_SET_ORDER );

        if ( $foundMatches > 0 ) {
            // We found images, now to download them so we can start balbal.
            foreach ( $matches as $match ) {
                $hrefLink = $match[2];
                $imgUrl   = $match[4];

                if ( ! isset( $upload_dir['baseurl'] ) || ( false === strripos( $imgUrl, $upload_dir['baseurl'] ) ) ) { // url of image is not in dashboard site.
                    continue;
                }

                if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
                    $search         = $imgMatches[0];
                    $replace        = '.' . $match[6];
                    $originalImgUrl = str_replace( $search, $replace, $imgUrl );
                } else {
                    $originalImgUrl = $imgUrl;
                }

                try {
                    $downloadfile      = MainWP_Utility::upload_image( $originalImgUrl, array(), $check_image_existed );
                    $localUrl          = $downloadfile['url'];
                    $linkToReplaceWith = dirname( $localUrl );
                    if ( '' !== $hrefLink ) {
                        $server     = MainWP_Child_Keys_Manager::get_encrypted_option( 'mainwp_child_server' );
                        $serverHost = wp_parse_url( $server, PHP_URL_HOST );
                        if ( ! empty( $serverHost ) && strpos( $hrefLink, $serverHost ) !== false ) {
                            $serverHref               = 'href="' . $serverHost;
                            $replaceServerHref        = 'href="' . wp_parse_url( $localUrl, PHP_URL_SCHEME ) . '://' . wp_parse_url( $localUrl, PHP_URL_HOST );
                            $new_post['post_content'] = str_replace( $serverHref, $replaceServerHref, $new_post['post_content'] );
                        }
                    }
                    $lnkToReplace = dirname( $imgUrl );
                    if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
                        $new_post['post_content'] = str_replace( $imgUrl, $localUrl, $new_post['post_content'] ); // replace src image.
                        $new_post['post_content'] = str_replace( $lnkToReplace, $linkToReplaceWith, $new_post['post_content'] );
                    }
                } catch ( MainWP_Exception $e ) {
                    MainWP_Helper::log_debug( $e->getMessage() );
                }
            }
        }
    }

    /**
     * Method replace_advanced_image()
     *
     * Handle upload advanced image.
     *
     * @param array $content post content data.
     * @param array $upload_dir upload directory info.
     * @param bool  $withslashes to use preg pattern with slashes.
     *
     * @return mixed array of result.
     */
    public static function replace_advanced_image( $content, $upload_dir, $withslashes = false ) { //phpcs:ignore -- NOSONAR - complex.

        if ( empty( $upload_dir ) || ! isset( $upload_dir['baseurl'] ) ) {
            return $content;
        }

        $dashboard_url = MainWP_Child_Keys_Manager::get_encrypted_option( 'mainwp_child_server' );

        $site_url_destination = get_site_url();

        // to fix url with slashes.
        if ( $withslashes ) {
            $site_url_destination = str_replace( '/', '\/', $site_url_destination );
            $dashboard_url        = str_replace( '/', '\/', $dashboard_url );
        }

        $foundMatches = preg_match_all( '#(' . preg_quote( $site_url_destination, null ) . ')[^\.]*(\.(png|gif|jpg|jpeg|avif))#ix', $content, $matches, PREG_SET_ORDER );

        if ( 0 < $foundMatches ) {

            $matches_checked = array();
            $check_double    = array();
            foreach ( $matches as $match ) {
                // to avoid double images.
                if ( ! in_array( $match[0], $check_double ) ) {
                    $check_double[]    = $match[0];
                    $matches_checked[] = $match;
                }
            }
            foreach ( $matches_checked as $match ) {

                $imgUrl = $match[0];
                if ( false === strripos( wp_unslash( $imgUrl ), $upload_dir['baseurl'] ) ) {
                    continue;
                }

                if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
                    $search         = $imgMatches[0];
                    $replace        = '.' . $match[3];
                    $originalImgUrl = str_replace( $search, $replace, $imgUrl );
                } else {
                    $originalImgUrl = $imgUrl;
                }

                try {
                    $downloadfile      = MainWP_Utility::upload_image( wp_unslash( $originalImgUrl ), array(), true );
                    $localUrl          = $downloadfile['url'];
                    $linkToReplaceWith = dirname( $localUrl );
                    $lnkToReplace      = dirname( $imgUrl );
                    if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
                        $content = str_replace( $imgUrl, $localUrl, $content ); // replace src image.
                        $content = str_replace( $lnkToReplace, $linkToReplaceWith, $content );
                    }
                } catch ( MainWP_Exception $e ) {
                    // ok.
                }
            }
            if ( false === strripos( $dashboard_url, $site_url_destination ) ) {
                // replace other images src outside dashboard upload folder.
                $content = str_replace( $dashboard_url, $site_url_destination, $content );
            }
        }
        return $content;
    }

    /**
     * Create shortcode image gallery.
     *
     * @param array $new_post Post data array.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()
     * @uses MainWP_Exception()
     */
    private function create_has_shortcode_gallery( &$new_post ) { //phpcs:ignore -- NOSONAR - complex.

        if ( has_shortcode( $new_post['post_content'], 'gallery' ) && preg_match_all( '/\[gallery[^\]]+ids=\"(.*?)\"[^\]]*\]/ix', $new_post['post_content'], $matches, PREG_SET_ORDER ) ) {
            $replaceAttachedIds = array();
            // phpcs:disable WordPress.Security.NonceVerification
            if ( isset( $_POST['post_gallery_images'] ) ) {
                $post_gallery_images = isset( $_POST['post_gallery_images'] ) ? json_decode( base64_decode( wp_unslash( $_POST['post_gallery_images'] ) ), true ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                if ( is_array( $post_gallery_images ) ) {
                    foreach ( $post_gallery_images as $gallery ) {
                        if ( isset( $gallery['src'] ) ) {
                            try {
                                $upload = MainWP_Utility::upload_image( $gallery['src'], $gallery ); // Upload image to WP.
                                if ( null !== $upload ) {
                                    $replaceAttachedIds[ $gallery['id'] ] = $upload['id'];
                                }
                            } catch ( MainWP_Exception $e ) {
                                // ok!
                            }
                        }
                    }
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification
            if ( ! empty( $replaceAttachedIds ) ) {
                foreach ( $matches as $match ) {
                    $idsToReplace     = $match[1];
                    $idsToReplaceWith = '';
                    $originalIds      = explode( ',', $idsToReplace );
                    foreach ( $originalIds as $attached_id ) {
                        if ( ! empty( $originalIds ) && isset( $replaceAttachedIds[ $attached_id ] ) ) {
                            $idsToReplaceWith .= $replaceAttachedIds[ $attached_id ] . ',';
                        }
                    }
                    $idsToReplaceWith = rtrim( $idsToReplaceWith, ',' );
                    if ( ! empty( $idsToReplaceWith ) ) {
                        $new_post['post_content'] = str_replace( '"' . $idsToReplace . '"', '"' . $idsToReplaceWith . '"', $new_post['post_content'] );
                    }
                }
            }
        }
    }

    /**
     * Create post plus post.
     *
     * @param array $new_post    Post data array.
     * @param array $post_custom Post custom meta data.
     */
    private function create_post_plus( &$new_post, $post_custom ) { //phpcs:ignore -- NOSONAR - complex.
        $random_publish_date = isset( $post_custom['_saved_draft_random_publish_date'] ) ? $post_custom['_saved_draft_random_publish_date'] : false;
        $random_publish_date = is_array( $random_publish_date ) ? current( $random_publish_date ) : null;

        if ( ! empty( $random_publish_date ) ) {
            $random_date_from = isset( $post_custom['_saved_draft_publish_date_from'] ) ? $post_custom['_saved_draft_publish_date_from'] : 0;
            $random_date_from = is_array( $random_date_from ) ? current( $random_date_from ) : 0;

            $random_date_to = isset( $post_custom['_saved_draft_publish_date_to'] ) ? $post_custom['_saved_draft_publish_date_to'] : 0;
            $random_date_to = is_array( $random_date_to ) ? current( $random_date_to ) : 0;

            $now = time();

            if ( empty( $random_date_from ) ) {
                $random_date_from = $now;
            }

            if ( empty( $random_date_to ) ) {
                $random_date_to = $now;
            }

            if ( $random_date_from === $now && $random_date_from === $random_date_to ) {
                $random_date_to = $now + 7 * 24 * 3600;
            }

            if ( $random_date_from > $random_date_to ) {
                $tmp              = $random_date_from;
                $random_date_from = $random_date_to;
                $random_date_to   = $tmp;
            }

            $random_timestamp      = wp_rand( $random_date_from, $random_date_to );
            $new_post['post_date'] = date( 'Y-m-d H:i:s', $random_timestamp ); // phpcs:ignore -- local time.
        }
    }

    /**
     * Update post plus author.
     *
     * @param string $new_post_id New post ID.
     * @param array  $post_custom Post custom meta data.
     */
    private function post_plus_update_author( $new_post_id, $post_custom ) {
        $random_privelege      = isset( $post_custom['_saved_draft_random_privelege'] ) ? $post_custom['_saved_draft_random_privelege'] : null;
        $random_privelege      = is_array( $random_privelege ) ? current( $random_privelege ) : null;
        $random_privelege_base = base64_decode( $random_privelege ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        $random_privelege      = json_decode( $random_privelege_base, true );

        if ( is_array( $random_privelege ) && count( $random_privelege ) > 0 ) {
            $random_post_authors = array();
            foreach ( $random_privelege as $role ) {
                $users = get_users( array( 'role' => $role ) );
                foreach ( $users as $user ) {
                    $random_post_authors[] = $user->ID;
                }
            }
            if ( ! empty( $random_post_authors ) ) {
                shuffle( $random_post_authors );
                $key = array_rand( $random_post_authors );
                wp_update_post(
                    array(
                        'ID'          => $new_post_id,
                        'post_author' => $random_post_authors[ $key ],
                    )
                );
            }
        }
    }

    /**
     * Update post plus categories.
     *
     * @param string $new_post_id New post ID.
     * @param array  $post_custom Post custom meta data.
     */
    private function post_plus_update_categories( $new_post_id, $post_custom ) {
        $random_category = isset( $post_custom['_saved_draft_random_category'] ) ? $post_custom['_saved_draft_random_category'] : false;
        $random_category = is_array( $random_category ) ? current( $random_category ) : null;
        if ( ! empty( $random_category ) ) {
            $cats        = get_categories(
                array(
                    'type'       => 'post',
                    'hide_empty' => 0,
                )
            );
            $random_cats = array();
            if ( is_array( $cats ) ) {
                foreach ( $cats as $cat ) {
                    $random_cats[] = $cat->term_id;
                }
            }
            if ( ! empty( $random_cats ) ) {
                shuffle( $random_cats );
                $key = array_rand( $random_cats );
                wp_set_post_categories( $new_post_id, array( $random_cats[ $key ] ), false );
            }
        }
    }

    /**
     * Create new and set categories.
     *
     * @param string $new_post_id   New post ID.
     * @param string $post_category Post category.
     * @param bool   $post_to_only  TRUE|FALSE, Whether or not to post only to this category.
     *
     * @uses wp_create_categories() Create categories for the given post.
     * @see https://developer.wordpress.org/reference/functions/wp_create_categories/
     *
     * @uses wp_set_post_categories() Set categories for a post.
     * @see https://developer.wordpress.org/reference/functions/wp_set_post_categories/
     */
    private function create_set_categories( $new_post_id, $post_category, $post_to_only ) { //phpcs:ignore -- NOSONAR - complex.

        // If categories exist, create them (second parameter of wp_create_categories adds the categories to the post).
        include_once ABSPATH . 'wp-admin/includes/taxonomy.php'; // // NOSONAR -- WP compatible. Contains wp_create_categories.
        if ( isset( $post_category ) && '' !== $post_category ) {
            $categories = explode( ',', $post_category );
            if ( count( $categories ) > 0 ) {
                if ( ! $post_to_only ) {
                    $post_category = wp_create_categories( $categories, $new_post_id );
                } else {
                    $cat_ids = array();
                    foreach ( $categories as $cat ) {
                        $id = category_exists( $cat );
                        if ( $id ) {
                            $cat_ids[] = $id;
                        }
                    }
                    if ( ! empty( $cat_ids ) ) {
                        wp_set_post_categories( $new_post_id, $cat_ids );
                    }
                }
            }
        }
    }

    /**
     * Set custom post fields.
     *
     * @param string $new_post_id       New post ID.
     * @param array  $post_custom       Post custom meta data.
     * @param bool   $seo_ext_activated TRUE|FALSE, Whether or not Yoast SEO is activateed or not.
     * @param bool   $post_to_only      TRUE|FALSE, Whether or not to post only to this category.
     */
    private function set_custom_post_fields( $new_post_id, $post_custom, $seo_ext_activated, &$post_to_only ) { //phpcs:ignore -- NOSONAR - complex.

        // Set custom fields.
        $not_allowed = array(
            '_slug',
            '_tags',
            '_edit_lock',
            '_selected_sites',
            '_selected_groups',
            '_selected_by',
            '_categories',
            '_edit_last',
            '_sticky',
            '_mainwp_post_dripper',
            '_bulkpost_do_not_del',
            '_mainwp_spin_me',
            '_mainwp_boilerplate_sites_posts',
            '_mainwp_boilerplate',
            '_mainwp_post_plus',
            '_saved_as_draft',
            '_saved_draft_categories',
            '_saved_draft_tags',
            '_saved_draft_random_privelege',
            '_saved_draft_random_category',
            '_saved_draft_random_publish_date',
            '_saved_draft_publish_date_from',
            '_saved_draft_publish_date_to',
            '_post_to_only_existing_categories',
            '_mainwp_edit_post_site_id',
            '_mainwp_edit_post_id',
            '_edit_post_status',
            '_mainwp_edit_post_type',
            '_mainwp_edit_post_status',
            '_mainwp_edit_post_save_to_post_type',
            '_mainwp_post_dripper_sites_number',
            '_mainwp_post_dripper_time_number',
            '_mainwp_post_dripper_select_time',
            '_mainwp_post_dripper_use_post_dripper',
            'mainwp_post_id',
            '_mainwp_post_dripper_selected_drip_sites',
            '_mainwp_post_dripper_total_drip_sites',
            '_mainwp_replace_advance_img',
        );

        if ( $seo_ext_activated ) {
            // update those custom fields later.
            $not_allowed[] = \WPSEO_Meta::$meta_prefix . 'opengraph-image-id';
            $not_allowed[] = \WPSEO_Meta::$meta_prefix . 'opengraph-image';
        }

        if ( is_array( $post_custom ) ) {
            foreach ( $post_custom as $meta_key => $meta_values ) {
                if ( ! in_array( $meta_key, $not_allowed ) ) {
                    foreach ( $meta_values as $meta_value ) {
                        if ( 0 === strpos( $meta_key, '_mainwp_spinner_' ) ) {
                            continue;
                        }

                        if ( ! $seo_ext_activated ) {
                            // if WordPress SEO plugin is not activated do not save yoast post meta.
                            if ( false === strpos( $meta_key, '_yoast_wpseo_' ) ) {
                                update_post_meta( $new_post_id, $meta_key, $meta_value );
                            }
                        } else {
                            update_post_meta( $new_post_id, $meta_key, $meta_value );
                        }
                    }
                } elseif ( '_sticky' === $meta_key ) {
                    foreach ( $meta_values as $meta_value ) {
                        if ( 'sticky' === base64_decode( $meta_value ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                            stick_post( $new_post_id );
                        }
                    }
                } elseif ( '_post_to_only_existing_categories' === $meta_key ) {
                    if ( isset( $meta_values[0] ) && $meta_values[0] ) {
                        $post_to_only = true;
                    }
                }
            }
        }
    }

    /**
     * Update Yoast SEO Extension meta.
     *
     * @param string $new_post_id     New post ID.
     * @param array  $post_custom      Post custom meta data.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()
     * @uses \WPSEO_Meta::$meta_prefix()
     * @uses MainWP_Exception()
     */
    private function update_seo_meta( $new_post_id, $post_custom ) {

        $_seo_opengraph_image = isset( $post_custom[ \WPSEO_Meta::$meta_prefix . 'opengraph-image' ] ) ? $post_custom[ \WPSEO_Meta::$meta_prefix . 'opengraph-image' ] : array();
        $_seo_opengraph_image = current( $_seo_opengraph_image );
        $_server_domain       = '';
        $_server              = MainWP_Child_Keys_Manager::get_encrypted_option( 'mainwp_child_server' );
        if ( preg_match( '/(https?:\/\/[^\/]+\/).+/', $_server, $matchs ) ) {
            $_server_domain = isset( $matchs[1] ) ? $matchs[1] : '';
        }

        // upload image if it on the server.
        if ( ! empty( $_seo_opengraph_image ) && false !== strpos( $_seo_opengraph_image, $_server_domain ) ) {
            try {
                $upload = MainWP_Utility::upload_image( $_seo_opengraph_image ); // Upload image to WP.
                if ( null !== $upload ) {
                    update_post_meta( $new_post_id, \WPSEO_Meta::$meta_prefix . 'opengraph-image', $upload['url'] ); // Add the image to the post!
                    update_post_meta( $new_post_id, \WPSEO_Meta::$meta_prefix . 'opengraph-image-id', $upload['id'] ); // Add the id image to the post!
                }
            } catch ( MainWP_Exception $e ) {
                // ok!
            }
        }
    }

    /**
     * Create featured image.
     *
     * @param string $new_post_id         New post ID.
     * @param string $post_featured_image Post featured image.
     * @param bool   $check_image_existed TRUE|FALSE, Whether or not featured image already exists.
     * @param array  $others        Post custom others meta data.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()
     * @uses \Excepsion()
     */
    private function create_featured_image( $new_post_id, $post_featured_image, $check_image_existed, $others ) {

        $featured_image_exist = false;
        // If featured image exists - set it.
        if ( null !== $post_featured_image ) {
            try {
                $upload = MainWP_Utility::upload_image( $post_featured_image, array(), $check_image_existed, $new_post_id ); // Upload image to WP.
                if ( null !== $upload ) {
                    update_post_meta( $new_post_id, '_thumbnail_id', $upload['id'] ); // Add the thumbnail to the post!
                    $featured_image_exist = true;
                    if ( isset( $others['featured_image_data'] ) && ! empty( $others['featured_image_data'] ) ) {
                        $_image_data = $others['featured_image_data'];
                        update_post_meta( $upload['id'], '_wp_attachment_image_alt', $_image_data['alt'] );
                        wp_update_post(
                            array(
                                'ID'           => $upload['id'],
                                'post_excerpt' => MainWP_Utility::esc_content( $_image_data['caption'], 'mixed' ),
                                'post_content' => $_image_data['description'],
                                'post_title'   => htmlspecialchars( $_image_data['title'] ),
                            )
                        );
                    }
                }
            } catch ( MainWP_Exception $e ) {
                // ok!
            }
        }

        if ( ! $featured_image_exist ) {
            delete_post_meta( $new_post_id, '_thumbnail_id' );
        }
    }
}
