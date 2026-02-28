<?php

namespace WCPOS\WooCommercePOS\Vipps;

class Logger {

    public static function log( $message ) {
        if ( ! apply_filters( 'wcpos_vipps_logging', true ) ) {
            return;
        }

        if ( is_array( $message ) || is_object( $message ) ) {
            $message = print_r( $message, true );
        }

        $logger = wc_get_logger();
        $logger->info( $message, array( 'source' => 'wcpos-vipps' ) );
    }
}
