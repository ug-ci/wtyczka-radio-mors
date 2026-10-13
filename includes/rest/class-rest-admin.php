<?php
namespace Mors\Rest;

use Mors\Db\Editions_Repo;
use Mors\Db\Entries_Repo;
use Mors\Db\Tracks_Repo;
use Mors\Db\Votes_Repo;
use Mors\Domain\Chart_Engine;

/**
 * Admin REST: lista/upload/edycja/usuwanie utworów panelu redakcji.
 * Kształt odpowiedzi jest portem app/src/routes/admin.js (linie ~60-209),
 * NIE prostszej wersji z brief.md — patrz task-8-brief.md „CRITICAL OVERRIDE”.
 *
 * Każda trasa wymaga nagłówka X-WP-Nonce (akcja 'wp_rest') ORAZ capability
 * mors_edit_music (require_cap jako wspólny permission_callback).
 */
class Admin {

    /** Dozwolone MIME/rozmiary uploadu (Task 12 — hartowanie bezpieczeństwa). */
    const COVER_MIMES     = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
    const AUDIO_MIMES     = [
        'audio/mpeg',
        'audio/mp3',
        'audio/mpg',
        'audio/mp4',
        'audio/ogg',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/flac',
        'audio/x-flac',
    ];
    const COVER_MAX_BYTES = 2 * MB_IN_BYTES;
    const AUDIO_MAX_BYTES = 120 * MB_IN_BYTES;

