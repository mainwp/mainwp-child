<?php
/**
 * Changes Installer Class
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 *
 */

declare(strict_types=1);

namespace MainWP\Child\Changes;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Changes_Defaults
 */
class Changes_Defaults {


    /**
     * Holds the array with all the default built in links.
     *
     * @var array
     */
    private static $ws_al_built_links = array();

    /**
     * Loads all the events for the core and extentions
     *
     * @return void
     */
    public static function set_changes_logs() {

        $changes_default_logs = array(
            esc_html__( 'Defaults Logs', 'mainwp-child' ) => array(
                esc_html__( 'Post', 'mainwp-child' )       => array(
                    array(
                        2000,
                        esc_html__( 'Created a new post', 'mainwp-child' ),
                        esc_html__( 'Created the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'created',
                    ),
                    array(
                        2001,
                        esc_html__( 'Published a post', 'mainwp-child' ),
                        esc_html__( 'Published the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'published',
                    ),
                    array(
                        2002,
                        esc_html__( 'Modified a post', 'mainwp-child' ),
                        esc_html__( 'Modified the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2008,
                        esc_html__( 'Permanently deleted a post', 'mainwp-child' ),
                        esc_html__( 'Permanently deleted the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        array(),
                        'post',
                        'deleted',
                    ),
                    array(
                        2012,
                        esc_html__( 'Moved a post to trash', 'mainwp-child' ),
                        esc_html__( 'Moved the post %PostTitle% to trash.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'PostUrlIfPublished' ) ),
                        'post',
                        'deleted',
                    ),
                    array(
                        2014,
                        esc_html__( 'Restored a post from trash', 'mainwp-child' ),
                        esc_html__( 'Restored the post %PostTitle% from trash.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'restored',
                    ),
                    array(
                        2016,
                        esc_html__( 'Changed the category of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the category(ies) of the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )                => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )              => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )            => '%PostStatus%',
                            esc_html__( 'New category(ies)', 'mainwp-child' )      => '%NewCategories%',
                            esc_html__( 'Previous category(ies)', 'mainwp-child' ) => '%OldCategories%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2017,
                        esc_html__( 'Changed the URL of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the URL of the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )      => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )    => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )  => '%PostStatus%',
                            esc_html__( 'Previous URL', 'mainwp-child' ) => '%OldUrl%',
                            esc_html__( 'New URL', 'mainwp-child' )      => '%NewUrl%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2019,
                        esc_html__( 'Changed the author of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the author of the post %PostTitle% to %NewAuthor%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )         => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )       => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )     => '%PostStatus%',
                            esc_html__( 'Previous author', 'mainwp-child' ) => '%OldAuthor%',
                        ),
                        static::ws_al_defaults_build_links( array( 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2021,
                        esc_html__( 'Changed the status of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the status of the post %PostTitle% to %NewStatus%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )         => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )       => '%PostType%',
                            esc_html__( 'Previous status', 'mainwp-child' ) => '%OldStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2047,
                        esc_html__( 'Changed the parent of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the parent of the post %PostTitle% to %NewParentName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )         => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )       => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )     => '%PostStatus%',
                            esc_html__( 'Previous parent', 'mainwp-child' ) => '%OldParentName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2048,
                        esc_html__( 'Changed the template of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the template of the post %PostTitle% to %NewTemplate%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )           => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )         => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )       => '%PostStatus%',
                            esc_html__( 'Previous template', 'mainwp-child' ) => '%OldTemplate%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2049,
                        esc_html__( 'Set a post as Sticky', 'mainwp-child' ),
                        esc_html__( 'Set the post %PostTitle% as sticky.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2050,
                        esc_html__( 'Removed post from Sticky', 'mainwp-child' ),
                        esc_html__( 'Removed the post %PostTitle% from sticky.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2053,
                        esc_html__( 'Created a custom field in a post', 'mainwp-child' ),
                        esc_html__( 'Created the new custom field %MetaKey% in the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )            => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )          => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )        => '%PostStatus%',
                            esc_html__( 'Custom field value', 'mainwp-child' ) => '%MetaValue%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'MetaLink', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2073,
                        esc_html__( 'Submitted post for review', 'mainwp-child' ),
                        esc_html__( 'Submitted the post %PostTitle% for review.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2074,
                        esc_html__( 'Scheduled a post for publishing', 'mainwp-child' ),
                        esc_html__( 'Scheduled the post %PostTitle% to be published on %PublishingDate%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2025,
                        esc_html__( 'User changed the visibility of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the visibility of the post %PostTitle% to %NewVisibility%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )                    => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )                  => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )                => '%PostStatus%',
                            esc_html__( 'Previous visibility status', 'mainwp-child' ) => '%OldVisibility%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2027,
                        esc_html__( 'Changed the date of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the date of the post %PostTitle% to %NewDate%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )       => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )     => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )   => '%PostStatus%',
                            esc_html__( 'Previous date', 'mainwp-child' ) => '%OldDate%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2065,
                        esc_html__( 'Modified the content of a post', 'mainwp-child' ),
                        esc_html__( 'Modified the content of the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'RevisionLink', 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2086,
                        esc_html__( 'Changed title of a post', 'mainwp-child' ),
                        esc_html__( 'Changed the title of the post %OldTitle% to %NewTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2100,
                        esc_html__( 'Opened a post in editor', 'mainwp-child' ),
                        esc_html__( 'Opened the post %PostTitle% in the editor.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'opened',
                    ),
                    array(
                        2101,
                        esc_html__( 'Viewed a post', 'mainwp-child' ),
                        esc_html__( 'Viewed the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'PostUrl', 'EditorLinkPost' ) ),
                        'post',
                        'viewed',
                    ),
                    array(
                        2111,
                        esc_html__( 'Enabled / disabled comments in a post', 'mainwp-child' ),
                        esc_html__( 'Comments in the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'enabled',
                    ),
                    array(
                        2112,
                        esc_html__( 'Enabled / disabled trackbacks in a post', 'mainwp-child' ),
                        esc_html__( 'Pingbacks and Trackbacks in the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'enabled',
                    ),
                    array(
                        2119,
                        esc_html__( 'Added tag(s) to a post', 'mainwp-child' ),
                        esc_html__( 'Added tag(s) to the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'ID', 'mainwp-child' ) => '%PostID%',
                            esc_html__( 'Type', 'mainwp-child' ) => '%PostType%',
                            esc_html__( 'Status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Added tag(s)', 'mainwp-child' ) => '%tag%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2120,
                        esc_html__( 'Removed tag(s) from a post', 'mainwp-child' ),
                        esc_html__( 'Removed tag(s) from the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'ID', 'mainwp-child' ) => '%PostID%',
                            esc_html__( 'Type', 'mainwp-child' ) => '%PostType%',
                            esc_html__( 'Status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Removed tag(s)', 'mainwp-child' ) => '%tag%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2129,
                        esc_html__( 'Updated the excerpt of a post', 'mainwp-child' ),
                        esc_html__( 'The excerpt of the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )                => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )              => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )            => '%PostStatus%',
                            esc_html__( 'Previous excerpt entry', 'mainwp-child' ) => '%old_post_excerpt%',
                            esc_html__( 'New excerpt entry', 'mainwp-child' )      => '%post_excerpt%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2130,
                        esc_html__( 'Updated the feature image of a post', 'mainwp-child' ),
                        esc_html__( 'The featured image of the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )        => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )      => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )    => '%PostStatus%',
                            esc_html__( 'Previous image', 'mainwp-child' ) => '%previous_image%',
                            esc_html__( 'New image', 'mainwp-child' )      => '%new_image%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    // Post 9043 - Added / changed / removed a post’s featured image
                    array(
                        2133,
                        esc_html__( 'Taken over a post from another user', 'mainwp-child' ),
                        esc_html__( 'Has taken over the post %PostTitle% from %user%', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )        => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )      => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )    => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                ),
                esc_html__( 'Custom field', 'mainwp-child' ) => array(
                    array(
                        2131,
                        esc_html__( 'Added a relationship in an ACF custom field', 'mainwp-child' ),
                        esc_html__( 'Added relationships to the custom field %MetaKey% in the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )           => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )         => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )       => '%PostStatus%',
                            esc_html__( 'New relationships', 'mainwp-child' ) => '%Relationships%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'MetaLink' ) ),
                        'custom-field',
                        'modified',
                    ),
                    array(
                        2132,
                        esc_html__( 'Removed a relationship from an ACF custom field', 'mainwp-child' ),
                        esc_html__( 'Removed relationships from the custom field %MetaKey% in the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )               => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )             => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )           => '%PostStatus%',
                            esc_html__( 'Removed relationships', 'mainwp-child' ) => '%Relationships%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'MetaLink' ) ),
                        'custom-field',
                        'modified',
                    ),
                    array(
                        2054,
                        esc_html__( 'Changed the value of a custom field', 'mainwp-child' ),
                        esc_html__( 'Modified the value of the custom field %MetaKey% in the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )                     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )                   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' )                 => '%PostStatus%',
                            esc_html__( 'Previous custom field value', 'mainwp-child' ) => '%MetaValueOld%',
                            esc_html__( 'New custom field value', 'mainwp-child' )      => '%MetaValueNew%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'MetaLink', 'PostUrlIfPublished' ) ),
                        'custom-field',
                        'modified',
                    ),
                    array(
                        2055,
                        esc_html__( 'Deleted a custom field', 'mainwp-child' ),
                        esc_html__( 'Deleted the custom field %MetaKey% from the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'custom-field',
                        'deleted',
                    ),
                    array(
                        2062,
                        esc_html__( 'Renamed a custom field', 'mainwp-child' ),
                        esc_html__( 'Renamed the custom field %MetaKeyOld% on post %PostTitle% to %MetaKeyNew%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post', 'mainwp-child' ) => '%PostTitle%',
                            esc_html__( 'Post ID', 'mainwp-child' ) => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' ) => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'custom-field',
                        'renamed',
                    ),
                ),
                esc_html__( 'Categories', 'mainwp-child' ) => array(
                    array(
                        2023,
                        esc_html__( 'Created a new category', 'mainwp-child' ),
                        esc_html__( 'Created the category %CategoryName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Slug', 'mainwp-child' ) => 'Slug',
                        ),
                        static::ws_al_defaults_build_links( array( 'CategoryLink' ) ),
                        'category',
                        'created',
                    ),
                    array(
                        2024,
                        esc_html__( 'Deleted a category', 'mainwp-child' ),
                        esc_html__( 'Deleted the category %CategoryName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Slug', 'mainwp-child' ) => 'Slug',
                        ),
                        array(),
                        'category',
                        'deleted',
                    ),
                    array(
                        2052,
                        esc_html__( 'Changed the parent of a category', 'mainwp-child' ),
                        esc_html__( 'Changed the parent of the category %CategoryName% to %NewParent%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Slug', 'mainwp-child' ) => '%Slug%',
                            esc_html__( 'Previous parent', 'mainwp-child' ) => '%OldParent%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CategoryLink' ) ),
                        'category',
                        'modified',
                    ),
                    array(
                        2127,
                        esc_html__( 'Renamed a category', 'mainwp-child' ),
                        esc_html__( 'Renamed the category %old_name% to %new_name%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Slug', 'mainwp-child' ) => '%slug%',
                        ),
                        static::ws_al_defaults_build_links( array( 'cat_link' ) ),
                        'category',
                        'renamed',
                    ),
                    array(
                        2128,
                        esc_html__( 'Renamed a category', 'mainwp-child' ),
                        esc_html__( 'Changed the slug of the category %CategoryName% to %new_slug%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous slug', 'mainwp-child' ) => '%old_slug%',
                        ),
                        static::ws_al_defaults_build_links( array( 'cat_link' ) ),
                        'category',
                        'modified',
                    ),
                ),
                esc_html__( 'Tag', 'mainwp-child' )        => array(
                    array(
                        2121,
                        esc_html__( 'Created a new tag', 'mainwp-child' ),
                        esc_html__( 'Created the tag %TagName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Slug', 'mainwp-child' ) => 'Slug',
                        ),
                        static::ws_al_defaults_build_links( array( 'TagLink' ) ),
                        'tag',
                        'created',
                    ),
                    array(
                        2122,
                        esc_html__( 'Deleted a tag', 'mainwp-child' ),
                        esc_html__( 'Deleted the tag %TagName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Slug', 'mainwp-child' ) => 'Slug',
                        ),
                        array(),
                        'tag',
                        'deleted',
                    ),
                    array(
                        2123,
                        esc_html__( 'Renamed the tag %old_name% to %new_name%.', 'mainwp-child' ),
                        '',
                        array(
                            esc_html__( 'Slug', 'mainwp-child' ) => '%Slug%',
                        ),
                        static::ws_al_defaults_build_links( array( 'TagLink' ) ),
                        'tag',
                        'renamed',
                    ),
                    array(
                        2124,
                        esc_html__( 'Changed the slug of a tag', 'mainwp-child' ),
                        esc_html__( 'Changed the slug of the tag %tag% to %new_slug%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous slug', 'mainwp-child' ) => '%old_slug%',
                        ),
                        static::ws_al_defaults_build_links( array( 'TagLink' ) ),
                        'tag',
                        'modified',
                    ),
                    array(
                        2125,
                        esc_html__( 'Changed the description of a tag', 'mainwp-child' ),
                        esc_html__( 'Changed the description of the tag %tag%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Slug', 'mainwp-child' ) => '%Slug%',
                            esc_html__( 'Previous description', 'mainwp-child' ) => '%old_desc%',
                            esc_html__( 'New description', 'mainwp-child' ) => '%new_desc%',
                        ),
                        static::ws_al_defaults_build_links( array( 'TagLink' ) ),
                        'tag',
                        'modified',
                    ),
                ),
                esc_html__( 'File', 'mainwp-child' )       => array(
                    array(
                        2010,
                        esc_html__( 'Uploaded a file', 'mainwp-child' ),
                        esc_html__( 'Uploaded a file called %FileName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Directory', 'mainwp-child' ) => '%FilePath%',
                        ),
                        static::ws_al_defaults_build_links( array( 'AttachmentUrl' ) ),
                        'file',
                        'uploaded',
                    ),
                    array(
                        2011,
                        esc_html__( 'Deleted a file', 'mainwp-child' ),
                        esc_html__( 'Deleted the file %FileName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Directory', 'mainwp-child' ) => '%FilePath%',
                        ),
                        array(),
                        'file',
                        'deleted',
                    ),
                ),
                esc_html__( 'Widget', 'mainwp-child' )     => array(
                    array(
                        2042,
                        esc_html__( 'Added a new widget', 'mainwp-child' ),
                        esc_html__( 'Added a new %WidgetName% widget in %Sidebar%.', 'mainwp-child' ),
                        array(),
                        array(),
                        'widget',
                        'added',
                    ),
                    array(
                        2043,
                        esc_html__( 'Modified a widget', 'mainwp-child' ),
                        esc_html__( 'Modified the %WidgetName% widget in %Sidebar%.', 'mainwp-child' ),
                        array(),
                        array(),
                        'widget',
                        'modified',
                    ),
                    array(
                        2044,
                        esc_html__( 'Deleted a widget', 'mainwp-child' ),
                        esc_html__( 'Deleted the %WidgetName% widget from %Sidebar%.', 'mainwp-child' ),
                        array(),
                        array(),
                        'widget',
                        'deleted',
                    ),
                    array(
                        2045,
                        esc_html__( 'Moved a widget in between sections', 'mainwp-child' ),
                        esc_html__( 'Moved the %WidgetName% widget.', 'mainwp-child' ),
                        array(
                            esc_html__( 'From', 'mainwp-child' ) => '%OldSidebar%',
                            esc_html__( 'To', 'mainwp-child' ) => '%NewSidebar%',
                        ),
                        array(),
                        'widget',
                        'modified',
                    ),
                    array(
                        2071,
                        esc_html__( 'Changed the position of a widget in a section', 'mainwp-child' ),
                        esc_html__( 'Changed the position of the %WidgetName% widget in %Sidebar%.', 'mainwp-child' ),
                        array(),
                        array(),
                        'widget',
                        'modified',
                    ),
                ),
                esc_html__( 'Plugin', 'mainwp-child' )     => array(
                    array(
                        2051,
                        esc_html__( 'Modified a file with the plugin editor', 'mainwp-child' ),
                        esc_html__( 'Modified the file %File% with the plugin editor.', 'mainwp-child' ),
                        array(),
                        array(),
                        'file',
                        'modified',
                    ),
                    array(
                        5028,
                        esc_html__( 'The automatic updates setting for a plugin was changed.', 'mainwp-child' ),
                        esc_html__( 'Changed the Automatic updates setting for the plugin %name%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Install location', 'mainwp-child' )     => '%install_directory%',
                        ),
                        array(),
                        'plugin',
                        'enabled',
                    ),
                ),
                esc_html__( 'Theme', 'mainwp-child' )      => array(
                    array(
                        2046,
                        esc_html__( 'Modified a file with the theme editor', 'mainwp-child' ),
                        esc_html__( 'Modified the file %Theme%/%File% with the theme editor.', 'mainwp-child' ),
                        array(),
                        array(),
                        'file',
                        'modified',
                    ),
                    array(
                        5029,
                        esc_html__( 'The automatic updates setting for a theme was changed.', 'mainwp-child' ),
                        esc_html__( 'Changed the Automatic updates setting for the theme %name%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Install location', 'mainwp-child' )     => '%install_directory%',
                        ),
                        array(),
                        'theme',
                        'enabled',
                    ),
                ),
                esc_html__( 'Menu', 'mainwp-child' )       => array(
                    array(
                        2078,
                        esc_html__( 'Created a menu', 'mainwp-child' ),
                        esc_html__( 'New menu called %MenuName%.', 'mainwp-child' ),
                        array(),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'created',
                    ),
                    array(
                        2079,
                        esc_html__( 'Added item(s) to a menu', 'mainwp-child' ),
                        esc_html__( 'Added the item %ContentName% to the menu %MenuName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Item type', 'mainwp-child' ) => '%ContentType%',
                        ),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                    array(
                        2080,
                        esc_html__( 'Removed item(s) from a menu', 'mainwp-child' ),
                        esc_html__( 'Removed the item %ContentName% from the menu %MenuName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Item type', 'mainwp-child' ) => '%ContentType%',
                        ),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                    array(
                        2081,
                        esc_html__( 'Deleted a menu', 'mainwp-child' ),
                        esc_html__( 'Deleted the menu %MenuName%.', 'mainwp-child' ),
                        array(),
                        array(),
                        'menu',
                        'deleted',
                    ),
                    array(
                        2082,
                        esc_html__( 'Changed the settings of a menu', 'mainwp-child' ),
                        esc_html__( 'The setting %MenuSetting% in the menu %MenuName%.', 'mainwp-child' ),
                        array(),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'enabled',
                    ),
                    array(
                        2083,
                        esc_html__( 'Modified the item(s) in a menu', 'mainwp-child' ),
                        esc_html__( 'Modified the item %ContentName% in the menu %MenuName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Item type', 'mainwp-child' ) => '%ContentType%',
                        ),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                    array(
                        2084,
                        esc_html__( 'Renamed a menu', 'mainwp-child' ),
                        esc_html__( 'Renamed the menu %OldMenuName% to %MenuName%.', 'mainwp-child' ),
                        array(),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'renamed',
                    ),
                    array(
                        2085,
                        esc_html__( 'Changed the order of the objects in a menu.', 'mainwp-child' ),
                        esc_html__( 'Changed the order of the items in the menu %MenuName%.', 'mainwp-child' ),
                        array(),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                    array(
                        2089,
                        esc_html__( 'Moved an item as a sub-item in a menu', 'mainwp-child' ),
                        esc_html__( 'Moved items as sub-items in the menu %MenuName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Moved item', 'mainwp-child' )       => '%ItemName%',
                            esc_html__( 'as a sub-item of', 'mainwp-child' ) => '%ParentName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                ),
                esc_html__( 'Comment', 'mainwp-child' )    => array(
                    array(
                        2090,
                        esc_html__( 'Approved a comment', 'mainwp-child' ),
                        esc_html__( 'Approved the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'approved',
                    ),
                    array(
                        2091,
                        esc_html__( 'Unapproved a comment', 'mainwp-child' ),
                        esc_html__( 'Unapproved the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'unapproved',
                    ),
                    array(
                        2092,
                        esc_html__( 'Replied to a comment', 'mainwp-child' ),
                        esc_html__( 'Replied to the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'created',
                    ),
                    array(
                        2093,
                        esc_html__( 'Edited a comment', 'mainwp-child' ),
                        esc_html__( 'Edited the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'modified',
                    ),
                    array(
                        2094,
                        esc_html__( 'Marked a comment as spam', 'mainwp-child' ),
                        esc_html__( 'Marked the comment posted by %Author% on the post %PostTitle% as spam.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'unapproved',
                    ),
                    array(
                        2095,
                        esc_html__( 'Marked a comment as not spam', 'mainwp-child' ),
                        esc_html__( 'Marked the comment posted by %Author% on the post %PostTitle% as not spam.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'approved',
                    ),
                    array(
                        2096,
                        esc_html__( 'Moved a comment to trash', 'mainwp-child' ),
                        esc_html__( 'Moved the comment posted by %Author% on the post %PostTitle% to trash.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'deleted',
                    ),
                    array(
                        2097,
                        esc_html__( 'Restored a comment from the trash', 'mainwp-child' ),
                        esc_html__( 'Restored the comment posted by %Author% on the post %PostTitle% from trash.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'restored',
                    ),
                    array(
                        2098,
                        esc_html__( 'Permanently deleted a comment', 'mainwp-child' ),
                        esc_html__( 'Permanently deleted the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'PostUrlIfPublished' ) ),
                        'comment',
                        'deleted',
                    ),
                    array(
                        2099,
                        esc_html__( 'Posted a comment', 'mainwp-child' ),
                        esc_html__( 'Posted a comment on the post %PostTitle%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp-child' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp-child' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp-child' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp-child' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'created',
                    ),
                ),
                esc_html__( 'User', 'mainwp-child' )       => array(
                    array(
                        1000,
                        esc_html__( 'Successfully logged in', 'mainwp-child' ),
                        esc_html__( 'User logged in.', 'mainwp-child' ),
                        array(),
                        array(),
                        'user',
                        'login',
                    ),
                    array(
                        1001,
                        esc_html__( 'Successfully logged out', 'mainwp-child' ),
                        esc_html__( 'User logged out.', 'mainwp-child' ),
                        array(),
                        array(),
                        'user',
                        'logout',
                    ),
                    array(
                        1005,
                        esc_html__( 'Successful log in but other sessions exist for user', 'mainwp-child' ),
                        esc_html__( 'User logged in however there are other session(s) already for this user.', 'mainwp-child' ),
                        array(
                            esc_html__( 'IP address(es)', 'mainwp-child' ) => '%IPAddress%',
                        ),
                        array(),
                        'user',
                        'login',
                    ),
                    array(
                        1006,
                        esc_html__( 'Logged out all other sessions with same user', 'mainwp-child' ),
                        esc_html__( 'Logged out all other sessions with the same user.', 'mainwp-child' ),
                        array(),
                        array(),
                        'user',
                        'logout',
                    ),
                    array(
                        1009,
                        esc_html__( 'Terminated a user session', 'mainwp-child' ),
                        esc_html__( 'The plugin terminated an idle session for the user %username%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%TargetUserRole%',
                            esc_html__( 'Session ID', 'mainwp-child' ) => '%SessionID%',
                        ),
                        array(),
                        'user',
                        'logout',
                    ),
                    array(
                        1008,
                        esc_html__( 'Switched to another user', 'mainwp-child' ),
                        esc_html__( 'Switched the session to being logged in as %TargetUserName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%TargetUserRole%',
                        ),
                        array(),
                        'user',
                        'login',
                    ),
                    array(
                        1010,
                        esc_html__( 'User requested a password reset', 'mainwp-child' ),
                        esc_html__( 'User requested a password reset. This does not mean that the password was changed.', 'mainwp-child' ),
                        array(),
                        array(),
                        'user',
                        'submitted',
                    ),
                    array(
                        4000,
                        esc_html__( 'A new user was created', 'mainwp-child' ),
                        __( 'A new user %NewUserData->Username% is created via registration.', 'mainwp-child' ),
                        array(
                            esc_html__( 'User', 'mainwp-child' )  => '%NewUserData->Username%',
                            esc_html__( 'Email', 'mainwp-child' ) => '%NewUserData->Email%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'created',
                    ),
                    array(
                        4001,
                        esc_html__( 'User created a new user', 'mainwp-child' ),
                        __( 'Created the new user: %NewUserData->Username%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' )  => '%NewUserData->Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%NewUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%NewUserData->LastName%',
                            esc_html__( 'Email', 'mainwp-child' ) => '%NewUserData->Email%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'created',
                    ),
                    array(
                        4002,
                        esc_html__( 'Change the role of a user', 'mainwp-child' ),
                        esc_html__( 'Changed the role of user %TargetUsername% to %NewRole%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous role', 'mainwp-child' ) => '%OldRole%',
                            esc_html__( 'First name', 'mainwp-child' )    => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' )     => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4003,
                        esc_html__( 'Changed the password', 'mainwp-child' ),
                        esc_html__( 'Changed the password.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%TargetUserData->Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%TargetUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%TargetUserData->LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4004,
                        esc_html__( 'Changed the password of a user', 'mainwp-child' ),
                        __( 'Changed the password of the user %TargetUserData->Username%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%TargetUserData->Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%TargetUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%TargetUserData->LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4005,
                        esc_html__( 'Changed the email address', 'mainwp-child' ),
                        esc_html__( 'Changed the email address to %NewEmail%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                            esc_html__( 'Previous email address', 'mainwp-child' ) => '%OldEmail%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4006,
                        esc_html__( 'Changed the email address of a user', 'mainwp-child' ),
                        esc_html__( 'Changed the email address of the user %TargetUsername% to %NewEmail%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                            esc_html__( 'Previous email address', 'mainwp-child' ) => '%OldEmail%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4007,
                        esc_html__( 'Deleted a user', 'mainwp-child' ),
                        __( 'Deleted the user %TargetUserData->Username%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%TargetUserData->Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%NewUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%NewUserData->LastName%',
                        ),
                        array(),
                        'user',
                        'deleted',
                    ),
                    array(
                        4008,
                        esc_html__( 'Granted super admin privileges to a user', 'mainwp-child' ),
                        esc_html__( 'Granted Super Admin privileges to the user %TargetUsername%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4009,
                        esc_html__( 'Revoked super admin privileges from a user', 'mainwp-child' ),
                        esc_html__( 'Revoked Super Admin privileges from %TargetUsername%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4012,
                        esc_html__( 'Added a network user to a site', 'mainwp-child' ),
                        __( 'Created the new network user %NewUserData->Username%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'First name', 'mainwp-child' ) => '%NewUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' )  => '%NewUserData->LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'created',
                    ),
                    array(
                        4011,
                        esc_html__( 'Removed a network user from a site', 'mainwp-child' ),
                        esc_html__( 'Removed user %TargetUsername% from the site %SiteName%', 'mainwp-child' ),
                        array(
                            esc_html__( 'Site role', 'mainwp-child' )  => '%TargetUserRole%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' )  => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4010,
                        esc_html__( 'Created a new network user', 'mainwp-child' ),
                        esc_html__( 'Added user %TargetUsername% to the site %SiteName%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%TargetUserRole%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4013,
                        esc_html__( 'User has been activated on the network', 'mainwp-child' ),
                        __( 'User %NewUserData->Username% has been activated.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%NewUserData->Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%NewUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%NewUserData->LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'activated',
                    ),
                    array(
                        4014,
                        esc_html__( 'Opened the profile page of a user', 'mainwp-child' ),
                        esc_html__( 'Opened the profile page of user %TargetUsername%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'opened',
                    ),
                    array(
                        4015,
                        esc_html__( 'Changed a custom field value in user profile', 'mainwp-child' ),
                        esc_html__( 'Changed the value of the custom field %custom_field_name% in the user profile %TargetUsername%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                            esc_html__( 'Previous value', 'mainwp-child' ) => '%old_value%',
                            esc_html__( 'New value', 'mainwp-child' ) => '%new_value%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink', 'MetaLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4016,
                        esc_html__( 'Created a custom field in a user profile', 'mainwp-child' ),
                        esc_html__( 'Created the custom field %custom_field_name% in the user profile %TargetUsername%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                            esc_html__( 'Custom field value', 'mainwp-child' ) => '%new_value%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink', 'MetaLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4017,
                        esc_html__( 'Changed the first name (of a user)', 'mainwp-child' ),
                        esc_html__( 'Changed the first name of the user %TargetUsername% to %new_firstname%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'Previous name', 'mainwp-child' ) => '%old_firstname%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4018,
                        esc_html__( 'Changed the last name (of a user)', 'mainwp-child' ),
                        esc_html__( 'Changed the last name of the user %TargetUsername% to %new_lastname%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Previous last name', 'mainwp-child' ) => '%old_lastname%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4019,
                        esc_html__( 'Changed the nickname (of a user)', 'mainwp-child' ),
                        esc_html__( 'Changed the nickname of the user %TargetUsername% to %new_nickname%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                            esc_html__( 'Previous nickname', 'mainwp-child' ) => '%old_nickname%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4020,
                        esc_html__( 'Changed the display name (of a user)', 'mainwp-child' ),
                        esc_html__( 'Changed the display name of the user %TargetUsername% to %new_displayname%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                            esc_html__( 'Previous display name', 'mainwp-child' ) => '%old_displayname%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4021,
                        esc_html__( 'Changed the website URL of the user', 'mainwp-child' ),
                        esc_html__( 'Changed the website URL of the user %TargetUsername% to %new_url%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%LastName%',
                            esc_html__( 'Previous website URL', 'mainwp-child' ) => '%old_url%',

                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4025,
                        esc_html__( 'User added / removed application password from own profile', 'mainwp-child' ),
                        esc_html__( 'The application password %friendly_name%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%firstname%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%lastname%',
                        ),
                        array(),
                        'user',
                        'added',
                    ),
                    array(
                        4026,
                        esc_html__( 'User added / removed application password from another user’s profile', 'mainwp-child' ),
                        esc_html__( 'The application password %friendly_name% for the user %login%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%firstname%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%lastname%',
                        ),
                        array(),
                        'user',
                        'added',
                    ),
                    array(
                        4028,
                        esc_html__( 'User revoked all application passwords from own profile', 'mainwp-child' ),
                        esc_html__( 'All application passwords from the user %login%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%firstname%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%lastname%',
                        ),
                        array(),
                        'user',
                        'revoked',
                    ),
                    array(
                        4027,
                        esc_html__( 'User revoked all application passwords from another user’s profile', 'mainwp-child' ),
                        esc_html__( 'All application passwords.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Role', 'mainwp-child' ) => '%roles%',
                            esc_html__( 'First name', 'mainwp-child' ) => '%firstname%',
                            esc_html__( 'Last name', 'mainwp-child' ) => '%lastname%',
                        ),
                        array(),
                        'user',
                        'revoked',
                    ),
                ),
                esc_html__( 'Database', 'mainwp-child' )   => array(
                    array(
                        5010,
                        esc_html__( 'Plugin created database table(s)', 'mainwp-child' ),
                        __( 'The plugin %Plugin->Name% created this table in the database.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Table', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'created',
                    ),
                    array(
                        5011,
                        esc_html__( 'Plugin modified the structure of database table(s)', 'mainwp-child' ),
                        __( 'The plugin %Plugin->Name% modified the structure of a database table.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Table', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'modified',
                    ),
                    array(
                        5012,
                        esc_html__( 'Plugin deleted database table(s)', 'mainwp-child' ),
                        __( 'The plugin %Plugin->Name% deleted this table from the database.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Table', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'deleted',
                    ),
                    array(
                        5013,
                        esc_html__( 'Theme created database table(s)', 'mainwp-child' ),
                        __( 'The theme %Theme->Name% created this tables in the database.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Table', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'created',
                    ),
                    array(
                        5014,
                        esc_html__( 'Theme modified the structure of table(s) in the database', 'mainwp-child' ),
                        __( 'The theme %Theme->Name% modified the structure of this database table', 'mainwp-child' ),
                        array(
                            esc_html__( 'Table', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'modified',
                    ),
                    array(
                        5015,
                        esc_html__( 'Theme deleted database table(s)', 'mainwp-child' ),
                        __( 'The theme %Theme->Name% deleted this table from the database.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Tables', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'deleted',
                    ),
                    array(
                        5016,
                        esc_html__( 'Unknown component created database table(s)', 'mainwp-child' ),
                        esc_html__( 'An unknown component created these tables in the database.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Tables', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'created',
                    ),
                    array(
                        5017,
                        esc_html__( 'Unknown component modified the structure of table(s )in the database', 'mainwp-child' ),
                        esc_html__( 'An unknown component modified the structure of these database tables.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Tables', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'modified',
                    ),
                    array(
                        5018,
                        esc_html__( 'Unknown component deleted database table(s)', 'mainwp-child' ),
                        esc_html__( 'An unknown component deleted these tables from the database.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Tables', 'mainwp-child' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'deleted',
                    ),
                ),
                esc_html__( 'System setting', 'mainwp-child' ) => array(
                    array(
                        6001,
                        esc_html__( 'Changed the option anyone can register', 'mainwp-child' ),
                        __( 'The <strong>Membership</strong> setting <strong>Anyone can register</strong>.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6002,
                        esc_html__( 'Changed the new user default role', 'mainwp-child' ),
                        __( 'Changed the <strong>New user default role</strong> WordPress setting.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous role', 'mainwp-child' ) => '%OldRole%',
                            esc_html__( 'New role', 'mainwp-child' )      => '%NewRole%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6003,
                        esc_html__( 'Changed the WordPress administrator notification email address', 'mainwp-child' ),
                        __( 'Change the <strong>Administrator email address</strong> in the WordPress settings.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous address', 'mainwp-child' ) => '%OldEmail%',
                            esc_html__( 'New address', 'mainwp-child' )      => '%NewEmail%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6005,
                        esc_html__( 'Changed the WordPress permalinks', 'mainwp-child' ),
                        __( 'Changed the <strong>WordPress permalinks</strong>.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous permalinks', 'mainwp-child' ) => '%OldPattern%',
                            esc_html__( 'New permalinks', 'mainwp-child' )      => '%NewPattern%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6008,
                        esc_html__( 'Changed the setting: Discourage search engines from indexing this site', 'mainwp-child' ),
                        __( 'Changed the status of the WordPress setting <strong>Search engine visibility</strong> (Discourage search engines from indexing this site)', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6009,
                        esc_html__( 'Enabled / disabled comments on the website', 'mainwp-child' ),
                        __( 'Changed the status of the WordPress setting <strong>Allow people to submit comments on new posts</strong>.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),

                    array(
                        6010,
                        esc_html__( 'Changed the setting: Comment author must fill out name and email', 'mainwp-child' ),
                        __( 'Changed the status of the WordPress setting <strong>.Comment author must fill out name and email</strong>.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6011,
                        esc_html__( 'Changed the setting: Users must be logged in and registered to comment', 'mainwp-child' ),
                        __( 'Changed the status of the WordPress setting <strong>Users must be registered and logged in to comment</strong>.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6012,
                        esc_html__( 'Changed the setting: Automatically close comments after a number of days', 'mainwp-child' ),
                        __( 'Changed the status of the WordPress setting <strong>Automatically close comments after %Value% days</strong>.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6013,
                        esc_html__( 'Changed the value of the setting: Automatically close comments after a number of days.', 'mainwp-child' ),
                        __( 'Changed the value of the WordPress setting <strong>Automatically close comments after a number of days</strong> to %NewValue%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous value', 'mainwp-child' ) => '%OldValue%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6014,
                        esc_html__( 'Changed the setting: Comments must be manually approved', 'mainwp-child' ),
                        __( 'Changed the value of the WordPress setting <strong>Comments must be manualy approved</strong>.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6015,
                        esc_html__( 'Changed the setting: Author must have previously approved comments for the comments to appear', 'mainwp-child' ),
                        __( 'Changed the value of the WordPress setting <strong>Comment author must have a previously approved comment</strong>.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6016,
                        esc_html__( 'Changed the minimum number of links that a comment must have to be held in the queue', 'mainwp-child' ),
                        __( 'Changed the value of the WordPress setting <strong>Hold a comment in the queue if it contains links</strong> to %NewValue% links.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous value', 'mainwp-child' ) => '%OldValue%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6017,
                        esc_html__( 'Modified the list of keywords for comments moderation', 'mainwp-child' ),
                        esc_html__( 'Modified the list of keywords for comments moderation in WordPress.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6018,
                        esc_html__( 'Modified the list of keywords for comments blacklisting', 'mainwp-child' ),
                        __( 'Modified the list of <strong>Disallowed comment keys</strong> (keywords) for comments blacklisting in WordPress.', 'mainwp-child' ),
                        array(),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6024,
                        esc_html__( 'Changed the WordPress address (URL)', 'mainwp-child' ),
                        __( 'Changed the <strong>WordPress address (URL)</strong> to %new_url%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous URL', 'mainwp-child' ) => '%old_url%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6025,
                        esc_html__( 'Changed the site address (URL)', 'mainwp-child' ),
                        __( 'Changed the <strong>Site address (URL)</strong> to %new_url%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous URL', 'mainwp-child' ) => '%old_url%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6035,
                        esc_html__( 'Changed the “Your homepage displays” WordPress setting', 'mainwp-child' ),
                        __( 'Changed the <strong>Your homepage displays</strong> WordPress setting to %new_homepage%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous setting', 'mainwp-child' ) => '%old_homepage%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6036,
                        esc_html__( 'Changed the homepage in the WordPress setting', 'mainwp-child' ),
                        __( 'Changed the <strong>Homepage</strong> in the WordPress settings to %new_page%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous page', 'mainwp-child' ) => '%old_page%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6037,
                        esc_html__( 'Changed the posts page in the WordPress settings', 'mainwp-child' ),
                        __( 'Changed the <strong> Posts</strong>  page in the WordPress settings to %new_page%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous page', 'mainwp-child' ) => '%old_page%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),

                    array(
                        6040,
                        esc_html__( 'Changed the Timezone in the WordPress settings', 'mainwp-child' ),
                        __( 'Changed the <strong>Timezone</strong> in the WordPress settings to %new_timezone%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous timezone', 'mainwp-child' ) => '%old_timezone%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6041,
                        esc_html__( 'Changed the Date format in the WordPress settings', 'mainwp-child' ),
                        __( 'Changed the <strong>Date format</strong> in the WordPress settings to %new_date_format%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous format', 'mainwp-child' ) => '%old_date_format%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6042,
                        esc_html__( 'Changed the Time format in the WordPress settings', 'mainwp-child' ),
                        __( 'Changed the <strong>Time format</strong> in the WordPress settings to %new_time_format%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous format', 'mainwp-child' ) => '%old_time_format%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6044,
                        esc_html__( 'User changed the WordPress automatic update settings', 'mainwp-child' ),
                        __( 'Changed the <strong>Automatic updates</strong> setting.', 'mainwp-child' ),
                        array(
                            esc_html__( 'New setting status', 'mainwp-child' ) => '%updates_status%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6045,
                        esc_html__( 'Changed the site language', 'mainwp-child' ),
                        __( 'Changed the <strong>Site Language</strong> to %new_value%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous setting', 'mainwp-child' ) => '%previous_value%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6059,
                        esc_html__( 'Changed the site title', 'mainwp-child' ),
                        __( 'Changed the <strong>Site Title</strong> to %new_value%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Previous setting', 'mainwp-child' ) => '%previous_value%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6063,
                        esc_html__( 'Added site icon', 'mainwp-child' ),
                        __( 'Added a new website Site Icon %filename%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'New directory', 'mainwp-child' ) => '%new_path%',
                        ),
                        array(),
                        'system-setting',
                        'added',
                    ),
                    array(
                        6064,
                        esc_html__( 'Changed site icon', 'mainwp-child' ),
                        __( 'Changed the Site Icon from %old_filename% to %filename%.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Old directory', 'mainwp-child' ) => '%old_path%',
                            esc_html__( 'New directory', 'mainwp-child' ) => '%new_path%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                ),
                esc_html__( 'WordPress Cron', 'mainwp-child' ) => array(
                    array(
                        6066,
                        esc_html__( 'New one time task (cron job) created', 'mainwp-child' ),
                        __( 'A new one-time task called %task_name% has been scheduled.', 'mainwp-child' ),
                        array(
                            esc_html__( 'The task is scheduled to run on', 'mainwp-child' ) => '%timestamp%',
                        ),
                        array(),
                        'cron-job',
                        'created',
                    ),
                    array(
                        6067,
                        esc_html__( 'New recurring task (cron job) created', 'mainwp-child' ),
                        __( 'A new recurring task (cron job) called %task_name% has been created.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Task\'s first run: ', 'mainwp-child' ) => '%timestamp%',
                            esc_html__( 'Task\'s interval: ', 'mainwp-child' ) => '%display_name%',
                        ),
                        array(),
                        'cron-job',
                        'created',
                    ),
                    array(
                        6068,
                        esc_html__( 'Recurring task (cron job) modified', 'mainwp-child' ),
                        __( 'The schedule of recurring task (cron job) called %task_name% has changed.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Task\'s old schedule: ', 'mainwp-child' ) => '%old_display_name%',
                            esc_html__( 'Task\'s new schedule: ', 'mainwp-child' ) => '%new_display_name%',
                        ),
                        array(),
                        'cron-job',
                        'modified',
                    ),
                    array(
                        6069,
                        esc_html__( 'One time task (cron job) executed', 'mainwp-child' ),
                        __( 'The one-time task called %task_name% has been executed.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Task\'s schedule was: ', 'mainwp-child' ) => '%timestamp%',
                        ),
                        array(),
                        'cron-job',
                        'executed',
                    ),
                    array(
                        6070,
                        esc_html__( 'Recurring task (cron job) executed', 'mainwp-child' ),
                        __( ' The recurring task (cron job) called %task_name% has been executed.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Task\'s schedule was: ', 'mainwp-child' ) => '%display_name%',
                        ),
                        array(),
                        'cron-job',
                        'executed',
                    ),
                    array(
                        6071,
                        esc_html__( 'Deleted one-time task (cron job)', 'mainwp-child' ),
                        __( 'The one-time task  (cron job) called %task_name% has been deleted.', 'mainwp-child' ),
                        array(),
                        array(),
                        'cron-job',
                        'deleted',
                    ),
                    array(
                        6072,
                        esc_html__( 'Deleted recurring task (cron job)', 'mainwp-child' ),
                        __( 'The recurring task (cron job) called %task_name% has been deleted.', 'mainwp-child' ),
                        array(
                            esc_html__( 'Task\'s schedule was: ', 'mainwp-child' ) => '%display_name%',
                        ),
                        array(),
                        'cron-job',
                        'deleted',
                    ),
                ),
            ),
        );

        // Create list of default logs.
        Changes_Logs_Manager::register_group(
            $changes_default_logs
        );
        // Load Custom logs.
        // wsal_load_include_custom_files();
    }

    /**
     * Builds a configuration object of links suitable for the events definition.
     *
     * @param string[] $link_aliases Link aliases.
     *
     * @return array
     */
    public static function ws_al_defaults_build_links( $link_aliases = array() ) {
        $result = array();

        if ( empty( self::$ws_al_built_links ) ) {
            self::$ws_al_built_links['CategoryLink']   = array( esc_html__( 'View category', 'mainwp-child' ) => '%CategoryLink%' );
            self::$ws_al_built_links['cat_link']       = array( esc_html__( 'View category', 'mainwp-child' ) => '%cat_link%' );
            self::$ws_al_built_links['ProductCatLink'] = array( esc_html__( 'View category', 'mainwp-child' ) => '%ProductCatLink%' );

            self::$ws_al_built_links['ContactSupport'] = array( esc_html__( 'Contact Support', 'mainwp-child' ) => 'https://melapress.com/contact/' );

            self::$ws_al_built_links['CommentLink'] = array(
                esc_html__( 'Comment', 'mainwp-child' ) => array(
                    'url'   => '%CommentLink%',
                    'label' => '%CommentDate%',
                ),
            );

            self::$ws_al_built_links['EditorLinkPage'] = array( esc_html__( 'View page in the editor', 'mainwp-child' ) => '%EditorLinkPage%' );

            self::$ws_al_built_links['EditorLinkPost'] = array( esc_html__( 'View the post in editor', 'mainwp-child' ) => '%EditorLinkPost%' );

            self::$ws_al_built_links['EditorLinkOrder'] = array( esc_html__( 'View the order', 'mainwp-child' ) => '%EditorLinkOrder%' );

            self::$ws_al_built_links['EditUserLink'] = array( esc_html__( 'User profile page', 'mainwp-child' ) => '%EditUserLink%' );

            self::$ws_al_built_links['LinkFile'] = array( esc_html__( 'Open the log file', 'mainwp-child' ) => '%LinkFile%' );

            self::$ws_al_built_links['MenuUrl'] = array( esc_html__( 'View menu', 'mainwp-child' ) => '%MenuUrl%' );

            self::$ws_al_built_links['PostUrl'] = array( esc_html__( 'URL', 'mainwp-child' ) => '%PostUrl%' );

            self::$ws_al_built_links['AttachmentUrl'] = array( esc_html__( 'View attachment page', 'mainwp-child' ) => '%AttachmentUrl%' );

            self::$ws_al_built_links['PostUrlIfPlublished'] = array( esc_html__( 'URL', 'mainwp-child' ) => '%PostUrlIfPlublished%' );

            self::$ws_al_built_links['PostUrlIfPublished'] = array( esc_html__( 'URL', 'mainwp-child' ) => '%PostUrlIfPlublished%' );

            self::$ws_al_built_links['RevisionLink'] = array( esc_html__( 'View the content changes', 'mainwp-child' ) => '%RevisionLink%' );

            self::$ws_al_built_links['TagLink'] = array( esc_html__( 'View tag', 'mainwp-child' ) => '%RevisionLink%' );

            /*
            * All these links are formatted (including any label) because they
            * contain non-trivial HTML markup that includes custom JS. We assume these will only be rendered
            * in the log viewer in WP admin UI.
            */
            self::$ws_al_built_links['LogFileText'] = array( '%LogFileText%' );
            self::$ws_al_built_links['MetaLink']    = array( '%MetaLink%' );

        }

        if ( ! empty( $link_aliases ) ) {
            foreach ( $link_aliases as $link_alias ) {
                if ( array_key_exists( $link_alias, self::$ws_al_built_links ) ) {
                    $result = array_merge( $result, self::$ws_al_built_links[ $link_alias ] );
                }
            }
        }

        return $result;
    }


    /**
     * Get support logs type id.
     *
     * @return array
     */
    public static function support_logs() {
        return \apply_filters(
            'mainwp_child_changes_logs_support_type_ids',
            array(
                // Post.
                2000,
                2001,
                2002,
                2008,
                2012,
                2014,
                2016,
                2017,
                2019,
                2021,
                2047,
                2048,
                2049,
                2050,
                2053,
                2073,
                2074,
                2025,
                2027,
                2065,
                2086,
                2100,
                2101,
                2111,
                2112,
                2119,
                2120,
                2129,
                // xxxx, // Added / changed / removed a post’s excerpt ???.
                2130,
                9043,
                2133,
                // Custom field.
                2131,
                2132,
                2054,
                2055,
                2062,
                // Category.
                2023,
                2024,
                2052,
                2127,
                2128,
                // Tag.
                2119,
                2120,
                2123,
                2124,
                2125,
                // File.
                2010,
                2011,
                // Widget.
                2042,
                2043,
                2044,
                2045,
                2071,
                // Plugin.
                2051,
                5028,
                // Theme.
                2046,
                5029,
                // Menu.
                2078,
                2079,
                2080,
                // Menu
                2081,
                2082,
                2083,
                2084,
                2085,
                2089,
                // Comment.
                2090,
                2091,
                2092,
                2093,
                2094,
                2095,
                2096,
                2097,
                2098,
                2099,
                // User.
                1000,
                1001,
                1005,
                1006,
                1009, // ??? 1007 - Terminated a user session.
                1008,
                1010,
                4000,
                4001,
                4002,
                4003,
                4004,
                4005,
                4006,
                4007,
                4008,
                4009,
                4012,
                4011,
                4010,
                4013,
                4014,
                4015,
                4016,
                4017,
                4018,
                4019,
                4020,
                4021,
                4025,
                4026,
                4028,
                4027,
                // Database.
                5010,
                5011,
                5012,
                5013,
                5014,
                5015,
                5016,
                5017,
                5018,
                // System setting.
                6001,
                6002,
                6003,
                6005,
                6008,
                6009,
                6010,
                6011,
                6012,
                6013,
                6014,
                6015,
                6016,
                6017,
                6018,
                6024,
                6025,
                6035,
                6036,
                6037,
                6040,
                6041,
                6042,
                6044,
                6045,
                6059,
                6063,
                6064,
                // WordPress Cron.
                6066,
                6067,
                6068,
                6069,
                6070,
                6071,
                6072,
            )
        );
    }
}
