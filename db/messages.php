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
 * Defines message providers (types of message sent) for the quizinvideo module.
 *
 * @package   mod_quizinvideo
 * @copyright 2010 Andrew Davis http://moodle.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = array(
    // Notify teacher that a student has submitted a quizinvideo attempt.
    'submission' => array(
        'capability' => 'mod/quizinvideo:emailnotifysubmission'
    ),

    // Confirm a student's quizinvideo attempt.
    'confirmation' => array(
        'capability' => 'mod/quizinvideo:emailconfirmsubmission'
    ),

    // Warning to the student that their quizinvideo attempt is now overdue, if the quizinvideo
    // has a grace period.
    'attempt_overdue' => array(
        'capability' => 'mod/quizinvideo:emailwarnoverdue'
    ),
);
