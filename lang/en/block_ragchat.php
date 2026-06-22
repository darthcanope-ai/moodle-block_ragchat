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
 * English language strings for block_ragchat.
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin.
$string['pluginname'] = 'AI Chatbot';

// Chat UI.
$string['chat_placeholder']  = 'Ask a question about the available courses…';
$string['chat_send']         = 'Send';
$string['chat_thinking']     = 'Thinking…';
$string['ai_disclaimer']     = 'AI-generated response — always check the sources.';
$string['sources']           = 'Sources';
$string['no_results']        = 'No relevant courses were found for your question. Try rephrasing it.';
$string['unknown_source']      = 'Unknown source';
$string['chat_user_question']  = 'Question:';

// Errors.
$string['error_generic']      = 'An error occurred. Please try again later.';
$string['error_emptyquestion']= 'Please enter a question.';
$string['error_api']          = 'Albert API error: {$a}';
$string['provider_not_configured'] = 'The AI provider is not configured. Please contact your administrator.';

// System prompts.
$string['systemprompt_catalogue'] = "You are a course discovery assistant for a learning platform.
Help the user find courses that match their needs.
Answer ONLY based on the course descriptions provided below.
If no course matches, say so clearly and suggest related topics.
Always present the courses with their title, description and teacher name when available.

Available courses:
{chunks}";

$string['systemprompt_course'] = "You are a pedagogical assistant for the course \"{coursename}\".
Answer ONLY based on the document excerpts provided below.
If the answer is not found in the documents, say so clearly.
Never answer questions related to exams or graded assessments.
Always cite the source document.

Available documents:
{chunks}";

// Settings.
$string['settings_apikey']             = 'Albert API key (fallback)';
$string['settings_apikey_desc']        = 'Used only when the aiprovider_albertapi plugin is not installed.';
$string['settings_apiendpoint']        = 'Albert API endpoint (fallback)';
$string['settings_apiendpoint_desc']   = 'Base URL of the Albert API. Defaults to the DINUM production instance.';
$string['settings_embedding_model']    = 'Embedding model';
$string['settings_embedding_model_desc'] = 'Albert model used to vectorise documents and queries.';
$string['settings_search_top_k']       = 'Chunks retrieved (top-K)';
$string['settings_search_top_k_desc']  = 'Number of chunks retrieved from Albert /v1/search before reranking.';
$string['settings_rerank_top_n']       = 'Chunks after reranking (top-N)';
$string['settings_rerank_top_n_desc']  = 'Number of chunks injected into the LLM context after /v1/rerank.';
$string['settings_sync_heading']       = 'Catalogue synchronisation';
$string['settings_last_sync']          = 'Last sync: {$a}';
$string['never']                       = 'never';

// Capabilities.
$string['block_ragchat:addinstance']   = 'Add the RAG chatbot block';
$string['block_ragchat:myaddinstance'] = 'Add the RAG chatbot to the dashboard';
$string['block_ragchat:use']           = 'Use the RAG chatbot';
$string['block_ragchat:manage']        = 'Manage RAG chatbot settings and logs';

// Scheduled task.
$string['task_sync_catalogue'] = 'Synchronise course catalogue to Albert API';
