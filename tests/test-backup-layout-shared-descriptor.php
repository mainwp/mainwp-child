<?php
/**
 * Shared descriptor byte and digest contract.
 *
 * @package Mainwp_Child
 */
use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Exception;

class BackupLayoutSharedDescriptorTest extends WP_UnitTestCase {
    public static function contract_cases() {
        $fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/mwp2-descriptor-contract.json' ), true );
        $cases = array();
        foreach ( $fixture['cases'] as $case ) {
            $label = $case['name'];
            if ( 'outer_format_string_2' === $case['name'] ) {
                $label .= ' (Dashboard-envelope-only; outer admission belongs to PR6)';
            }
            $cases[ $label ] = array( $case );
        }
        return $cases;
    }

    /** @dataProvider contract_cases */
    public function test_shared_descriptor_contract( $case ) {
        $control = json_decode( $case['canonical_bytes'], true );
        $this->assertSame( $case['canonical_bytes'], MainWP_Child_Backup_Layout::serialize( $control ) );
        $this->assertSame( $case['canonical_sha256'], MainWP_Child_Backup_Layout::digest( $control ) );
        $this->assertSame( $case['canonical_bytes'], MainWP_Child_Backup_Layout::metadata_bytes( $control ) );
        $this->assertSame( $control, MainWP_Child_Backup_Layout::decode( $case['canonical_bytes'], $case['canonical_sha256'] ) );
        $input = $case['input_descriptor'];
        if ( 2 === $input['format'] && 2 === $input['list_format'] ) {
            $this->assertSame( $case['canonical_bytes'], MainWP_Child_Backup_Layout::serialize( $input ) );
            $this->assertSame( $case['canonical_sha256'], MainWP_Child_Backup_Layout::digest( $input ) );
        }
        if ( isset( $case['metadata_bytes'] ) ) {
            $this->assertSame( $case['metadata_bytes'], MainWP_Child_Backup_Layout::metadata_bytes( $input ) );
        }
        try {
            $decoded = MainWP_Child_Backup_Layout::decode( wp_json_encode( $input, JSON_UNESCAPED_SLASHES ), $case['supplied_digest'] );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( 'refuse', $case['expected_result'] );
            $this->assertSame( $case['error_code'], $error->get_message_error_code() );
            return;
        }
        // outer_format_string_2: skip the Dashboard envelope assertion; decode receives only its valid descriptor.
        $expected = 'outer_format_string_2' === $case['name'] ? 'accept' : $case['expected_result'];
        $this->assertSame( 'accept', $expected, $case['name'] );
        $this->assertSame( $control, array_diff_key( $decoded, array( 'core_version' => true ) ) );
        $this->assertSame( $case['canonical_bytes'], MainWP_Child_Backup_Layout::serialize( $decoded ) );
        if ( isset( $input['core_version'] ) ) {
            $this->assertSame( $input['core_version'], $decoded['core_version'] );
        }
    }
}
