<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * HTTP client for Albert API RAG endpoints.
 *
 * Credentials are resolved in this order:
 *  1. AI subsystem — iterates providers configured for generate_text,
 *     reads apikey / apiendpoint from $provider->config (Moodle 5 pattern).
 *  2. Block-level fallback settings (block admin settings page).
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ragchat;

use core\http_client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

/**
 * Wraps the Albert API RAG endpoints (search, rerank, collections, files).
 */
class albert_client {

    /** Embedding model used by Albert. */
    const EMBEDDING_MODEL = 'BAAI/bge-m3';

    /** Reranker model used by Albert. */
    const RERANKER_MODEL = 'BAAI/bge-reranker-v2-m3';

    /** Default Albert API base URL. */
    const DEFAULT_ENDPOINT = 'https://albert.api.etalab.gouv.fr';

    /** Number of chunks retrieved from /v1/search before reranking. */
    const SEARCH_TOP_K = 10;

    /** Number of chunks kept after /v1/rerank. */
    const RERANK_TOP_N = 4;

    /** @var string Albert API base URL (no trailing slash). */
    private string $endpoint;

    /** @var string Bearer token. */
    private string $apikey;

    /**
     * Constructor — resolves credentials from the Moodle AI subsystem,
     * with fallback to block-level admin settings.
     */
    public function __construct() {
        $this->endpoint = self::DEFAULT_ENDPOINT;
        $this->apikey   = '';

        // Priority 1: AI subsystem configured provider.
        $this->resolve_from_ai_subsystem();

        // Priority 2: block-level settings fallback.
        if (empty($this->apikey)) {
            $this->apikey = (string) (get_config('block_ragchat', 'apikey') ?: '');
        }
        $blockep = get_config('block_ragchat', 'apiendpoint');
        if (!empty($blockep) && $this->endpoint === self::DEFAULT_ENDPOINT) {
            $this->endpoint = rtrim($blockep, '/');
        }
    }

    /**
     * Returns true when an API key is available.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->apikey !== '';
    }

    // -------------------------------------------------------------------------
    // RAG pipeline
    // -------------------------------------------------------------------------

    /**
     * Retrieve relevant chunks from an Albert collection.
     *
     * POST /v1/search  (Albert handles vectorisation server-side)
     *
     * @param  string $query
     * @param  string $collectionid
     * @param  int    $topk
     * @return array
     * @throws \moodle_exception
     */
    public function search(string $query, string $collectionid, int $topk = self::SEARCH_TOP_K): array {
        $body = $this->post('/v1/search', [
            'collections' => [$collectionid],
            'query'       => $query,
            'k'           => $topk,
        ]);
        return $body->data ?? [];
    }

    /**
     * Rerank retrieved chunks by relevance.
     *
     * POST /v1/rerank
     *
     * @param  string $query
     * @param  array  $chunks
     * @param  int    $topn
     * @return array
     * @throws \moodle_exception
     */
    public function rerank(string $query, array $chunks, int $topn = self::RERANK_TOP_N): array {
        if (empty($chunks)) {
            return [];
        }

        $documents = array_map(fn($c) => $c->chunk->content ?? '', $chunks);

        $body = $this->post('/v1/rerank', [
            'model'     => self::RERANKER_MODEL,
            'query'     => $query,
            'documents' => $documents,
            'top_n'     => $topn,
        ]);

        $reranked = [];
        foreach ($body->results ?? [] as $r) {
            if (isset($chunks[$r->index])) {
                $chunk = $chunks[$r->index];
                $chunk->rerank_score = $r->relevance_score;
                $reranked[] = $chunk;
            }
        }
        return $reranked;
    }

    // -------------------------------------------------------------------------
    // Collection management (cron task)
    // -------------------------------------------------------------------------

    /**
     * @return array
     */
    public function list_collections(): array {
        return $this->get('/v1/collections')->data ?? [];
    }

    /**
     * @param  string $name
     * @return \stdClass
     */
    public function create_collection(string $name): \stdClass {
        return $this->post('/v1/collections', [
            'name'  => $name,
            'model' => self::EMBEDDING_MODEL,
        ]);
    }

    /**
     * @param  string $name
     * @return void
     */
    public function reset_collection(string $name): void {
        $this->delete('/v1/collections/' . urlencode($name) . '/documents');
    }

