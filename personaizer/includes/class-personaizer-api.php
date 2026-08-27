<?php
/**
 * Thin HTTP client for the PERSONAIZER knowledge API.
 *
 * Uses the persona's SECRET key (pa_…) in the X-Api-Key header — this is
 * server-side only and must never be printed into a page (unlike the public
 * Persona ID that drives the widget).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Base URL of the PERSONAIZER API (Core). Defaults to PRODUCTION.
 * Override in wp-config.php for dev/local testing (before plugins load):
 *   define( 'PERSONAIZER_API_URL', 'https://dev-api.personaizer.com' );
 */
if ( ! defined( 'PERSONAIZER_API_URL' ) ) {
    define( 'PERSONAIZER_API_URL', 'https://api.personaizer.com' );
}

class Personaizer_Api {

    /** @return string|null the secret pa_ key, or null when not configured. */
    private function secret_key() {
        $key = trim( (string) get_option( 'personaizer_secret_key', '' ) );
        return $key !== '' ? $key : null;
    }

    public function is_configured() {
        return $this->secret_key() !== null;
    }

    private function base() {
        return rtrim( PERSONAIZER_API_URL, '/' );
    }

    /**
     * The connected persona's public display info (name + avatar), so the admin screen can say
     * "Ana is live on your site" instead of printing a GUID at the owner. Identified by the PUBLIC
     * Persona ID — no secret key involved, which is why this works even before a sync key exists.
     *
     * Cached, because this runs on every admin page view — but for how long depends on what came
     * back. A persona still named after the domain is mid-build (the onboarding job renames it to the
     * brand when it finishes), and the admin screen polls for exactly that moment; a 5-minute cache
     * would leave it insisting "writing its personality" for minutes after it was done. So a
     * placeholder is held briefly and a finished persona is held long — the poll converges, and a
     * settled site still costs one request per 5 minutes.
     *
     * @return array{name:string,avatar_url:string}|null null when unconfigured or unreachable.
     */
    public function get_profile() {
        $persona_id = trim( (string) get_option( 'personaizer_persona_id', '' ) );
        if ( $persona_id === '' ) return null;

        $key    = 'personaizer_profile_' . md5( $persona_id . '|' . $this->base() );
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return is_array( $cached ) ? $cached : null;
        }

        $response = wp_remote_get( $this->base() . '/v1/persona/profile', [
            'timeout' => 8,
            'headers' => [ 'X-Persona-Id' => $persona_id ],
        ] );

