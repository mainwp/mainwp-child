<?php

trait Tar_Entry_Fixture {
	protected function tar_entry_bytes( $name, $content = '', $type = '0', $link = '' ) {
		if ( strlen( $name ) > 100 || strlen( $link ) > 100 ) {
			throw new InvalidArgumentException( 'Tar header name or link exceeds its field width.' );
		}
		$header = str_pad( $name, 100, "\0" )
			. sprintf( "%07o\0%07o\0%07o\0%011o\0%011o\0", 0644, 0, 0, strlen( $content ), 0 )
			. '        ' . $type . str_pad( $link, 100, "\0" )
			. "ustar\00000" . str_repeat( "\0", 247 );
		$checksum = array_sum( unpack( 'C*', $header ) );
		$header = substr_replace( $header, sprintf( "%06o\0 ", $checksum ), 148, 8 );
		return $header . str_pad( $content, (int) ( ceil( strlen( $content ) / 512 ) * 512 ), "\0" );
	}
}
