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
 * Scheduled task — synchronise the Moodle course catalogue to Albert.
 *
 * Strategy (full reset, runs nightly):
 *  1. Ensure the 'catalogue_moodle' collection exists in Albert.
 *  2. Delete all existing documents in the collection (clean slate).
 *  3. For every visible, non-hidden course:
 *     a. Build a rich text "fiche" (title, summary, category, teacher names,
 *        enrolment count, tags).
 *     b. Upload the fiche as a plain-text file via Albert /v1/files.
 *     c. Index it via Albert /v1/documents.
 *  4. Write a completion timestamp to config.
 *
 * @package    block_ragchat
 * @copyright  2026 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ragchat\task;

use block_ragchat\albert_client;

/**
 * Nightly catalogue sync task.
 */
class sync_catalogue extends \core\task\scheduled_task {

    /** Albert collection name for the Moodle catalogue. */
    const COLLECTION = 'catalogue_moodle';

    /**
     * Human-readable task name (shown in admin UI).
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_catalogue', 'block_ragchat');
    }

    /**
     * Execute the synchronisation.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $client = new albert_client();
        if (!$client->is_configured()) {
            mtrace('block_ragchat: Albert API not configured — skipping catalogue sync.');
            return;
        }

        mtrace('block_ragchat: Starting catalogue sync → ' . self::COLLECTION);

        // 1. Ensure collection exists.
        $this->ensure_collection($client);

        // 2. Reset collection (delete all existing documents).
        try {
            $client->reset_collection(self::COLLECTION);
            mtrace('block_ragchat: Collection reset.');
        } catch (\moodle_exception $e) {
            // If the collection has no documents yet, reset may return 404 — acceptable.
            mtrace('block_ragchat: Reset warning (may be empty): ' . $e->getMessage());
        }

        // 3. Fetch all visible courses (exclude site course id=1).
        $courses = $DB->get_records_select(
            'course',
            'id > 1 AND visible = 1',
            [],
            'fullname ASC',
            'id, fullname, shortname, summary, category, startdate, enddate',
        );

        $count   = 0;
        $errors  = 0;
        $total   = count($courses);
        mtrace("block_ragchat: Processing {$total} courses.");

        foreach ($courses as $course) {
            try {
                $fiche   = $this->build_course_fiche($course);
                $fileid  = $client->upload_file("course_{$course->id}.txt", $fiche);
                $client->index_document($fileid, self::COLLECTION);
                $count++;

                if ($count % 10 === 0) {
                    mtrace("block_ragchat: {$count}/{$total} indexed…");
                }
            } catch (\moodle_exception $e) {
                $errors++;
                mtrace("block_ragchat: Error on course {$course->id} ({$course->shortname}): " . $e->getMessage());
            }
        }

        // 4. Save completion timestamp.
        set_config('catalogue_last_sync', time(), 'block_ragchat');

        mtrace("block_ragchat: Catalogue sync complete — {$count} indexed, {$errors} errors.");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure the Albert collection exists, creating it if necessary.
     *
     * @param  albert_client $client
     * @return void
     */
    private function ensure_collection(albert_client $client): void {
        $collections = $client->list_collections();
        $names = array_map(fn($c) => $c->name ?? '', $collections);

        if (!in_array(self::COLLECTION, $names, true)) {
            $client->create_collection(self::COLLECTION);
            mtrace('block_ragchat: Collection created: ' . self::COLLECTION);
        }
    }

    /**
     * Build a rich plain-text "fiche" for a course, suitable for embedding.
     *
     * @param  \stdClass $course Minimal course record from DB.
     * @return string
     */
    private function build_course_fiche(\stdClass $course): string {
        global $DB;

        // Category name.
        $category = $DB->get_field('course_categories', 'name', ['id' => $course->category]);

        // Teacher names (role shortname 'editingteacher' or 'teacher').
        $context  = \context_course::instance($course->id);
        $teachers = get_role_users(
            array_keys(get_roles_with_capability('moodle/course:manageactivities', CAP_ALLOW, $context)),
            $context,
            false,
            'u.firstname, u.lastname',
            null,
            false,
            '',
            '',
            '',
            5, // Max 5 teachers.
        );
        $teachernames = implode(', ', array_map(
            fn($t) => fullname($t),
            $teachers,
        ));

        // Enrolment count.
        $enrolled = count_enrolled_users($context);

        // Tags.
        $tags = \core_tag_tag::get_item_tags_array('core', 'course', $course->id);

        // Clean summary (strip HTML, limit length).
        $summary = html_to_text(format_text($course->summary, FORMAT_HTML), 0, false);
        $summary = \core_text::substr(trim($summary), 0, 2000);

        // Format dates.
        $startdate = $course->startdate ? userdate($course->startdate, get_string('strftimedate', 'langconfig')) : '';
        $enddate   = $course->enddate   ? userdate($course->enddate,   get_string('strftimedate', 'langconfig')) : '';

        $lines = [
            "Titre : {$course->fullname}",
            "Code : {$course->shortname}",
            "Catégorie : {$category}",
        ];

        if ($teachernames) {
            $lines[] = "Enseignants : {$teachernames}";
        }
        if ($enrolled > 0) {
            $lines[] = "Inscrits : {$enrolled}";
        }
        if ($startdate) {
            $lines[] = "Début : {$startdate}" . ($enddate ? "  —  Fin : {$enddate}" : '');
        }
        if (!empty($tags)) {
            $lines[] = "Mots-clés : " . implode(', ', $tags);
        }
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = "Description :";
            $lines[] = $summary;
        }

        return implode("\n", $lines);
    }
}
