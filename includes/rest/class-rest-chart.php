<?php
namespace Mors\Rest;

use Mors\Db\Editions_Repo;
use Mors\Db\Entries_Repo;
use Mors\Domain\Serializer;

/**
 * Publiczne endpointy listy przebojów: GET /chart/current, GET /chart/waiting-room.
 * Kształt odpowiedzi jest zgodny z SPA (app/public/app.js), NIE z brief.md.
 */
class Chart {

    public function register() {
        register_rest_route( 'mors/v1', '/chart/current', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'current' ],
        ] );
        register_rest_route( 'mors/v1', '/chart/waiting-room', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'waiting' ],
        ] );
    }

    private function no_active_edition() {
        return new \WP_REST_Response(
            [ 'success' => false, 'message' => 'Brak aktywnego notowania.' ], 404 );
    }

    public function current() {
        $ed = ( new Editions_Repo() )->current();
        if ( ! $ed ) {
            return $this->no_active_edition();
        }
        $entries = new Entries_Repo();
        $chartRows = $entries->chart_by_position( $ed['id'] );
        $totalVotes = $entries->total_votes( $ed['id'] );

        $payload = [
            'success' => true,
            'config'  => \mors_get_config(),
            'edition' => [
                'id'              => $ed['id'],
                'number'          => isset( $ed['edition_number'] ) ? (int) $ed['edition_number'] : null,
                'title'           => isset( $ed['title'] ) ? $ed['title'] : null,
                'endsAt'          => isset( $ed['voting_ends_at'] ) ? \mors_to_iso8601( $ed['voting_ends_at'] ) : null,
                'status'          => isset( $ed['status'] ) ? $ed['status'] : null,
                'totalVotesCount' => $totalVotes,
                'onlineListeners' => 300 + wp_rand( 0, 89 ),
            ],
            'chartTracks' => array_map( [ Serializer::class, 'chart_entry' ], $chartRows ),
        ];
        return new \WP_REST_Response( $payload, 200 );
    }

    public function waiting() {
        $ed = ( new Editions_Repo() )->current();
        if ( ! $ed ) {
            return $this->no_active_edition();
        }
        $rows = ( new Entries_Repo() )->for_edition( $ed['id'], true );
        $payload = [
            'success'            => true,
            'config'             => \mors_get_config(),
            'waitingRoomTracks'  => array_map( [ Serializer::class, 'waiting_entry' ], $rows ),
        ];
        return new \WP_REST_Response( $payload, 200 );
    }
}
