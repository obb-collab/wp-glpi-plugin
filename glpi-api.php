<?php
if (!defined('ABSPATH')) exit;

class Gexe_GLPI_API {
    private $base;
    private $app_token;
    private $user_token;
    private $session_token = '';

    public function __construct($base, $app_token, $user_token) {
        $this->base       = rtrim((string)$base, '/');
        $this->app_token  = trim((string)$app_token);
        $this->user_token = trim((string)$user_token);
    }

    private function request($method, $endpoint, $body = null, $headers = [], $retry = true) {
        $url  = $this->base . $endpoint;
        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => $headers,
        ];
        if (null !== $body) {
            $args['body'] = wp_json_encode($body);
            $args['headers']['Content-Type'] = 'application/json';
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response) && $retry) {
            $response = wp_remote_request($url, $args);
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 500 && $retry) {
                $response = wp_remote_request($url, $args);
            }
        }
        return $response;
    }

    public function init_session() {
        $resp = $this->request('POST', '/initSession', null, [
            'App-Token'   => $this->app_token,
            'Authorization' => 'user_token ' . $this->user_token,
        ]);
        if (is_wp_error($resp)) return $resp;
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || empty($body['session_token'])) {
            return new WP_Error('glpi_no_session', 'No session token');
        }
        $this->session_token = $body['session_token'];
        return $body;
    }

    private function auth_headers() {
        return [
            'App-Token'   => $this->app_token,
            'Session-Token' => $this->session_token,
        ];
    }

    public function add_solution($ticket_id, $content) {
        $body = [
            'input' => [
                'itemtype' => 'Ticket',
                'items_id' => (int)$ticket_id,
                'content'  => $content,
                'solutiontypes_id' => 1,
            ]
        ];
        return $this->request('POST', '/ITILSolution', $body, $this->auth_headers());
    }

    public function set_ticket_status($ticket_id, $status) {
        $body = [
            'input' => [
                'id'     => (int)$ticket_id,
                'status' => (int)$status,
            ]
        ];
        return $this->request('PUT', '/Ticket/' . intval($ticket_id), $body, $this->auth_headers());
    }

    public function kill_session() {
        return $this->request('GET', '/killSession', null, $this->auth_headers());
    }
}
