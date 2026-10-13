<?php
namespace Mors\Rest;

use Mors\Auth\Request_Identity;
use Mors\Db\Votes_Repo;

/**
 * Publiczny endpoint statusu głosującego (cooldown). Oryginalny serwer montował
 * router pod obydwoma prefiksami — /voter/status i /votes/status — więc
 * rejestrujemy ten sam handler pod oboma ścieżkami, żeby SPA (które woła
 * /voter/status) i ewentualni starsi klienci (/votes/status) działały tak samo.
 *
 * POST /votes/cast (Task 7) — walidacja + cooldown + rate-limit żyją w
 * Vote_Service / can_cast(); ten kontroler jedynie tłumaczy wynik na REST.
 */
class Votes {

    public function register() {
        register_rest_route( 'mors/v1', '/voter/status', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'status' ],
        ] );
        register_rest_route( 'mors/v1', '/votes/status', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'status' ],
        ] );
        register_rest_route( 'mors/v1', '/votes/cast', [
            'methods'             => 'POST',
            'permission_callback' => [ $this, 'can_cast' ],
            'callback'            => [ $this, 'cast' ],
        ] );
    }

    public function can_cast( $req ) {
        $config = \mors_get_config();
        if ( ! empty( $config['votingSuspended'] ) ) {
            return new \WP_Error( 'mors_suspended', 'Głosowanie jest obecnie czasowo zawieszone. Zapraszamy wkrótce!', [ 'status' => 403 ] );
        }

        // Nonce dla żądania piszącego + rate-limit transportu (30/h per hash).
        $nonce = $req->get_header( 'x_wp_nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( 'mors_bad_nonce', 'Nieprawidłowy token żądania.', [ 'status' => 403 ] );
        }
        $hash  = Request_Identity::voter_hash();
        $key   = 'mors_rl_' . $hash;
        $count = (int) get_transient( $key );
        if ( $count >= 30 ) {
            return new \WP_Error( 'mors_rate', 'Zbyt wiele żądań głosowania. Spróbuj później.', [ 'status' => 429 ] );
        }
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return apply_filters( 'mors_votes_can_cast', true, $req ); // hook Turnstile
    }

    public function cast( $req ) {
        $body     = $req->get_json_params();
        $trackIds = isset( $body['trackIds'] ) && is_array( $body['trackIds'] ) ? $body['trackIds'] : [];
        $trackIds = array_map( 'sanitize_text_field', $trackIds );
        $hash     = Request_Identity::voter_hash();
        $ip       = Request_Identity::client_ip();
        $ua       = sanitize_text_field( $req->get_header( 'user_agent' ) ?: '' );
        try {
            $out = ( new \Mors\Domain\Vote_Service() )->cast( $trackIds, $hash, $ip, $ua );
            return new \WP_REST_Response( $out, 200 );
        } catch ( \Mors\Domain\Vote_Exception $e ) {
            $payload = [ 'success' => false, 'message' => $e->getMessage() ];
            if ( $e->nextEligibleVoteAt ) {
                $payload['nextEligibleVoteAt'] = $e->nextEligibleVoteAt;
            }
            return new \WP_REST_Response( $payload, $e->http );
        }
    }

    public function status() {
        $hash   = Request_Identity::voter_hash();
        $voter  = ( new Votes_Repo() )->find_voter( $hash );
        $config = \mors_get_config();

        $inCooldown = false;
        $next       = null;
        if ( $voter && strtotime( $voter['next_eligible_vote_at'] . ' UTC' ) > time() ) {
            $inCooldown = true;
            $next       = \mors_to_iso8601( $voter['next_eligible_vote_at'] );
        }

        return new \WP_REST_Response( [
            'success'            => true,
            'inCooldown'         => $inCooldown,
            'nextEligibleVoteAt' => $next,
            'votingSuspended'    => ! empty( $config['votingSuspended'] ),
        ], 200 );
    }
}
