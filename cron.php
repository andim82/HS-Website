<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registriert ein eigenes "einmal pro Monat" Cron-Intervall fuer WordPress,
 * da WP standardmaessig nur hourly/twicedaily/daily kennt.
 */
add_filter( 'cron_schedules', 'hs_add_monthly_cron_schedule' );
function hs_add_monthly_cron_schedule( $schedules ) {
	$schedules['hs_monthly'] = [
		'interval' => 30 * DAY_IN_SECONDS,
		'display'  => __( 'Einmal pro Monat (HEIM:SPIEL)', 'heimspiel-data-cache' ),
	];
	return $schedules;
}

function hs_schedule_cron() {
	if ( ! wp_next_scheduled( HS_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'hs_monthly', HS_CRON_HOOK );
	}
}

function hs_unschedule_cron() {
	$timestamp = wp_next_scheduled( HS_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, HS_CRON_HOOK );
	}
}

add_action( HS_CRON_HOOK, 'hs_refresh_all_cache' );
