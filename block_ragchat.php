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
 * RAG chatbot block — main class.
 *
 * Operates in two modes determined by placement context:
 *  - Homepage / dashboard : queries the Albert collection 'catalogue_moodle'
 *    to help a user find courses matching their needs.
 *  - Inside a course : queries 'course_{courseid}' to answer questions
 *    based on the course resources (not yet implemented here).
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * RAG chatbot block.
 */
class block_ragchat extends block_base {

    /**
     * Initialise the block.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_ragchat');
    }

    /**
     * Use per-instance title if configured.
     */
    public function specialization(): void {
        if (!empty($this->config->title)) {
            $this->title = format_string($this->config->title);
        }
    }

    /**
     * Allow per-instance configuration.
     *
     * @return bool
     */
    public function instance_allow_config(): bool {
        return true;
    }

    /**
     * This block can appear on the site homepage and course pages.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'site-index' => true,
            'course-view' => true,
            'my' => true,
        ];
    }

    /**
     * Allow multiple instances per page.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Block has global config (admin settings).
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Render block content.
     *
     * @return stdClass|null
     */
    public function get_content(): ?stdClass {
        global $USER, $COURSE, $PAGE, $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        $PAGE->requires->css('/blocks/ragchat/styles.css');

        // Require login.
        if (!isloggedin() || isguestuser()) {
            $this->content->text = '';
            return $this->content;
        }

        // Determine mode: catalogue (site/dashboard) or course.
        $incourse = ($COURSE->id > 1);
        $collectionid = $incourse
            ? 'course_' . $COURSE->id
            : 'catalogue_moodle';

        // Pass parameters to JS.
        $PAGE->requires->js_call_amd('block_ragchat/chat', 'init', [[
            'blockid'      => $this->instance->id,
            'instanceid'   => $this->instance->id,
            'courseid'     => (int) $COURSE->id,
            'collectionid' => $collectionid,
            'userid'       => (int) $USER->id,
            'incourse'     => $incourse,
            'sesskey'      => sesskey(),
            'strings'      => [
                'placeholder'   => get_string('chat_placeholder', 'block_ragchat'),
                'send'          => get_string('chat_send', 'block_ragchat'),
                'thinking'      => get_string('chat_thinking', 'block_ragchat'),
                'error_generic' => get_string('error_generic', 'block_ragchat'),
                'ai_disclaimer'  => get_string('ai_disclaimer', 'block_ragchat'),
                'sources'        => get_string('sources', 'block_ragchat'),
                'norag_disclaimer' => get_string('norag_disclaimer', 'block_ragchat'),
                'popout_open'    => get_string('popout_open', 'block_ragchat'),
                'popout_close'   => get_string('popout_close', 'block_ragchat'),
            ],
        ]]);

        // Render via Mustache template.
        $data = [
            'blockid'      => $this->instance->id,
            'incourse'     => $incourse,
            'placeholder'  => get_string('chat_placeholder', 'block_ragchat'),
            'send'         => get_string('chat_send', 'block_ragchat'),
            'ai_disclaimer'=> get_string('ai_disclaimer', 'block_ragchat'),
        ];

        $this->content->text = $OUTPUT->render_from_template('block_ragchat/chat', $data);

        return $this->content;
    }
}
