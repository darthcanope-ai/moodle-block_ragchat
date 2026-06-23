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
 * External function — execute the RAG pipeline and return an AI answer.
 *
 * Pipeline:
 *  1. Validate input / capabilities.
 *  2. Call Albert /v1/search  (semantic search in the target collection).
 *  3. Call Albert /v1/rerank  (re-score top chunks).
 *  4. Build system prompt with injected context.
 *  5. Call Albert /v1/chat/completions  (generate answer).
 *  6. Return answer + sources to the JS layer.
 *
 * Note: /v1/embeddings is NOT called explicitly here because Albert's
 * /v1/search endpoint accepts a raw text query and handles vectorisation
 * server-side. We only call embeddings if we need the raw vector.
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ragchat\external;

use block_ragchat\albert_client;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Send a user message through the RAG pipeline and return the AI answer.
 */
class send_message extends external_api {

    /**
     * Describe the input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'question'     => new external_value(PARAM_TEXT, 'User question'),
            'collectionid' => new external_value(PARAM_ALPHANUMEXT, 'Albert collection identifier'),
            'courseid'     => new external_value(PARAM_INT, 'Moodle course ID (1 = site)', VALUE_DEFAULT, 1),
            'instanceid'   => new external_value(PARAM_INT, 'Block instance ID (for per-instance prompt)', VALUE_DEFAULT, 0),
            'history'      => new external_multiple_structure(
                new external_single_structure([
                    'role'    => new external_value(PARAM_ALPHA, 'user or assistant'),
                    'content' => new external_value(PARAM_TEXT, 'Message content'),
                ]),
                'Previous conversation turns',
                VALUE_DEFAULT,
                [],
            ),
        ]);
    }

    /**
     * Execute the RAG pipeline.
     *
     * @param  string $question
     * @param  string $collectionid
     * @param  int    $courseid
     * @return array
     */
    public static function execute(
        string $question,
        string $collectionid,
        int $courseid = 1,
        int $instanceid = 0,
        array $history = [],
    ): array {
        global $USER;

        // Validate and clean parameters.
        [
            'question'     => $question,
            'collectionid' => $collectionid,
            'courseid'     => $courseid,
            'instanceid'   => $instanceid,
            'history'      => $history,
        ] = self::validate_parameters(
            self::execute_parameters(),
            compact('question', 'collectionid', 'courseid', 'instanceid', 'history'),
        );

        // Capability check.
        $context = $courseid > 1
            ? \context_course::instance($courseid)
            : \context_system::instance();
        self::validate_context($context);
        require_capability('block/ragchat:use', $context);

        // Sanitise question length.
        $question = \core_text::substr(trim($question), 0, 1000);
        if ($question === '') {
            return self::error_response(get_string('error_emptyquestion', 'block_ragchat'));
        }

        $client = new albert_client();
        if (!$client->is_configured()) {
            return self::error_response(get_string('provider_not_configured', 'block_ragchat'));
        }

        // Try the full RAG pipeline. If the collection is not yet indexed,
        // fall back to a direct LLM answer without retrieved context.
        $ragavailable = true;
        $chunks       = [];

        try {
            $chunks = $client->search($question, $collectionid);
        } catch (\RuntimeException $e) {
            // Collection not found = cron has not run yet.
            $ragavailable = false;
        }

        try {
            if ($ragavailable && !empty($chunks)) {
                // Full RAG pipeline.
                $reranked     = $client->rerank($question, $chunks);
                $systemprompt = self::build_system_prompt($reranked, $collectionid, $courseid, $instanceid);
                $answer       = self::generate_answer($systemprompt, $question, $history, $context->id, $USER->id);
                $sources      = self::format_sources($reranked);
            } elseif ($ragavailable && empty($chunks)) {
                // Collection exists but no results found.
                $answer  = get_string('no_results', 'block_ragchat');
                $sources = [];
            } else {
                // No collection yet — answer without RAG context.
                $systemprompt = self::resolve_prompt_template($instanceid, 'norag');
                $answer       = self::generate_answer($systemprompt, $question, $history, $context->id, $USER->id);
                $sources      = [];
            }

            self::log_interaction($USER->id, $courseid, $collectionid, $question, $answer);

            return [
                'success' => true,
                'answer'  => $answer,
                'sources' => $sources,
                'error'   => '',
                'norag'   => !$ragavailable,
            ];

        } catch (\Throwable $e) {
            return self::error_response($e->getMessage());
        }
    }