    /**
     * Upload a file via multipart/form-data.
     *
     * @param  string $filename
     * @param  string $content
     * @param  string $purpose
     * @return string Albert file ID.
     */
    public function upload_file(string $filename, string $content, string $purpose = 'assistants'): string {
        $client  = \core\di::get(http_client::class);
        $request = new Request(
            'POST',
            $this->endpoint . '/v1/files',
            ['Authorization' => 'Bearer ' . $this->apikey],
        );

        try {
            $response = $client->send($request, [
                RequestOptions::MULTIPART => [
                    ['name' => 'purpose',  'contents' => $purpose],
                    ['name' => 'file',     'contents' => $content, 'filename' => $filename],
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (RequestException $e) {
            throw new \moodle_exception('error_api', 'block_ragchat', '', $e->getMessage());
        }

        $this->assert_success($response);
        $body = json_decode($response->getBody()->getContents());
        return $body->id ?? '';
    }

    /**
     * Index an uploaded file into a collection.
     *
     * @param  string $fileid
     * @param  string $collectionid
     * @return \stdClass
     */
    public function index_document(string $fileid, string $collectionid): \stdClass {
        return $this->post('/v1/documents', [
            'file_id'    => $fileid,
            'collection' => $collectionid,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve API credentials from the Moodle AI subsystem.
     *
     * Iterates providers configured for generate_text and reads apikey /
     * apiendpoint from $provider->config (Moodle 5 hook-based config).
     */
    private function resolve_from_ai_subsystem(): void {
        try {
            $manager   = \core\di::get(\core_ai\manager::class);

            // get_providers_for_actions() takes an array and returns [actionclass => [providers]].
            $map = $manager->get_providers_for_actions(
                [\core_ai\aiactions\generate_text::class],
                true,
            );
            $providers = $map[\core_ai\aiactions\generate_text::class] ?? [];

            foreach ($providers as $provider) {
                // In Moodle 5, $provider->config is populated from the saved form
                // and may be an array or object depending on storage.
                $config = $provider->config ?? [];
                if (is_object($config)) {
                    $config = (array) $config;
                }

                if (!empty($config['apikey'])) {
                    $this->apikey = $config['apikey'];
                    if (!empty($config['apiendpoint'])) {
                        $this->endpoint = rtrim($config['apiendpoint'], '/');
                    }
                    return;
                }

                // Some providers expose dedicated accessors.
                if (method_exists($provider, 'get_api_key')) {
                    $key = $provider->get_api_key();
                    if (!empty($key)) {
                        $this->apikey = $key;
                        if (method_exists($provider, 'get_api_endpoint')) {
                            $this->endpoint = rtrim($provider->get_api_endpoint(), '/');
                        }
                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
            debugging('block_ragchat: could not resolve AI provider credentials: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    private function post(string $path, array $payload): \stdClass {
        $client  = \core\di::get(http_client::class);
        $request = new Request(
            'POST',
            $this->endpoint . $path,
            [
                'Authorization' => 'Bearer ' . $this->apikey,
                'Content-Type'  => 'application/json',
            ],
            json_encode($payload),
        );

        try {
            $response = $client->send($request, [RequestOptions::HTTP_ERRORS => false]);
        } catch (RequestException $e) {
            throw new \moodle_exception('error_api', 'block_ragchat', '', $e->getMessage());
        }

        $this->assert_success($response);
        return json_decode($response->getBody()->getContents());
    }

    private function get(string $path): \stdClass {
        $client  = \core\di::get(http_client::class);
        $request = new Request(
            'GET',
            $this->endpoint . $path,
            ['Authorization' => 'Bearer ' . $this->apikey],
        );

        try {
            $response = $client->send($request, [RequestOptions::HTTP_ERRORS => false]);
        } catch (RequestException $e) {
            throw new \moodle_exception('error_api', 'block_ragchat', '', $e->getMessage());
        }

        $this->assert_success($response);
        return json_decode($response->getBody()->getContents());
    }

    private function delete(string $path): void {
        $client  = \core\di::get(http_client::class);
        $request = new Request(
            'DELETE',
            $this->endpoint . $path,
            ['Authorization' => 'Bearer ' . $this->apikey],
        );

        try {
            $response = $client->send($request, [RequestOptions::HTTP_ERRORS => false]);
        } catch (RequestException $e) {
            throw new \moodle_exception('error_api', 'block_ragchat', '', $e->getMessage());
        }

        if ($response->getStatusCode() >= 400) {
            $this->assert_success($response);
        }
    }

    private function assert_success(\Psr\Http\Message\ResponseInterface $response): void {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $body = $response->getBody()->getContents();
            $obj  = json_decode($body);
            $msg  = $obj?->detail ?? $obj?->error?->message ?? "HTTP {$status}: {$body}";
            throw new \moodle_exception('error_api', 'block_ragchat', '', $msg);
        }
    }
}
