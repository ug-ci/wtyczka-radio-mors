<?php
namespace Mors\Domain;

/**
 * Kształtuje wiersze z Entries_Repo::for_edition() (i rekord edycji z Editions_Repo)
 * w JSON zgodny z frontendem SPA. Odpowiednik oryginalnego
 * app/src/services/chartSerializer.js — patrz tam po autorytatywny kształt.
 */
class Serializer {

    const COVER_PALETTE = [ '#0041d2', '#032c73', '#00214d' ];

    /** Odpowiednik coverBgFor() z chartSerializer.js: deterministyczny kolor tła okładki z hasha track_id. */
    public static function cover_bg_for( $track_id ) {
        $hash = 0;
        $len  = strlen( (string) $track_id );
        for ( $i = 0; $i < $len; $i++ ) {
            $hash = ( $hash * 31 + ord( $track_id[ $i ] ) ) & 0xFFFFFFFF;
        }
        $count = count( self::COVER_PALETTE );
        return self::COVER_PALETTE[ $hash % $count ];
    }

    /** Odpowiednik formatDuration() z chartSerializer.js: "m:ss". */
    public static function format_duration( $seconds ) {
        $seconds = (int) $seconds;
        $m = intdiv( $seconds, 60 );
        $s = $seconds % 60;
        return $m . ':' . str_pad( (string) $s, 2, '0', STR_PAD_LEFT );
    }

    /**
     * Wpis listy przebojów. Odpowiednik serializeChartEntry() z chartSerializer.js.
     * $r to płaski, złączony wiersz z Entries_Repo::for_edition() (kolumny entries + tracks).
     */
    public static function chart_entry( array $r ) {
        return [
            'id'         => $r['id'],
            'trackId'    => $r['track_id'],
            'position'   => isset( $r['position'] ) ? (int) $r['position'] : null,
            'prevPosition' => isset( $r['previous_position'] ) ? (int) $r['previous_position'] : null,
            'trend'      => $r['trend'],
            'weeks'      => (int) $r['weeks_on_chart'],
            'peak'       => isset( $r['peak_position'] ) ? (int) $r['peak_position'] : null,
            'title'      => $r['title'],
            'artist'     => $r['artist'],
            'album'      => $r['album'],
            'duration'   => self::format_duration( $r['duration_seconds'] ),
            'votes'      => (int) $r['votes_count'],
            'coverBg'    => self::cover_bg_for( $r['track_id'] ),
            'coverImage' => ! empty( $r['cover_image_url'] ) ? $r['cover_image_url'] : null,
            'audioUrl'   => isset( $r['audio_url'] ) && $r['audio_url'] ? $r['audio_url'] : null,
            'bpm'        => isset( $r['bpm'] ) ? (int) $r['bpm'] : null,
            'genre'      => $r['genre'],
            'audioKey'   => ! empty( $r['audio_key'] ) ? $r['audio_key'] : 'synth_chill',
            'isChart'    => empty( $r['is_waiting'] ),
        ];
    }

    /**
     * Wpis poczekalni. Odpowiednik serializeWaitingEntry() z chartSerializer.js.
     * $r to płaski, złączony wiersz z Entries_Repo::for_edition() (kolumny entries + tracks).
     */
    public static function waiting_entry( array $r ) {
        return [
            'id'             => $r['id'],
            'trackId'        => $r['track_id'],
            'title'          => $r['title'],
            'artist'         => $r['artist'],
            'duration'       => self::format_duration( $r['duration_seconds'] ),
            'votes'          => (int) $r['votes_count'],
            'weeksInWaiting' => (int) $r['weeks_on_chart'],
            'coverBg'        => self::cover_bg_for( $r['track_id'] ),
            'coverImage'     => ! empty( $r['cover_image_url'] ) ? $r['cover_image_url'] : null,
            'audioUrl'       => isset( $r['audio_url'] ) && $r['audio_url'] ? $r['audio_url'] : null,
            'tag'            => isset( $r['tag'] ) ? $r['tag'] : null,
            'isChart'        => false,
        ];
    }
}