        $profile = null;
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( is_array( $data ) && ! empty( $data['name'] ) ) {
                $profile = [
                    'name'       => (string) $data['name'],
                    // The /v1 surface is snake_case (it has no camel->snake interceptor of its own).
                    'avatar_url' => isset( $data['avatar_url'] ) ? (string) $data['avatar_url'] : '',
                    // The server's own answer to "is this persona finished, and what's it doing?" —
                    // not ours to infer. See profile_ttl(): it also decides how long to trust this.
                    'building'   => ! empty( $data['building'] ),
                    'stage'      => isset( $data['build_stage'] ) ? (string) $data['build_stage'] : '',
                ];
            }
        }
        set_transient( $key, $profile === null ? 'miss' : $profile, self::profile_ttl( $profile ) );
        return $profile;
    }

    /**
     * Seconds to trust this answer for.
     *
     * Short while the server says the persona is still being built, because during that window nearly
     * everything about it changes — and the avatar in particular arrives a stage AFTER the name does.
     * Caching a mid-build answer for minutes is how you end up showing a nameless, pictureless persona
     * long after it's finished.
     */
    private static function profile_ttl( $profile ) {
        if ( $profile === null ) return 5 * MINUTE_IN_SECONDS;
        return ! empty( $profile['building'] ) ? 5 : 5 * MINUTE_IN_SECONDS;
    }

    /** Drop the cached profile — call after (re)connecting to a different persona. */
    public static function forget_profile( $persona_id, $base ) {
        delete_transient( 'personaizer_profile_' . md5( $persona_id . '|' . rtrim( $base, '/' ) ) );
    }

    /**
     * The account's knowledge-unit budget: how much the plan allows, how much is used, and the plan's
     * name — enough to tell the owner "you've hit your Free plan's limit, upgrade" and to gate the
     * after-upgrade catch-up on real headroom before it replays anything.
     *
     * Read with the SECRET key against /api/subscription/limits — a public-surface endpoint that accepts
     * a persona key and resolves the owning account from it, so no user login is involved. Cached briefly:
     * it's consulted on every settings-page render and by the daily catch-up, and a plan's ceiling doesn't
     * move minute to minute.
     *
     * @param bool $force Skip the cache and read live — used by the after-upgrade catch-up, which runs
     *                    rarely and must not act on a stale "full" reading from just before the upgrade.
     * @return array{ku_used:float,ku_limit:?float,plan_slug:string,plan_name:string}|null
     *         null when unconfigured or unreachable — a caller must read that as "don't know", never "0".
     */
    public function get_limits( $force = false ) {
        $key = $this->secret_key();
        if ( $key === null ) return null;

        $cache = 'personaizer_limits_' . md5( $key . '|' . $this->base() );
        if ( ! $force ) {
            $hit = get_transient( $cache );
            if ( $hit !== false ) {
                return is_array( $hit ) ? $hit : null;
            }
        }

        $response = wp_remote_get(
            $this->base() . '/api/subscription/limits',
            [ 'timeout' => 10, 'headers' => [ 'X-Api-Key' => $key ] ]
        );

        $limits = null;
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            // The /api surface is snake_case on the wire (its own clients rely on a camel->snake
            // interceptor the plugin doesn't have) — read snake_case keys, as Connect does.
            $data  = json_decode( wp_remote_retrieve_body( $response ), true );
            $usage = ( is_array( $data ) && isset( $data['usage'] ) && is_array( $data['usage'] ) ) ? $data['usage'] : null;
            $plan  = ( is_array( $data ) && isset( $data['plan'] ) && is_array( $data['plan'] ) ) ? $data['plan'] : array();
            if ( $usage !== null ) {
                $limits = array(
                    'ku_used'   => (float) ( $usage['knowledge_units_used'] ?? 0 ),
                    // null (not 0) = unlimited — an absent/blank ceiling must never read as "no room".
                    'ku_limit'  => ( isset( $usage['knowledge_units_limit'] ) && $usage['knowledge_units_limit'] !== null )
                        ? (float) $usage['knowledge_units_limit'] : null,
                    'plan_slug' => (string) ( $plan['slug'] ?? '' ),
                    'plan_name' => (string) ( $plan['name'] ?? '' ),
                );
            }
        }
        // Hold a miss briefly too, so a blip doesn't hammer the endpoint on every admin page view.
        set_transient( $cache, $limits === null ? 'miss' : $limits, 5 * MINUTE_IN_SECONDS );
        return $limits;
    }

    /**
     * Upsert a plain-text / markdown knowledge doc by external id.
     * Idempotent server-side: same id updates in place, identical content is a no-op.
     *
     * @param array $images Image library entries [{url, description, is_primary}]; [] = no images.
     * @return true|WP_Error
     */
    public function upsert_text( $external_id, $title, $source, $markdown, $permalink = '', $images = array() ) {
        $key = $this->secret_key();
        if ( $key === null ) {
            return new WP_Error( 'personaizer_no_key', 'PERSONAIZER secret key is not configured.' );
        }

        $boundary = wp_generate_password( 24, false );
        $eol      = "\r\n";
        $filename = $external_id . '.md';

        $body  = '';
        // file part (the post content, as a markdown text file)
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
        $body .= 'Content-Type: text/markdown' . $eol . $eol;
        $body .= $markdown . $eol;

        // brand: this site's lanes (pages / posts / products) are separate sources so each can be switched
        // off on its own — but they are ONE shop, so they must share one identity. Without this, every lane
        // would mint its own brand and the owner would have three logos to keep in step.
        $fields = [ 'id' => $external_id, 'title' => $title, 'source' => $source, 'brand' => personaizer_source_key() ];
        if ( $permalink !== '' ) {
            $fields['links'] = wp_json_encode( [ [ 'url' => $permalink, 'is_primary' => true ] ] );
        }
        // Image library (featured + inline). Always sent — even [] — so the plugin,
        // not the extractor's auto-find, is the source of truth for this doc's images.
        $fields['images'] = wp_json_encode( array_values( (array) $images ) );
        foreach ( $fields as $name => $value ) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= $value . $eol;
        }
        $body .= '--' . $boundary . '--' . $eol;

        $response = wp_remote_post(
            $this->base() . '/v1/knowledge/docs/upload',
            [
                'timeout' => 30,
                'headers' => [
                    'X-Api-Key'    => $key,
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ],
                'body'    => $body,
            ]
        );

        return $this->handle_response( $response, 'upload' );
    }

    /**
     * Bulk upsert TYPED product docs (1–100) via PUT /v1/knowledge/docs.
     * Idempotent by each item's `id`; identical content replays as a no-op.
     *
     * @param array[] $items Typed items ({id,title,source,category,price,…}).
     * @return array{deferred:string[]}|WP_Error On success an array whose `deferred` holds the external
     *         ids the plan had no room for (empty = everything landed). WP_Error on failure — a 402 means
     *         nothing fit at all.
     */
    /**
     * Every external id the AI currently holds for one source — the remote half of a reconciliation.
     *
     * Deliberately does NOT go through handle_response(): that stamps `personaizer_last_sync` on any
     * successful non-delete call, and a read must never be able to claim the site was just synced.
     *
     * @param string $source Lane source key (see personaizer_lane_source()).
     * @return string[]|WP_Error
     */
    public function list_doc_ids( $source ) {
        $key = $this->secret_key();
        if ( $key === null ) {
            return new WP_Error( 'personaizer_no_key', 'PersonAIzer secret key is not configured.' );
        }

        $ids    = array();
        $limit  = 200;   // the endpoint's documented maximum
        $offset = 0;

        // Bounded: a runaway pager must not hang an admin request. 500 pages = 100k docs, far past any
        // plan's knowledge allowance, so hitting this cap means something is wrong, not that a site is big.
        for ( $page = 0; $page < 500; $page++ ) {
            $url = add_query_arg(
                array( 'source' => $source, 'limit' => $limit, 'offset' => $offset ),
                $this->base() . '/v1/knowledge/docs'
            );
            $response = wp_remote_get( $url, array(
                'timeout' => 30,
                'headers' => array( 'X-Api-Key' => $key ),
            ) );
            if ( is_wp_error( $response ) ) return $response;

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) {
                return new WP_Error(
                    'personaizer_api_' . $code,
                    sprintf( 'Could not read the document list (HTTP %d).', $code ),
                    array( 'status' => $code )
                );
            }

            $body  = json_decode( wp_remote_retrieve_body( $response ), true );
            $items = ( is_array( $body ) && isset( $body['items'] ) && is_array( $body['items'] ) )
                ? $body['items'] : array();

            foreach ( $items as $item ) {
                if ( ! empty( $item['id'] ) ) $ids[] = (string) $item['id'];
            }
            if ( count( $items ) < $limit ) break;   // short page ⇒ last page
            $offset += $limit;
        }

        return $ids;
    }

    public function upsert_products( array $items ) {
        $key = $this->secret_key();
        if ( $key === null ) {
            return new WP_Error( 'personaizer_no_key', 'PERSONAIZER secret key is not configured.' );
        }
        $items = array_values( $items );
        if ( empty( $items ) ) {
            return array( 'deferred' => array() );
        }

        $response = wp_remote_request(
            $this->base() . '/v1/knowledge/docs',
            [
                'method'  => 'PUT',
                'timeout' => 30,
                'headers' => [
                    'X-Api-Key'    => $key,
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode( [ 'items' => $items ] ),
            ]
        );

        $result = $this->handle_response( $response, 'product upsert' );
        if ( $result !== true ) {
            return $result;   // WP_Error
        }
        // Partial-accept: the server writes what fits the plan's knowledge quota and returns the ids it
        // DEFERRED, so the caller can remember exactly those (not the whole batch) as waiting for space.
        // Body is snake_case on the /v1 surface; absent/empty `deferred` ⇒ everything landed.
        $body     = json_decode( wp_remote_retrieve_body( $response ), true );
        $deferred = ( is_array( $body ) && ! empty( $body['deferred'] ) )
            ? array_values( array_map( 'strval', (array) $body['deferred'] ) )
            : array();
        return array( 'deferred' => $deferred );
    }

    /**
     * Which of this account's sources the connected persona answers from, and how much each one holds.
     *
     * Read, never assumed: the owner can flip a source in the PERSONAIZER dashboard, and a plugin that
     * guessed the state from its own options would render a lie the moment they did.
     *
     * doc_count is here for the same reason. The obvious local substitute — "how many pages does this site
     * have" — is a DIFFERENT number, and it diverges exactly when a lane stops syncing: the site loses a
     * page, the AI keeps its copy, and the screen that says "what your AI uses" would quietly count the
     * site's. That is the one moment the owner is reading it to decide whether to switch syncing back on.
     *
     * @return array<string,array{in_use:bool,doc_count:int,ready_count:int|null}>|WP_Error source key => its state.
     */
    public function get_source_states() {
        $key = $this->secret_key();
        if ( $key === null ) {
            return new WP_Error( 'personaizer_no_key', 'PERSONAIZER secret key is not configured.' );
        }

        $response = wp_remote_get(
            $this->base() . '/v1/knowledge/sources',
            [ 'timeout' => 15, 'headers' => [ 'X-Api-Key' => $key ] ]
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'personaizer_http_' . $code, self::friendly_error( $code, wp_remote_retrieve_body( $response ) ) );
        }
        // snake_case on the wire — /v1 has no camelCase interceptor.
        $body  = json_decode( wp_remote_retrieve_body( $response ), true );
        $out   = array();
        foreach ( (array) ( $body['sources'] ?? array() ) as $row ) {
            if ( isset( $row['source'] ) ) {
                $out[ (string) $row['source'] ] = array(
                    'in_use'    => ! empty( $row['in_use'] ),
                    // Null, not 0, when the field isn't in the response: "no documents" and "I don't know"
                    // are different answers and only one of them is safe to print beside a switch.
                    'doc_count' => array_key_exists( 'doc_count', $row ) ? (int) $row['doc_count'] : null,
                    // How many of doc_count have finished processing (embedded) and can actually be answered
                    // from. Null when an older Core doesn't send it — callers must fall back to doc_count
                    // rather than assume "0 ready", which would falsely read as "nothing works yet".
                    'ready_count' => array_key_exists( 'ready_count', $row ) ? (int) $row['ready_count'] : null,
                );
            }
        }
        return $out;
    }

    /**
     * Start/stop the persona answering from a source. NOT a delete — the documents stay exactly where they
     * are, retrieval just stops including them, and switching back on is instant and costs no re-processing.
     *
     * @param string $source Source key.
     * @param bool   $in_use
     * @return true|WP_Error
     */
    public function set_source_in_use( $source, $in_use ) {
        $key = $this->secret_key();
        if ( $key === null ) {
            return new WP_Error( 'personaizer_no_key', 'PERSONAIZER secret key is not configured.' );
        }

        $response = wp_remote_request(
            $this->base() . '/v1/knowledge/sources/' . rawurlencode( $source ) . '/use',
            [
                'method'  => $in_use ? 'POST' : 'DELETE',
                'timeout' => 20,
                'headers' => [ 'X-Api-Key' => $key ],
            ]
        );
        return $this->handle_response( $response, $in_use ? 'use source' : 'stop using source' );
    }

    /**
     * Remove docs from the persona by external id(s). Silently succeeds for ids
     * that aren't present, so it's safe to call unconditionally on delete.
     *
     * @param string[] $external_ids
     * @return true|WP_Error
     */
    public function delete_docs( array $external_ids ) {
        $key = $this->secret_key();
        if ( $key === null ) {
            return new WP_Error( 'personaizer_no_key', 'PERSONAIZER secret key is not configured.' );
        }
        $external_ids = array_values( array_filter( array_map( 'strval', $external_ids ) ) );
        if ( empty( $external_ids ) ) {
            return true;
        }

        $ids = implode( ',', array_map( 'rawurlencode', $external_ids ) );
        $response = wp_remote_request(
            $this->base() . '/v1/knowledge/docs?ids=' . $ids,
            [
                'method'  => 'DELETE',
                'timeout' => 30,
                'headers' => [ 'X-Api-Key' => $key ],
            ]
        );

        return $this->handle_response( $response, 'delete' );
    }

    /** @return true|WP_Error */
    private function handle_response( $response, $op ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            // Stamp the last successful CONTENT push — the admin screen turns this into
            // "synced 2 minutes ago", which is the whole proof-of-life the owner gets. Deletes
            // don't count: removing a trashed post says nothing about the catalog being current.
            if ( $op !== 'delete' ) {
                update_option( 'personaizer_last_sync', time(), false );
            }
            return true;
        }
        $raw  = (string) wp_remote_retrieve_body( $response );
        $body = wp_strip_all_tags( $raw );
        // The machine-readable error code, when the body is our RFC7807 problem. `limits.quota_exceeded`
        // (HTTP 402) is the one the sync layer must act on: it means "your plan is full", not "this item
        // is bad", so those items are remembered for an automatic replay after an upgrade rather than
        // dropped. Carried on the WP_Error's data (see is_quota_error()).
        $api_code = self::error_code( $raw );

        // Remember WHY, in the owner's words, so the admin screen can explain a stalled sync instead
        // of just showing a smaller number than expected. The API rejects a batch atomically, so this
        // one reason usually accounts for every item in it.
        update_option( 'personaizer_last_error', [
            'message' => self::friendly_error( $code, $body ),
            'code'    => $api_code,
            'at'      => time(),
        ], false );

        return new WP_Error(
            'personaizer_api_' . $code,
            sprintf( 'PERSONAIZER %s failed (HTTP %d): %s', $op, $code, $body ),
            [ 'status' => $code, 'code' => $api_code ]
        );
    }

    /** The RFC7807 `code` from a problem body (e.g. "limits.quota_exceeded"), or '' when the body isn't ours. */
    private static function error_code( $body ) {
        $data = json_decode( (string) $body, true );
        return ( is_array( $data ) && ! empty( $data['code'] ) ) ? (string) $data['code'] : '';
    }

    /**
     * Was this failure the account's knowledge quota being full (HTTP 402 limits.quota_exceeded)?
     *
     * The one API rejection the sync layer treats specially: the items are fine, the plan is full, so
     * they're remembered for an automatic replay after the owner upgrades — never counted as broken.
     * Falls back to the bare 402 status, which on the knowledge surface only ever means quota.
     *
     * @param mixed $result A return value from any of the write methods above.
     */
    public static function is_quota_error( $result ) {
        if ( ! is_wp_error( $result ) ) return false;
        $data = $result->get_error_data();
        if ( ! is_array( $data ) ) return false;
        return ( ( $data['code'] ?? '' ) === 'limits.quota_exceeded' )
            || ( (int) ( $data['status'] ?? 0 ) === 402 );
    }

    /**
     * Turn an RFC7807 problem body into one sentence a site owner can act on. Falls back to the
     * status code when the body isn't ours (a proxy error page, say).
     */
    private static function friendly_error( $code, $body ) {
        // Auth first: the body's title for a 401 is just "Unauthorized", which tells the owner
        // nothing they can act on. The status code is the more informative signal here.
        if ( $code === 401 || $code === 403 ) {
            return 'Your secret API key was rejected — try reconnecting.';
        }
        $data = json_decode( $body, true );
        if ( is_array( $data ) ) {
            // Per-item validation failures (422) carry the actionable reason as objects; they repeat per
            // item, so one is enough. Guard that errors[0] is actually an OBJECT: on a 402 quota problem
            // `errors` is a bare string[], and indexing a string with 'message' would return its first
            // character — so fall through to `detail` (the quota prose) instead.
            if ( isset( $data['errors'][0] ) && is_array( $data['errors'][0] ) && ! empty( $data['errors'][0]['message'] ) ) {
                return (string) $data['errors'][0]['message'];
            }
            if ( ! empty( $data['detail'] ) ) return (string) $data['detail'];
            if ( ! empty( $data['title'] ) )  return (string) $data['title'];
        }
        return sprintf( 'The PERSONAIZER API returned HTTP %d.', (int) $code );
    }
}
