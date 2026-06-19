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
 * Privacy subsystem implementation for block_ragchat.
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ragchat\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider — stores user questions and AI answers in block_ragchat_log.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe data stored.
     *
     * @param  collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'block_ragchat_log',
            [
                'userid'       => 'privacy:metadata:block_ragchat_log:userid',
                'courseid'     => 'privacy:metadata:block_ragchat_log:courseid',
                'collectionid' => 'privacy:metadata:block_ragchat_log:collectionid',
                'question'     => 'privacy:metadata:block_ragchat_log:question',
                'answer'       => 'privacy:metadata:block_ragchat_log:answer',
                'timecreated'  => 'privacy:metadata:block_ragchat_log:timecreated',
            ],
            'privacy:metadata:block_ragchat_log',
        );

        $collection->add_external_location_link(
            'albert_api',
            ['question' => 'privacy:metadata:albert_api:question'],
            'privacy:metadata:albert_api',
        );

        return $collection;
    }

    /**
     * Get contexts containing data for the given user.
     *
     * @param  int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    /**
     * Get users in context.
     *
     * @param  userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            'SELECT DISTINCT userid FROM {block_ragchat_log}',
            [],
        );
    }

    /**
     * Export user data.
     *
     * @param  approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $rows   = $DB->get_records('block_ragchat_log', ['userid' => $userid], 'timecreated ASC');

        foreach ($rows as $row) {
            $context = \context_system::instance();
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'block_ragchat'), $row->id],
                (object) [
                    'question'    => $row->question,
                    'answer'      => $row->answer,
                    'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                ],
            );
        }
    }

    /**
     * Delete all data for all users in the given context list.
     *
     * @param  approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\core_privacy\local\request\context $context): void {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records('block_ragchat_log');
        }
    }

    /**
     * Delete data for users in a context.
     *
     * @param  approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select('block_ragchat_log', "userid {$insql}", $inparams);
    }

    /**
     * Delete all data for the given user.
     *
     * @param  approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $DB->delete_records('block_ragchat_log', ['userid' => $contextlist->get_user()->id]);
    }
}
