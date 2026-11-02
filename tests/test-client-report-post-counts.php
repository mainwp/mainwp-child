<?php
/**
 * Pro Reports post count tokens must count only their own post type.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

/**
 * Covers the [post.*.count] and [page.*.count] tokens in MainWP_Client_Report_Base.
 */
class Test_MainWP_Client_Report_Post_Counts extends WP_UnitTestCase {

    /**
     * Report helper under test.
     *
     * @var MainWP_Client_Report_Base
     */
    private $report;

    /**
     * Next fake record ID.
     *
     * @var int
     */
    private $next_id = 1;

    /**
     * Starts each test with a fresh helper and no cached spam list.
     */
    public function setUp(): void {
        parent::setUp();
        $this->report                              = new MainWP_Client_Report_Base();
        MainWP_Client_Report_Base::$records_cached = array();
        $this->next_id                             = 1;
    }

    /**
     * Builds a Child Reports record as the posts connector stores it.
     *
     * @param string $context Post type the record belongs to.
     * @param string $action  Logged action.
     * @param string $title   Post title.
     *
     * @return \stdClass
     */
    private function record( $context, $action, $title ) {
        $id = $this->next_id++;
        return (object) array(
            'ID'        => $id,
            'object_id' => 100 + $id,
            'connector' => 'posts',
            'context'   => $context,
            'action'    => $action,
            'created'   => '2026-10-15 12:00:00',
            'summary'   => $title,
            'meta'      => array(
                'post_title' => array( $title ),
                'new_status' => array( 'publish' ),
            ),
        );
    }

    /**
     * The period from the ticket: 3 new posts, 4 new pages, plus one product.
     *
     * @return array
     */
    private function ticket_records() {
        $records = array();
        foreach ( array( 'Fall Harvest Menu', 'Taco Tuesdays', 'Holiday Party' ) as $title ) {
            $records[] = $this->record( 'post', 'created', $title );
        }
        foreach ( array( 'Menu', 'Privacy Policy', 'Terms of Service', 'Refund Policy' ) as $title ) {
            $records[] = $this->record( 'page', 'created', $title );
        }
        $records[] = $this->record( 'product', 'created', 'Gift Card' );
        return $records;
    }

    /**
     * Resolves one count token.
     *
     * @param array  $records Report records.
     * @param string $token   Count token.
     * @param array  $skip    Records to skip.
     *
     * @return int
     */
    private function count_token( $records, $token, $skip = array() ) {
        $values = $this->report->get_other_tokens_data( $records, array( $token ), $skip );
        return $values[ $token ];
    }

    /**
     * Counts the rows the matching section token lists.
     *
     * @param array  $records Report records.
     * @param string $section Section token.
     *
     * @return int
     */
    private function section_rows( $records, $section ) {
        return count( $this->report->get_section_loop_data( $records, array( '[post.title]' ), $section ) );
    }

    /**
     * New pages and custom post types must not count as new posts.
     */
    public function test_post_created_count_excludes_pages_and_custom_post_types() {
        $records = $this->ticket_records();

        $this->assertSame( 3, $this->count_token( $records, '[post.created.count]' ) );
        $this->assertSame( 4, $this->count_token( $records, '[page.created.count]' ) );
    }

    /**
     * Each summary count must equal the number of rows its section lists.
     *
     * @dataProvider action_provider
     *
     * @param string $action Token action.
     * @param string $logged Action as Child Reports stores it.
     */
    public function test_counts_match_section_lists( $action, $logged ) {
        $records = array();
        foreach ( array( 'post', 'post', 'post', 'page', 'page', 'page', 'page', 'product' ) as $i => $context ) {
            $records[] = $this->record( $context, $logged, $context . ' ' . $i );
        }

        foreach ( array( 'post' => 3, 'page' => 4 ) as $type => $expected ) {
            $count = $this->count_token( $records, '[' . $type . '.' . $action . '.count]' );
            $rows  = $this->section_rows( $records, '[section.' . $type . 's.' . $action . ']' );

            $this->assertSame( $expected, $count, "[{$type}.{$action}.count]" );
            $this->assertSame( $rows, $count, "[{$type}.{$action}.count] vs [section.{$type}s.{$action}]" );
        }
    }

    /**
     * Post and page token actions with the action Child Reports logs.
     *
     * @return array
     */
    public static function action_provider() {
        return array(
            'created'  => array( 'created', 'created' ),
            'updated'  => array( 'updated', 'updated' ),
            'trashed'  => array( 'trashed', 'trashed' ),
            'restored' => array( 'restored', 'untrashed' ),
            'deleted'  => array( 'deleted', 'deleted' ),
        );
    }

    /**
     * Records already claimed by another token stay out of the new-post count.
     */
    public function test_post_created_count_still_honors_skip_records() {
        $records = $this->ticket_records();

        $this->assertSame( 2, $this->count_token( $records, '[post.created.count]', array( $records[0]->ID ) ) );
    }
}
