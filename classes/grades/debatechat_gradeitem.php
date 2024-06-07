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
 * Grade item storage for mod_debatechat.
 *
 * @package   mod_debatechat
 * @copyright Andrew Nicols <andrew@nicols.co.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types = 1);

namespace mod_debatechat\grades;

use coding_exception;
use context;
use core_grades\component_gradeitem;
use core_grades\local\gradeitem as gradeitem;
use mod_debatechat\local\container as debatechat_container;
use mod_debatechat\local\entities\debatechat as debatechat_entity;
use required_capability_exception;
use stdClass;

/**
 * Grade item storage for mod_debatechat.
 *
 * @package   mod_debatechat
 * @copyright Andrew Nicols <andrew@nicols.co.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debatechat_gradeitem extends component_gradeitem {
    /** @var debatechat_entity The debatechat entity being graded */
    protected $debatechat;

    /**
     * Return an instance based on the context in which it is used.
     *
     * @param context $context
     */
    public static function load_from_context(context $context): parent {
        // Get all the factories that are required.
        $vaultfactory = debatechat_container::get_vault_factory();
        $debatechatvault = $vaultfactory->get_debatechat_vault();

        $debatechat = $debatechatvault->get_from_course_module_id((int) $context->instanceid);

        return static::load_from_debatechat_entity($debatechat);
    }

    /**
     * Return an instance using the debatechat_entity instance.
     *
     * @param debatechat_entity $debatechat
     *
     * @return debatechat_gradeitem
     */
    public static function load_from_debatechat_entity(debatechat_entity $debatechat): self {
        $instance = new static('mod_debatechat', $debatechat->get_context(), 'debatechat');
        $instance->debatechat = $debatechat;

        return $instance;
    }

    /**
     * The table name used for grading.
     *
     * @return string
     */
    protected function get_table_name(): string {
        return 'debatechat_grades';
    }

    /**
     * Whether grading is enabled for this item.
     *
     * @return bool
     */
    public function is_grading_enabled(): bool {
        return $this->debatechat->is_grading_enabled();
    }

    /**
     * Whether the grader can grade the gradee.
     *
     * @param stdClass $gradeduser The user being graded
     * @param stdClass $grader The user who is grading
     * @return bool
     */
    public function user_can_grade(stdClass $gradeduser, stdClass $grader): bool {
        // Validate the required capabilities.
        $managerfactory = debatechat_container::get_manager_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($this->debatechat);

        return $capabilitymanager->can_grade($grader, $gradeduser);
    }

    /**
     * Require that the user can grade, throwing an exception if not.
     *
     * @param stdClass $gradeduser The user being graded
     * @param stdClass $grader The user who is grading
     * @throws required_capability_exception
     */
    public function require_user_can_grade(stdClass $gradeduser, stdClass $grader): void {
        if (!$this->user_can_grade($gradeduser, $grader)) {
            throw new required_capability_exception($this->debatechat->get_context(), 'mod/debatechat:grade', 'nopermissions', '');
        }
    }

    /**
     * Get the grade value for this instance.
     * The itemname is translated to the relevant grade field on the debatechat entity.
     *
     * @return int
     */
    protected function get_gradeitem_value(): int {
        $getter = "get_grade_for_{$this->itemname}";

        return $this->debatechat->{$getter}();
    }

    /**
     * Create an empty debatechat_grade for the specified user and grader.
     *
     * @param stdClass $gradeduser The user being graded
     * @param stdClass $grader The user who is grading
     * @return stdClass The newly created grade record
     * @throws \dml_exception
     */
    public function create_empty_grade(stdClass $gradeduser, stdClass $grader): stdClass {
        global $DB;

        $grade = (object) [
            'debatechat' => $this->debatechat->get_id(),
            'itemnumber' => $this->itemnumber,
            'userid' => $gradeduser->id,
            'timemodified' => time(),
        ];
        $grade->timecreated = $grade->timemodified;

        $gradeid = $DB->insert_record($this->get_table_name(), $grade);

        return $DB->get_record($this->get_table_name(), ['id' => $gradeid]);
    }

    /**
     * Get the grade for the specified user.
     *
     * @param stdClass $gradeduser The user being graded
     * @param stdClass $grader The user who is grading
     * @return stdClass The grade value
     * @throws \dml_exception
     */
    public function get_grade_for_user(stdClass $gradeduser, stdClass $grader = null): ?stdClass {
        global $DB;

        $params = [
            'debatechat' => $this->debatechat->get_id(),
            'itemnumber' => $this->itemnumber,
            'userid' => $gradeduser->id,
        ];

        $grade = $DB->get_record($this->get_table_name(), $params);

        if (empty($grade)) {
            $grade = $this->create_empty_grade($gradeduser, $grader);
        }

        return $grade ?: null;
    }

    /**
     * Get the grade status for the specified user.
     * Check if a grade obj exists & $grade->grade !== null.
     * If the user has a grade return true.
     *
     * @param stdClass $gradeduser The user being graded
     * @return bool The grade exists
     * @throws \dml_exception
     */
    public function user_has_grade(stdClass $gradeduser): bool {
        global $DB;

        $params = [
            'debatechat' => $this->debatechat->get_id(),
            'itemnumber' => $this->itemnumber,
            'userid' => $gradeduser->id,
        ];

        $grade = $DB->get_record($this->get_table_name(), $params);

        if (empty($grade) || $grade->grade === null) {
            return false;
        }
        return true;
    }

    /**
     * Get grades for all users for the specified gradeitem.
     *
     * @return stdClass[] The grades
     * @throws \dml_exception
     */
    public function get_all_grades(): array {
        global $DB;

        return $DB->get_records($this->get_table_name(), [
            'debatechat' => $this->debatechat->get_id(),
            'itemnumber' => $this->itemnumber,
        ]);
    }

    /**
     * Get the grade item instance id.
     *
     * This is typically the cmid in the case of an activity, and relates to the iteminstance field in the grade_items
     * table.
     *
     * @return int
     */
    public function get_grade_instance_id(): int {
        return (int) $this->debatechat->get_id();
    }

    /**
     * Defines whether only active users in the course should be gradeable.
     *
     * @return bool Whether only active users in the course should be gradeable.
     */
    public function should_grade_only_active_users(): bool {
        global $CFG;

        $showonlyactiveenrolconfig = !empty($CFG->grade_report_showonlyactiveenrol);
        // Grade only active users enrolled in the course either when the 'grade_report_showonlyactiveenrol' user
        // preference is set to true or the current user does not have the capability to view suspended users in the
        // course. In cases where the 'grade_report_showonlyactiveenrol' user preference is not set we are falling back
        // to the set value for the 'grade_report_showonlyactiveenrol' config.
        return get_user_preferences('grade_report_showonlyactiveenrol', $showonlyactiveenrolconfig) ||
            !has_capability('moodle/course:viewsuspendedusers', \context_course::instance($this->debatechat->get_course_id()));
    }

    /**
     * Create or update the grade.
     *
     * @param stdClass $grade
     * @return bool Success
     * @throws \dml_exception
     * @throws \moodle_exception
     * @throws coding_exception
     */
    protected function store_grade(stdClass $grade): bool {
        global $CFG, $DB;
        require_once("{$CFG->dirroot}/mod/debatechat/lib.php");

        if ($grade->debatechat != $this->debatechat->get_id()) {
            throw new coding_exception('Incorrect debatechat provided for this grade');
        }

        if ($grade->itemnumber != $this->itemnumber) {
            throw new coding_exception('Incorrect itemnumber provided for this grade');
        }

        // Ensure that the grade is valid.
        $this->check_grade_validity($grade->grade);

        $grade->debatechat = $this->debatechat->get_id();
        $grade->timemodified = time();

        $DB->update_record($this->get_table_name(), $grade);

        // Update in the gradebook (note that 'cmidnumber' is required in order to update grades).
        $mapper = debatechat_container::get_legacy_data_mapper_factory()->get_debatechat_data_mapper();
        $debatechatrecord = $mapper->to_legacy_object($this->debatechat);
        $debatechatrecord->cmidnumber = $this->debatechat->get_course_module_record()->idnumber;

        debatechat_update_grades($debatechatrecord, $grade->userid);

        return true;
    }
}
