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
 * Block instance configuration form for block_ragchat.
 *
 * Allows per-instance customisation of the chatbot system prompt,
 * overriding the global admin default and the lang string fallback.
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Edit form for a block_ragchat instance.
 */
class block_ragchat_edit_form extends block_edit_form {

    /**
     * Add instance-specific fields to the block configuration form.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    protected function specific_definition($mform): void {

        $mform->addElement('header', 'configheader', get_string('editform_header', 'block_ragchat'));

        // Custom block title.
        $mform->addElement(
            'text',
            'config_title',
            get_string('editform_title', 'block_ragchat'),
            ['size' => 50],
        );
        $mform->setType('config_title', PARAM_TEXT);

        // System prompt override.
        $mform->addElement('header', 'promptheader', get_string('editform_prompt_header', 'block_ragchat'));

        $mform->addElement(
            'textarea',
            'config_systemprompt',
            get_string('editform_systemprompt', 'block_ragchat'),
            ['rows' => 10, 'cols' => 80, 'style' => 'width:100%;font-family:monospace;font-size:0.85em;'],
        );
        $mform->setType('config_systemprompt', PARAM_RAW);
        $mform->addHelpButton('config_systemprompt', 'editform_systemprompt', 'block_ragchat');

        // Placeholder hint showing available variables.
        $mform->addElement(
            'static',
            'config_systemprompt_hint',
            '',
            get_string('editform_systemprompt_hint', 'block_ragchat'),
        );
    }
}
