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
 * Reads credentials from the aiprovider_albertapi configuration so this block
 * is not tied to a specific API key — it reuses whatever the admin configured
 * in the Moodle AI subsystem.
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
 * Wraps the four Albert API RAG endpoints.
 */
class albert_client {

    /** Embedding model used by Albert. */
    const EMBEDDING_MODEL = 'BAAI/bge-m3';

    /** Reranker model used by Albert. */
    const RERANKER_MODEL = 'BAAI/bge-reranker-v2-m3';

    /** Number of chunks retrieved from /v1/search before reranking. */
    const SEARCH_TOP_K = 10;

    /** Number of chunks kept after /v1/rerank for context injection. */
    const RERANK_TOP_N = 4;

    /** @var string Albert API base URL (no trailing slash). */
    private string $endpoint;

    /** @var string Bearer token. */
    private string $apikey;

    /**
     * Constructor — reads config from aiprovider_albertapi.
     *
     * Falls back to block-level settings if the provider plugin is absent
     * (e.g. when using a different AI provider for completions).
     */
    public function __construct() {
        $this->apikey   = (string) (get_config('aiprovider_albertapi', 'apikey')       ?: get_config('block_ragchat', 'apikey') ?: '');
        $this->endpoint = rtrim(
            (string) (get_config('aiprovider_albertapi', 'apiendpoint') ?: get_config('block_ragchat', 'apiendpoint') ?: 'https://albert.api.etalab.gouv.fr'),
            '/',
        );
    }

    /**
     * Returns true when credentials are available.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->apikey !== '';
    }

    // -------------------------------------------------------------------------
    // Public RAG pipeline methods
    // -------------------------------------------------------------------------

    /**
     * Step 1 — Vectorise a query string.
     *
     * POST /v1/embeddings
     *
     * @param  string $query
     * @return float[]  Embedding vector.
     * @throws \moodle_exception on API error.
     */
    public function get_embedding(string $query): array {
        $body = $this->post('/v1/embeddings', [
            'input' => $query,
            'model' => self::EMBEDDING_MODEL,
        ]);

        return $body->data[0]->embedding ?? [];
    }

    /**
     * Step 2 — Retrieve relevant chunks from an Albert collection.
     *
     * POST /v1/search
     *
     * @param  string  $query        User question.
     * @param  string  $collectionid Albert collection name (e.g. 'catalogue_moodle').
     * @param  int     $topk         Number of results to retrieve.
     * @return array   Array of chunk objects {score, chunk: {content, metadata}}.
     * @throws \moodle_exception on API error.
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
     * Step 3 — Rerank the retrieved chunks by relevance.
     *
     * POST /v1/rerank
     *
     * @param  string $query   User question.
     * @param  array  $chunks  Chunks returned by search() (objects with ->chunk->content).
     * @param  int    $topn    Number of top chunks to keep.
     * @return array  Reranked subset of chunks.
     * @throws \moodle_exception on API error.
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

        // results: [{index: int, relevance_score: float}]
        $results = $body->results ?? [];
        $reranked = [];
        foreach ($results as $r) {
            if (isset($chunks[$r->index])) {
                $chunk = $chunks[$r->index];
                $chunk->rerank_score = $r->relevance_score;
                $reranked[] = $chunk;
            }
        }

        return $reranked;
    }

    /**
     * Step 4 — Generate an answer using the LLM with injected context.
     *
     * POST /v1/chat/completions
     *
     * @param  string $systemprompt System prompt with injected chunks.
     * @param  string $question     User question.
     * @param  string $model        LLM model identifier.
     * @return string Generated answer.
     * @throws \moodle_exception on API error.
     */
    public function chat(string $systemprompt, string $question, string $model = ''): string {
        if ($model === '') {
            $model = (string) (get_config('aiprovider_albertapi', 'action_generate_text_model') ?: 'AgentPublic/llama3-instruct-8b');
        }

        $body = $this->post('/v1/chat/completions', [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user',   'content' => $question],
            ],
            'stream'     => false,
            'max_tokens' => 1024,
        ]);

        return $body->choices[0]->message->content ?? '';
    }

    // -------------------------------------------------------------------------
    // Collection management (used by cron task)
    // -------------------------------------------------------------------------

    /**
     * List existing collections.
     *
     * GET /v1/collections
     *
     * @return array
     */
    public function list_collections(): array {
        $body = $this->get('/v1/collections');
        return $body->data ?? [];
    }

    /**
     * Create a collection if it does not exist.
     *
     * POST /v1/collections
     *
     * @param  string $name
     * @return \stdClass API response body.
     */
    public function create_collection(string $name): \stdClass {
        return $this->post('/v1/collections', ['name' => $name, 'model' => self::EMBEDDING_MODEL]);
    }

    /**
     * Delete all documents in a collection (full reset before re-indexing).
     *
     * DELETE /v1/collections/{name}/documents
     *
     * @param  string $name
     * @return void
     */
    public function reset_collection(string $name): void {
        $this->delete('/v1/collections/' . urlencode($name) . '/documents');
    }

    /**
     * Upload a file to Albert Files API.
     *
     * POST /v1/files  (multipart/form-data)
     *
     * @param  string $filename
     * @param  string $content  Raw file content.
     * @param  string $purpose  Albert file purpose (default: 'assistants').
     * @return string File ID returned by Albert.
     */
    public function upload_file(string $filename, string $content, string $purpose = 'assistants'): string {
        $client = \core\di::get(http_client::class);

        $request = new Request(
            'POST',
            $this->endpoint . '/v1/files',
            ['Authorization' => 'Bearer ' . $this->apikey],
        );

        try {
            $response = $client->send($request, [
                RequestOptions::MULTIPART => [
                    ['name' => 'purpose', 'contents' => $purpose],
                    ['name' => 'file', 'contents' => $content, 'filename' => $filename],
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
     * Index a previously uploaded file into a collection.
     *
     * POST /v1/documents
     *
     * @param  string $fileid       Albert file ID.
     * @param  string $collectionid Albert collection name.
     * @return \stdClass
     */
    public function index_document(string $fileid, string $collectionid): \stdClass {
        return $this->post('/v1/documents', [
            'file_id'    => $fileid,
            'collection' => $collectionid,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Perform a JSON POST request.
     *
     * @param  string $path    API path (e.g. '/v1/search').
     * @param  array  $payload Request body.
     * @return \stdClass Decoded response body.
     * @throws \moodle_exception
     */
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

    /**
     * Perform a JSON GET request.
     *
     * @param  string $path
     * @return \stdClass
     * @throws \moodle_exception
     */
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

    /**
     * Perform a DELETE request.
     *
     * @param  string $path
     * @return void
     * @throws \moodle_exception
     */
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

        // 204 No Content is acceptable for DELETE.
        if ($response->getStatusCode() >= 400) {
            $this->assert_success($response);
        }
    }

    /**
     * Throw a moodle_exception if the response indicates an error.
     *
     * @param  \Psr\Http\Message\ResponseInterface $response
     * @return void
     * @throws \moodle_exception
     */
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