    public function register() {
        $cap = [ $this, 'require_cap' ];

        register_rest_route( 'mors/v1', '/admin/tracks', [
            'methods'             => 'GET',
            'permission_callback' => $cap,
            'callback'            => [ $this, 'list_tracks' ],
        ] );

        register_rest_route( 'mors/v1', '/admin/tracks/upload', [
            'methods'             => 'POST',
            'permission_callback' => $cap,
            'callback'            => [ $this, 'upload_track' ],
        ] );

        register_rest_route( 'mors/v1', '/admin/tracks/(?P<id>[a-f0-9-]+)', [
            [ 'methods' => 'PUT',    'permission_callback' => $cap, 'callback' => [ $this, 'update_track' ] ],
            [ 'methods' => 'DELETE', 'permission_callback' => $cap, 'callback' => [ $this, 'delete_track' ] ],
        ] );

        register_rest_route( 'mors/v1', '/admin/tracks/(?P<id>[a-f0-9-]+)/move', [
            'methods'             => 'POST',
            'permission_callback' => $cap,
            'callback'            => [ $this, 'move_track' ],
        ] );

        register_rest_route( 'mors/v1', '/admin/chart/freeze', [
            'methods'             => 'POST',
            'permission_callback' => $cap,
            'callback'            => [ $this, 'freeze' ],
        ] );

        register_rest_route( 'mors/v1', '/admin/chart/reset-and-publish', [
            'methods'             => 'POST',
            'permission_callback' => $cap,
            'callback'            => [ $this, 'reset_publish' ],
        ] );

        register_rest_route( 'mors/v1', '/admin/chart/reorder', [
            'methods'             => 'POST',
            'permission_callback' => $cap,
            'callback'            => [ $this, 'reorder' ],
        ] );

        register_rest_route( 'mors/v1', '/admin/settings', [
            [ 'methods' => 'GET',  'permission_callback' => $cap, 'callback' => [ $this, 'get_settings' ] ],
            [ 'methods' => 'POST', 'permission_callback' => $cap, 'callback' => [ $this, 'update_settings' ] ],
        ] );

        $manage = [ $this, 'require_manage' ];

        register_rest_route( 'mors/v1', '/admin/editors', [
            [ 'methods' => 'GET', 'permission_callback' => $manage, 'callback' => [ $this, 'list_editors' ] ],
            [ 'methods' => 'POST', 'permission_callback' => $manage, 'callback' => [ $this, 'add_editor' ] ],
        ] );

        register_rest_route( 'mors/v1', '/admin/editors/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'permission_callback' => $manage,
            'callback'            => [ $this, 'remove_editor' ],
        ] );
    }

    /** Wspólny permission_callback: nonce transportu + capability redakcyjna. */
    public function require_cap( $req ) {
        $nonce = $req->get_header( 'x_wp_nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( 'mors_bad_nonce', 'Nieprawidłowy token żądania.', [ 'status' => 403 ] );
        }
        if ( ! current_user_can( \Mors_Enum::CAP_EDIT_MUSIC ) ) {
            return new \WP_Error( 'mors_forbidden', 'Brak uprawnień.', [ 'status' => 403 ] );
        }
        return true;
    }

    /**
     * GET /admin/tracks — utwory ze statusem CHART lub WAITING_ROOM, najnowsze
     * pierwsze. `section`/`votes` liczone względem wpisu w BIEŻĄCEJ edycji
     * (jeśli istnieje); bez wpisu — wnioskowane ze statusu utworu.
     */
    public function list_tracks( $req ) {
        $rows = ( new Tracks_Repo() )->all();
        $rows = array_values( array_filter( $rows, static function ( $row ) {
            return in_array( $row['status'], [ 'CHART', 'WAITING_ROOM' ], true );
        } ) );

        $entryByTrack = [];
        $edition = ( new Editions_Repo() )->current();
        if ( $edition ) {
            $entriesRepo = new Entries_Repo();
            $all = array_merge(
                $entriesRepo->for_edition( $edition['id'], false ),
                $entriesRepo->for_edition( $edition['id'], true )
            );
            foreach ( $all as $entry ) {
                $entryByTrack[ $entry['track_id'] ] = $entry;
            }
        }

        $tracks = array_map( static function ( $t ) use ( $entryByTrack ) {
            $entry = isset( $entryByTrack[ $t['id'] ] ) ? $entryByTrack[ $t['id'] ] : null;
            if ( $entry ) {
                $section = ( (int) $entry['is_waiting'] === 1 ) ? 'Poczekalnia' : 'Notowanie';
            } else {
                $section = ( $t['status'] === 'CHART' ) ? 'Notowanie' : 'Poczekalnia';
            }
            return [
                'id'            => $t['id'],
                'title'         => $t['title'],
                'artist'        => $t['artist'],
                'album'         => $t['album'],
                'genre'         => $t['genre'],
                'coverImageUrl' => $t['cover_image_url'],
                'status'        => $t['status'],
                'section'       => $section,
                'votes'         => $entry ? (int) $entry['votes_count'] : 0,
                'position'      => ( $entry && $entry['position'] !== null ) ? (int) $entry['position'] : null,
                'weeksOnChart'  => $entry ? (int) $entry['weeks_on_chart'] : (int) $t['total_weeks_on_chart'],
            ];
        }, $rows );

        return new \WP_REST_Response( [ 'success' => true, 'tracks' => $tracks ], 200 );
    }

    /**
     * POST /admin/tracks/upload (multipart) — title, artist, target ('chart'|'waiting'),
     * duration ("m:ss"), opcjonalne pliki cover/audio. Tworzy Track + wpis w
     * bieżącej edycji w jednej transakcji.
     */
    public function upload_track( $req ) {
        $params = $req->get_params();
        $title   = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
        $artist  = isset( $params['artist'] ) ? sanitize_text_field( $params['artist'] ) : '';
        $target  = isset( $params['target'] ) ? sanitize_text_field( $params['target'] ) : '';
        $duration = isset( $params['duration'] ) ? sanitize_text_field( $params['duration'] ) : '';

        if ( ! $title || ! $artist ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'message' => 'Wprowadź tytuł utworu i wykonawcę.' ], 400 );
        }

        $edition = ( new Editions_Repo() )->current();
        if ( ! $edition ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'message' => 'Brak aktywnego notowania.' ], 409 );
        }

        $isChart = ( $target === 'chart' );

        $config = \mors_get_config();
        $limit  = $isChart ? $config['chartSize'] : $config['waitingSize'];
        $count  = ( new Entries_Repo() )->count_for_edition( $edition['id'], ! $isChart );
        if ( $count >= $limit ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => $isChart
                    ? sprintf( 'Główne Notowanie jest już pełne (maksymalnie %d pozycji). Usuń utwór, aby dodać kolejny.', $limit )
                    : sprintf( 'Poczekalnia jest już pełna (maksymalnie %d pozycji). Usuń utwór, aby dodać kolejny.', $limit ),
            ], 409 );
        }

        $data = [
            'title'            => $title,
            'artist'           => $artist,
            'status'           => $isChart ? 'CHART' : 'WAITING_ROOM',
            'duration_seconds' => mors_parse_duration( $duration, 210 ),
        ];

        if ( ! empty( $_FILES['cover'] ) ) {
            $err = $this->validate_upload( 'cover', self::COVER_MIMES, self::COVER_MAX_BYTES );
            if ( is_wp_error( $err ) ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => $err->get_error_message() ], 400 );
            }
        }
        if ( ! empty( $_FILES['audio'] ) ) {
            $err = $this->validate_upload( 'audio', self::AUDIO_MIMES, self::AUDIO_MAX_BYTES );
            if ( is_wp_error( $err ) ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => $err->get_error_message() ], 400 );
            }
        }

        if ( ! empty( $_FILES['cover'] ) || ! empty( $_FILES['audio'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if ( ! empty( $_FILES['cover'] ) ) {
            $cover_id = media_handle_upload( 'cover', 0 );
            if ( ! is_wp_error( $cover_id ) ) {
                $data['cover_image_url'] = wp_get_attachment_url( $cover_id );
            }
        }
        if ( ! empty( $_FILES['audio'] ) ) {
            $audio_id = media_handle_upload( 'audio', 0 );
            if ( ! is_wp_error( $audio_id ) ) {
                $data['audio_url'] = wp_get_attachment_url( $audio_id );
            }
        }

        $tracksRepo  = new Tracks_Repo();
        $entriesRepo = new Entries_Repo();

        $track = $tracksRepo->tx( function () use ( $tracksRepo, $entriesRepo, $data, $edition, $isChart ) {
            $track = $tracksRepo->create( $data );
            if ( $isChart ) {
                $position = $entriesRepo->max_chart_position( $edition['id'] ) + 1;
                $entriesRepo->create( [
                    'edition_id'     => $edition['id'],
                    'track_id'       => $track['id'],
                    'position'       => $position,
                    'is_waiting'     => 0,
                    'trend'          => 'NEW',
                    'weeks_on_chart' => 1,
                ] );
            } else {
                $entriesRepo->create( [
                    'edition_id'     => $edition['id'],
                    'track_id'       => $track['id'],
                    'position'       => null,
                    'is_waiting'     => 1,
                    'trend'          => 'NEW',
                    'weeks_on_chart' => 1,
                    'tag'            => 'Dodany przez redakcję',
                ] );
            }
            return $track;
        } );

        ( new Votes_Repo() )->log( get_current_user_id(), 'TRACK_UPLOAD', [
            'trackId' => $track['id'], 'title' => $title, 'target' => $target,
        ] );

        return new \WP_REST_Response( [ 'success' => true, 'track' => $track ], 200 );
    }

    /**
     * Waliduje wgrywany plik ($_FILES[$field]) zanim trafi do media_handle_upload():
     * rozmiar w granicach $max_bytes oraz rzeczywisty MIME (sprawdzony przez
     * wp_check_filetype_and_ext — nie ufamy samemu nagłówkowi $_FILES['type']
     * przysłanemu przez klienta) na białej liście $allowed_mimes.
     */
    private function validate_upload( $field, array $allowed_mimes, $max_bytes ) {
        if ( empty( $_FILES[ $field ]['tmp_name'] ) ) {
            return true;
        }
        $file = $_FILES[ $field ];
        if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
            return new \WP_Error( 'mors_upload_error', 'Błąd przesyłania pliku.' );
        }
        if ( (int) $file['size'] > $max_bytes ) {
            return new \WP_Error( 'mors_upload_too_large', 'Plik jest zbyt duży.' );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        $mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : '';
        if ( ! $mime || ! in_array( $mime, $allowed_mimes, true ) ) {
            return new \WP_Error( 'mors_upload_bad_type', 'Niedozwolony typ pliku.' );
        }
        return true;
    }

    /** PUT /admin/tracks/{id} — częściowa aktualizacja (tylko obecne klucze). */
    public function update_track( $req ) {
        $id   = $req['id'];
        $repo = new Tracks_Repo();
        if ( ! $repo->find( $id ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Utwór nie istnieje.' ], 404 );
        }

        $params = $req->get_params();
        $data = [];
        foreach ( [ 'title', 'artist', 'album', 'genre' ] as $f ) {
            if ( array_key_exists( $f, $params ) ) {
                $data[ $f ] = sanitize_text_field( $params[ $f ] );
            }
        }
        if ( array_key_exists( 'bpm', $params ) ) {
            $data['bpm'] = ( $params['bpm'] === null || $params['bpm'] === '' ) ? null : (int) $params['bpm'];
        }
        if ( array_key_exists( 'durationSeconds', $params ) ) {
            $data['duration_seconds'] = ( $params['durationSeconds'] === null || $params['durationSeconds'] === '' )
                ? null : (int) $params['durationSeconds'];
        }

        $repo->update( $id, $data );
        ( new Votes_Repo() )->log( get_current_user_id(), 'TRACK_UPDATE', [ 'trackId' => $id ] );

        return new \WP_REST_Response( [ 'success' => true, 'track' => $repo->find( $id ) ], 200 );
    }

    /**
     * POST /admin/tracks/{id}/move — ręczna zmiana sekcji utworu w bieżącej edycji
     * (body: target = 'chart' | 'waiting'). Do notowania trafia na koniec listy;
     * z notowania do poczekalni — pozycje pozostałych są zagęszczane.
     */
    public function move_track( $req ) {
        $id     = $req['id'];
        $body   = $req->get_json_params();
        $target = isset( $body['target'] ) ? sanitize_text_field( $body['target'] ) : '';
        if ( ! in_array( $target, [ 'chart', 'waiting' ], true ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Nieprawidłowy cel przeniesienia.' ], 400 );
        }

        $tracksRepo = new Tracks_Repo();
        if ( ! $tracksRepo->find( $id ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Utwór nie istnieje.' ], 404 );
        }
        $edition = ( new Editions_Repo() )->current();
        if ( ! $edition ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Brak aktywnego notowania.' ], 409 );
        }
        $entriesRepo = new Entries_Repo();
        $entry       = $entriesRepo->find_for_track( $edition['id'], $id );
        if ( ! $entry ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Utwór nie bierze udziału w bieżącym notowaniu.' ], 409 );
        }

        $toChart = ( $target === 'chart' );
        if ( $toChart === ( (int) $entry['is_waiting'] === 0 ) ) {
            return new \WP_REST_Response( [ 'success' => true, 'unchanged' => true ], 200 );
        }

        if ( $toChart ) {
            $limit = \mors_get_config()['chartSize'];
            if ( $entriesRepo->count_for_edition( $edition['id'], false ) >= $limit ) {
                return new \WP_REST_Response( [
                    'success' => false,
                    'message' => sprintf( 'Główne Notowanie jest już pełne (maksymalnie %d pozycji). Najpierw przenieś inny utwór do poczekalni.', $limit ),
                ], 409 );
            }
        } else {
            $limit = \mors_get_config()['waitingSize'];
            if ( $entriesRepo->count_for_edition( $edition['id'], true ) >= $limit ) {
                return new \WP_REST_Response( [
                    'success' => false,
                    'message' => sprintf( 'Poczekalnia jest już pełna (maksymalnie %d pozycji). Najpierw usuń lub przenieś inny utwór.', $limit ),
                ], 409 );
            }
        }

        $tracksRepo->tx( function () use ( $tracksRepo, $entriesRepo, $edition, $entry, $id, $toChart ) {
            $entriesRepo->update( $entry['id'], $toChart
                ? [ 'is_waiting' => 0, 'position' => $entriesRepo->max_chart_position( $edition['id'] ) + 1, 'tag' => null ]
                : [ 'is_waiting' => 1, 'position' => null ] );
            if ( ! $toChart ) {
                $entriesRepo->compact_chart_positions( $edition['id'] );
            }
            $tracksRepo->update( $id, [ 'status' => $toChart ? 'CHART' : 'WAITING_ROOM' ] );
        } );

        ( new Votes_Repo() )->log( get_current_user_id(), 'TRACK_MOVE', [ 'trackId' => $id, 'target' => $target ] );

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /** DELETE /admin/tracks/{id} — kaskadowe usunięcie (wpisy + głosy + utwór). */
    public function delete_track( $req ) {
        $id   = $req['id'];
        $repo = new Tracks_Repo();
        if ( ! $repo->find( $id ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Utwór nie istnieje.' ], 404 );
        }
        $repo->delete( $id );
        ( new Votes_Repo() )->log( get_current_user_id(), 'TRACK_DELETE', [ 'trackId' => $id ] );

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /** POST /admin/chart/freeze — zamraża bieżące notowanie (status -> FROZEN). */
    public function freeze( $req ) {
        try {
            $out = ( new Chart_Engine() )->freeze( get_current_user_id() );
            return new \WP_REST_Response( $out, 200 );
        } catch ( \RuntimeException $e ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 409 );
        }
    }

    /** POST /admin/chart/reset-and-publish — reset + publikacja nowego wydania. */
    public function reset_publish( $req ) {
        try {
            $out = ( new Chart_Engine() )->reset_and_publish( get_current_user_id() );
            return new \WP_REST_Response( $out, 200 );
        } catch ( \RuntimeException $e ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 409 );
        }
    }

    /** POST /admin/chart/reorder — zapis nowej kolejności notowania (drag & drop). Body: { order: [trackId,...] }. */
    public function reorder( $req ) {
        $body  = $req->get_json_params();
        $order = ( isset( $body['order'] ) && is_array( $body['order'] ) )
            ? array_map( 'sanitize_text_field', $body['order'] )
            : [];
        if ( empty( $order ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Brak kolejności do zapisania.' ], 400 );
        }
        try {
            $out = ( new Chart_Engine() )->reorder_chart( get_current_user_id(), $order );
            return new \WP_REST_Response( $out, 200 );
        } catch ( \RuntimeException $e ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 409 );
        }
    }

    /**
     * permission_callback dla /admin/editors/* — nonce transportu + capability
     * mors_manage_editors (przyznana administratorom w Activatorze). Odrębna od
     * require_cap(), bo zarządzanie redaktorami to wyższy poziom uprawnień niż
     * zwykła edycja muzyki.
     */
    public function require_manage( $req ) {
        $nonce = $req->get_header( 'x_wp_nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( 'mors_bad_nonce', 'Nieprawidłowy token żądania.', [ 'status' => 403 ] );
        }
        if ( ! current_user_can( \Mors_Enum::CAP_MANAGE ) ) {
            return new \WP_Error( 'mors_forbidden', 'Brak uprawnień.', [ 'status' => 403 ] );
        }
        return true;
    }

    /**
     * GET /admin/editors — użytkownicy WP z capability mors_edit_music LUB
     * mors_present. "Redaktor" nie jest osobną tabelą — to zwykły WP_User
     * z nadanymi capability (patrz add_editor()/remove_editor()).
     */
    public function list_editors( $req ) {
        $editors = array_values( array_filter( get_users(), static function ( $u ) {
            return user_can( $u, \Mors_Enum::CAP_EDIT_MUSIC ) || user_can( $u, \Mors_Enum::CAP_PRESENT );
        } ) );

        usort( $editors, static function ( $a, $b ) {
            return strcmp( $a->user_registered, $b->user_registered );
        } );

        $out = array_map( function ( $u ) {
            return $this->serialize_editor( $u );
        }, $editors );

        return new \WP_REST_Response( [ 'success' => true, 'editors' => $out ], 200 );
    }

    /**
     * POST /admin/editors {fullName,email,role} — tworzy nowego użytkownika WP
     * (rola bazowa 'subscriber') i nadaje mu capability wg roli redakcyjnej.
     * Zwraca jednorazowe hasło tymczasowe (tempPassword) — nie jest nigdzie
     * zapisywane w postaci jawnej poza tą jedną odpowiedzią.
     */
    public function add_editor( $req ) {
        $params   = $req->get_params();
        $fullName = isset( $params['fullName'] ) ? sanitize_text_field( $params['fullName'] ) : '';
        $email    = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
        $role     = isset( $params['role'] ) ? sanitize_text_field( $params['role'] ) : 'MUSIC_EDITOR';

        if ( ! $fullName || ! $email ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'message' => 'Wprowadź imię, nazwisko i adres e-mail redaktora.' ], 400 );
        }

        if ( get_user_by( 'email', $email ) ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'message' => 'Redaktor z tym adresem e-mail już istnieje.' ], 409 );
        }

        $tempPassword = wp_generate_password( 16 );
        $user_id      = wp_insert_user( [
            'user_login'   => $this->derive_login( $email ),
            'user_email'   => $email,
            'display_name' => $fullName,
            'user_pass'    => $tempPassword,
            'role'         => 'subscriber',
        ] );

        if ( is_wp_error( $user_id ) ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'message' => 'Nie udało się utworzyć redaktora.' ], 500 );
        }

        $user = get_user_by( 'id', $user_id );

        // SUPER_ADMIN nie jest tworzony przez ten endpoint — nieznana rola traktowana jak MUSIC_EDITOR.
        if ( $role === 'PRESENTER' ) {
            $user->add_cap( \Mors_Enum::CAP_PRESENT );
        } else {
            $role = 'MUSIC_EDITOR';
            $user->add_cap( \Mors_Enum::CAP_EDIT_MUSIC );
            $user->add_cap( \Mors_Enum::CAP_PRESENT );
        }

        ( new Votes_Repo() )->log( get_current_user_id(), 'EDITOR_CREATE', [
            'userId' => $user->ID, 'email' => $email,
        ] );

        return new \WP_REST_Response( [
            'success'      => true,
            'editor'       => $this->serialize_editor( $user, $role ),
            'tempPassword' => $tempPassword,
        ], 200 );
    }

    /**
     * DELETE /admin/editors/{id} — odbiera capability redakcyjne. Konto WP
     * pozostaje (nie usuwamy użytkownika), zgodnie z kontraktem zadania.
     */
    public function remove_editor( $req ) {
        $user = get_user_by( 'id', (int) $req['id'] );
        if ( ! $user ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'Nie znaleziono użytkownika.' ], 404 );
        }

        $user->remove_cap( \Mors_Enum::CAP_EDIT_MUSIC );
        $user->remove_cap( \Mors_Enum::CAP_PRESENT );

        ( new Votes_Repo() )->log( get_current_user_id(), 'EDITOR_REMOVE', [ 'userId' => $user->ID ] );

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /** Kształt {id,email,fullName,role,isActive,createdAt} współdzielony przez GET/POST. */
    private function serialize_editor( \WP_User $u, $role = null ) {
        if ( $role === null ) {
            $role = user_can( $u, \Mors_Enum::CAP_EDIT_MUSIC ) ? 'MUSIC_EDITOR' : 'PRESENTER';
        }
        return [
            'id'        => $u->ID,
            'email'     => $u->user_email,
            'fullName'  => $u->display_name,
            'role'      => $role,
            'isActive'  => true,
            'createdAt' => \mors_to_iso8601( $u->user_registered ),
        ];
    }

    /** Wyprowadza unikalny user_login z lokalnej części adresu e-mail. */
    private function derive_login( $email ) {
        $base = sanitize_user( current( explode( '@', $email ) ), true );
        if ( ! $base ) {
            $base = 'redaktor';
        }
        $login = $base;
        $i     = 1;
        while ( username_exists( $login ) ) {
            $login = $base . $i;
            $i++;
        }
        return $login;
    }

    /** GET /admin/settings — zwraca bieżącą konfigurację rozmiarów listy oraz numer notowania. */
    public function get_settings( $req ) {
        $ed = ( new Editions_Repo() )->current();
        return new \WP_REST_Response( [
            'success'       => true,
            'config'        => \mors_get_config(),
            'editionNumber' => $ed ? (int) $ed['edition_number'] : null,
        ], 200 );
    }

    /** POST /admin/settings — aktualizuje ustawienia rozmiarów listy, poczekalni, limitu głosów oraz numeru notowania. */
    public function update_settings( $req ) {
        $body = $req->get_json_params();
        if ( empty( $body ) ) {
            $body = $req->get_params();
        }

        $chart_size       = isset( $body['chartSize'] ) ? (int) $body['chartSize'] : (int) get_option( 'mors_chart_size', 25 );
        $waiting_size     = isset( $body['waitingSize'] ) ? (int) $body['waitingSize'] : (int) get_option( 'mors_waiting_size', 35 );
        $promoted_count   = isset( $body['promotedCount'] ) ? (int) $body['promotedCount'] : (int) get_option( 'mors_promoted_count', 2 );
        $max_votes        = isset( $body['maxVotes'] ) ? (int) $body['maxVotes'] : (int) get_option( 'mors_max_votes_per_voter', 15 );
        $voting_suspended = isset( $body['votingSuspended'] ) ? (bool) $body['votingSuspended'] : (bool) get_option( 'mors_voting_suspended', false );

        if ( $chart_size < 1 || $chart_size > 100 ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Liczba pozycji w notowaniu musi być w przedziale 1–100.',
            ], 400 );
        }

        if ( $waiting_size < 1 || $waiting_size > 200 ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Liczba pozycji w poczekalni musi być w przedziale 1–200.',
            ], 400 );
        }

        if ( $promoted_count < 0 || $promoted_count > $chart_size ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Liczba utworów awansujących nie może przekraczać rozmiaru notowania głównego.',
            ], 400 );
        }

        if ( $max_votes < 1 || $max_votes > 50 ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Maksymalna liczba głosów słuchacza musi być w przedziale 1–50.',
            ], 400 );
        }

        $edRepo    = new Editions_Repo();
        $currentEd = $edRepo->current();

        if ( isset( $body['editionNumber'] ) ) {
            $edition_number = (int) $body['editionNumber'];
            if ( $edition_number < 1 ) {
                return new \WP_REST_Response( [
                    'success' => false,
                    'message' => 'Numer notowania musi być liczbą całkowitą większą od zera.',
                ], 400 );
            }

            if ( $currentEd ) {
                if ( (int) $currentEd['edition_number'] !== $edition_number ) {
                    $existing = $edRepo->find_by_number( $edition_number );
                    if ( $existing && $existing['id'] !== $currentEd['id'] ) {
                        return new \WP_REST_Response( [
                            'success' => false,
                            'message' => sprintf( 'Notowanie o numerze #%d już istnieje w bazie.', $edition_number ),
                        ], 400 );
                    }

                    $edRepo->update( $currentEd['id'], [
                        'edition_number' => $edition_number,
                        'title'          => "Notowanie {$edition_number} • Wydanie Główne",
                    ] );

                    ( new Votes_Repo() )->log( get_current_user_id(), 'EDITION_NUMBER_UPDATE', [
                        'oldNumber' => $currentEd['edition_number'],
                        'newNumber' => $edition_number,
                    ] );
                }
            } else {
                $now  = current_time( 'mysql', true );
                $ends = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
                $edRepo->create( [
                    'edition_number'   => $edition_number,
                    'title'            => "Notowanie {$edition_number} • Wydanie Główne",
                    'voting_starts_at' => $now,
                    'voting_ends_at'   => $ends,
                    'status'           => 'ACTIVE',
                    'is_current'       => 1,
                ] );
            }
        }

        update_option( 'mors_chart_size', $chart_size );
        update_option( 'mors_waiting_size', $waiting_size );
        update_option( 'mors_promoted_count', $promoted_count );
        update_option( 'mors_max_votes_per_voter', $max_votes );
        update_option( 'mors_voting_suspended', $voting_suspended );

        $logMeta = [
            'chartSize'       => $chart_size,
            'waitingSize'     => $waiting_size,
            'promotedCount'   => $promoted_count,
            'maxVotes'        => $max_votes,
            'votingSuspended' => $voting_suspended,
        ];
        if ( isset( $edition_number ) ) {
            $logMeta['editionNumber'] = $edition_number;
        }

        ( new Votes_Repo() )->log( get_current_user_id(), 'SETTINGS_UPDATE', $logMeta );

        $updatedEd = $edRepo->current();

        return new \WP_REST_Response( [
            'success' => true,
            'message' => 'Ustawienia zostały pomyślnie zapisane.',
            'config'  => \mors_get_config(),
            'edition' => $updatedEd ? [
                'id'              => $updatedEd['id'],
                'number'          => (int) $updatedEd['edition_number'],
                'title'           => $updatedEd['title'],
                'endsAt'          => \mors_to_iso8601( $updatedEd['voting_ends_at'] ),
                'status'          => $updatedEd['status'],
                'totalVotesCount' => ( new Entries_Repo() )->total_votes( $updatedEd['id'] ),
                'onlineListeners' => 300 + wp_rand( 0, 89 ),
            ] : null,
        ], 200 );
    }
}