    /**
     * Describe the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the request succeeded'),
            'answer'  => new external_value(PARAM_RAW, 'Generated answer (HTML-safe)', VALUE_DEFAULT, ''),
            'sources' => new external_multiple_structure(
                new external_single_structure([
                    'title'   => new external_value(PARAM_TEXT, 'Source document title'),
                    'excerpt' => new external_value(PARAM_TEXT, 'Short excerpt from the chunk'),
                    'url'     => new external_value(PARAM_URL,  'Link to the resource (if available)', VALUE_DEFAULT, ''),
                    'score'   => new external_value(PARAM_FLOAT, 'Relevance score'),
                ]),
                'Source documents used to build the answer',
                VALUE_DEFAULT,
                [],
            ),
            'error' => new external_value(PARAM_TEXT, 'Error message when success=false', VALUE_DEFAULT, ''),
            'norag' => new external_value(PARAM_BOOL, 'True when answered without RAG context (collection not yet indexed)', VALUE_DEFAULT, false),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Generate an answer via the Moodle AI subsystem (generate_text action).
     *
     * The system prompt and question are combined into a single prompttext
     * because the standard generate_text action does not expose a separate
     * system prompt field. The full RAG context is thus passed as user prompt.
     *
     * @param  string $systemprompt
     * @param  string $question
     * @param  int    $contextid
     * @param  int    $userid
     * @return string Generated answer text.
     * @throws \moodle_exception
     */
    private static function generate_answer(
        string $systemprompt,
        string $question,
        array $history,
        int $contextid,
        int $userid,
    ): string {
        $manager = \core\di::get(\core_ai\manager::class);

        // Build a transcript of previous turns, capped to avoid token overflows.
        $historysection = '';
        $turns = array_slice($history, -10); // Keep last 10 turns max.
        foreach ($turns as $turn) {
            $role    = ($turn['role'] === 'assistant') ? get_string('history_role_assistant', 'block_ragchat') : get_string('history_role_user', 'block_ragchat');
            $historysection .= $role . ': ' . \core_text::substr(trim($turn['content']), 0, 500) . "\n";
        }

        $fullprompt = $systemprompt;
        if ($historysection !== '') {
            $fullprompt .= "\n\n" . get_string('history_heading', 'block_ragchat') . "\n" . $historysection;
        }
        $fullprompt .= "\n\n" . get_string('chat_user_question', 'block_ragchat') . ' ' . $question;

        $action = new \core_ai\aiactions\generate_text(
            contextid:  $contextid,
            userid:     $userid,
            prompttext: $fullprompt,
        );

        $response = $manager->process_action($action);

        if (!$response->get_success()) {
            throw new \RuntimeException(
                $response->get_errormessage() ?: get_string('error_generic', 'block_ragchat'),
            );
        }

        return $response->get_response_data()['generatedcontent'] ?? '';
    }

    /**
     * Build the system prompt for the LLM with injected context chunks.
     *
     * @param  array  $chunks      Reranked chunk objects.
     * @param  string $collectionid
     * @param  int    $courseid
     * @return string
     */
    /**
     * Resolve system prompt template: instance config → global admin → lang string.
     *
     * @param  int    $instanceid  Block instance ID (0 = no instance).
     * @param  string $type        'catalogue', 'course', or 'norag'.
     * @return string              Prompt template (may contain {chunks}, {coursename} placeholders).
     */
    private static function resolve_prompt_template(int $instanceid, string $type): string {
        // Priority 1: per-instance config.
        if ($instanceid > 0) {
            $instance = \block_instance_by_id($instanceid);
            $prompt   = $instance->config->systemprompt ?? '';
            if (trim($prompt) !== '') {
                return $prompt;
            }
        }

        // Priority 2: global admin setting.
        $global = get_config('block_ragchat', 'systemprompt_' . $type);
        if (!empty(trim((string) $global))) {
            return (string) $global;
        }

        // Priority 3: lang string fallback.
        return get_string('systemprompt_' . $type, 'block_ragchat');
    }

    private static function build_system_prompt(
        array $chunks,
        string $collectionid,
        int $courseid,
        int $instanceid = 0,
    ): string {
        $iscatalogue = ($collectionid === 'catalogue_moodle');
        $type        = $iscatalogue ? 'catalogue' : 'course';

        $chunktext = '';
        foreach ($chunks as $i => $c) {
            $title   = $c->chunk->metadata->title ?? ('Document ' . ($i + 1));
            $content = $c->chunk->content ?? '';
            $chunktext .= "--- [{$title}] ---\n{$content}\n\n";
        }

        $template = self::resolve_prompt_template($instanceid, $type);

        if (!$iscatalogue && $courseid > 1) {
            $course     = get_course($courseid);
            $coursename = format_string($course->fullname);
            $template   = str_replace('{coursename}', $coursename, $template);
        }

        return str_replace('{chunks}', $chunktext, $template);
    }

    /**
     * Convert reranked chunks into a UI-friendly source array.
     *
     * @param  array $chunks
     * @return array
     */
    private static function format_sources(array $chunks): array {
        $sources = [];
        foreach ($chunks as $c) {
            $content = $c->chunk->content ?? '';
            $sources[] = [
                'title'   => $c->chunk->metadata->title   ?? get_string('unknown_source', 'block_ragchat'),
                'excerpt' => \core_text::substr(strip_tags($content), 0, 200),
                'url'     => $c->chunk->metadata->url      ?? '',
                'score'   => round((float) ($c->rerank_score ?? $c->score ?? 0), 3),
            ];
        }
        return $sources;
    }

    /**
     * Write a row to the interaction log table.
     *
     * @param  int    $userid
     * @param  int    $courseid
     * @param  string $collectionid
     * @param  string $question
     * @param  string $answer
     * @return void
     */
    private static function log_interaction(
        int $userid,
        int $courseid,
        string $collectionid,
        string $question,
        string $answer,
    ): void {
        global $DB;
        $DB->insert_record('block_ragchat_log', (object) [
            'userid'       => $userid,
            'courseid'     => $courseid,
            'collectionid' => $collectionid,
            'question'     => $question,
            'answer'       => $answer,
            'timecreated'  => time(),
        ]);
    }

    /**
     * Return a standardised error response array.
     *
     * @param  string $message
     * @return array
     */
    private static function error_response(string $message): array {
        return [
            'success' => false,
            'answer'  => '',
            'sources' => [],
            'error'   => $message,
        ];
    }
}
