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
 * Admin settings for block_ragchat.
 *
 * These settings are only used as fallback when aiprovider_albertapi is not
 * installed (e.g. using another provider for completions but still needing
 * the Albert RAG endpoints).
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // Fallback API key (used only when aiprovider_albertapi is absent).
    $settings->add(new admin_setting_configpasswordunmask(
        'block_ragchat/apikey',
        get_string('settings_apikey', 'block_ragchat'),
        get_string('settings_apikey_desc', 'block_ragchat'),
        '',
    ));

    // Fallback API endpoint.
    $settings->add(new admin_setting_configtext(
        'block_ragchat/apiendpoint',
        get_string('settings_apiendpoint', 'block_ragchat'),
        get_string('settings_apiendpoint_desc', 'block_ragchat'),
        'https://albert.api.etalab.gouv.fr',
        PARAM_URL,
    ));

    // Embedding model.
    $settings->add(new admin_setting_configtext(
        'block_ragchat/embedding_model',
        get_string('settings_embedding_model', 'block_ragchat'),
        get_string('settings_embedding_model_desc', 'block_ragchat'),
        'BAAI/bge-m3',
        PARAM_TEXT,
    ));

    // Number of chunks to retrieve before reranking.
    $settings->add(new admin_setting_configselect(
        'block_ragchat/search_top_k',
        get_string('settings_search_top_k', 'block_ragchat'),
        get_string('settings_search_top_k_desc', 'block_ragchat'),
        10,
        [5 => '5', 10 => '10', 20 => '20'],
    ));

    // Number of chunks kept after reranking.
    $settings->add(new admin_setting_configselect(
        'block_ragchat/rerank_top_n',
        get_string('settings_rerank_top_n', 'block_ragchat'),
        get_string('settings_rerank_top_n_desc', 'block_ragchat'),
        4,
        [2 => '2', 3 => '3', 4 => '4', 5 => '5'],
    ));

    // Default system prompts (global fallback, overridable per block instance).
    $settings->add(new admin_setting_heading(
        'block_ragchat/prompts_heading',
        get_string('settings_prompts_heading', 'block_ragchat'),
        get_string('settings_prompts_heading_desc', 'block_ragchat'),
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_ragchat/systemprompt_catalogue',
        get_string('settings_systemprompt_catalogue', 'block_ragchat'),
        get_string('settings_systemprompt_catalogue_desc', 'block_ragchat'),
        '', // Empty = use lang string default.
        PARAM_RAW,
        '80',
        '8',
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_ragchat/systemprompt_course',
        get_string('settings_systemprompt_course', 'block_ragchat'),
        get_string('settings_systemprompt_course_desc', 'block_ragchat'),
        '',
        PARAM_RAW,
        '80',
        '8',
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_ragchat/systemprompt_norag',
        get_string('settings_systemprompt_norag', 'block_ragchat'),
        get_string('settings_systemprompt_norag_desc', 'block_ragchat'),
        '',
        PARAM_RAW,
        '80',
        '5',
    ));

    // Last catalogue sync (read-only info).
    $lastsync = get_config('block_ragchat', 'catalogue_last_sync');
    $lastsyncstr = $lastsync
        ? userdate($lastsync)
        : get_string('never', 'block_ragchat');

    $settings->add(new admin_setting_heading(
        'block_ragchat/sync_info',
        get_string('settings_sync_heading', 'block_ragchat'),
        get_string('settings_last_sync', 'block_ragchat', $lastsyncstr),
    ));
}
