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
 * This page deals with processing responses during an attempt at a quizinvideo.
 *
 * People will normally arrive here from a form submission on attempt.php or
 * summary.php, and once the responses are processed, they will be redirected to
 * attempt.php or summary.php.
 *
 * This code used to be near the top of attempt.php, if you are looking for CVS history.
 *
 * @package   mod_quizinvideo
 * @copyright 2009 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/quizinvideo/locallib.php');

// Remember the current time as the time any responses were submitted
// (so as to make sure students don't get penalized for slow processing on this page).
$timenow = time();

// Get submitted parameters.
$attemptid     = required_param('attempt',  PARAM_INT);
$thispage      = optional_param('thispage', 0, PARAM_INT);
$nextpage      = optional_param('nextpage', 0, PARAM_INT);
$next          = optional_param('next',          false, PARAM_BOOL);
$finishattempt = optional_param('finishattempt', false, PARAM_BOOL);
$timeup        = optional_param('timeup',        0,      PARAM_BOOL); // True if form was submitted by timer.
$scrollpos     = optional_param('scrollpos',     '',     PARAM_RAW);

$transaction = $DB->start_delegated_transaction();
$attemptobj = quizinvideo_attempt::create($attemptid);

$review_url = new moodle_url('/mod/quizinvideo/review.php',
    array('attempt' => $attemptid, 'processingattempt' => true, 'page' => $thispage));
// Set $nexturl now.
$nexturl = $review_url;

// If there is only a very small amount of time left, there is no point trying
// to show the student another page of the quizinvideo. Just finish now.
$graceperiodmin = null;
$accessmanager = $attemptobj->get_access_manager($timenow);
$timeclose = $accessmanager->get_end_time($attemptobj->get_attempt());

// Don't enforce timeclose for previews
if ($attemptobj->is_preview()) {
    $timeclose = false;
}
$toolate = false;
if ($timeclose !== false && $timenow > $timeclose - quizinvideo_MIN_TIME_TO_CONTINUE) {
    $timeup = true;
    $graceperiodmin = get_config('quizinvideo', 'graceperiodmin');
    if ($timenow > $timeclose + $graceperiodmin) {
        $toolate = true;
    }
}

// Check login.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());
require_sesskey();

// Check that this attempt belongs to this user.
if ($attemptobj->get_userid() != $USER->id) {
    throw new moodle_quizinvideo_exception($attemptobj->get_quizinvideoobj(), 'notyourattempt');
}

// Check capabilities.
if (!$attemptobj->is_preview_user()) {
    $attemptobj->require_capability('mod/quizinvideo:attempt');
}

// If the attempt is already closed, send them to the review page.
if ($attemptobj->is_finished()) {
    redirect($review_url);
}

// If time is running out, trigger the appropriate action.
$becomingoverdue = false;
$becomingabandoned = false;
if ($timeup) {
    if ($attemptobj->get_quizinvideo()->overduehandling == 'graceperiod') {
        if (is_null($graceperiodmin)) {
            $graceperiodmin = get_config('quizinvideo', 'graceperiodmin');
        }
        if ($timenow > $timeclose + $attemptobj->get_quizinvideo()->graceperiod + $graceperiodmin) {
            // Grace period has run out.
            $finishattempt = true;
            $becomingabandoned = true;
        } else {
            $becomingoverdue = true;
        }
    } else {
        $finishattempt = true;
    }
}

// Don't log - we will end with a redirect to a page that is logged.

if (!$finishattempt) {
    // Just process the responses for this page and go to the next page.
    if (!$toolate) {
        try {
            $attemptobj->process_submitted_actions($timenow, $becomingoverdue);

        } catch (question_out_of_sequence_exception $e) {
            print_error('submissionoutofsequencefriendlymessage', 'question',
                    $attemptobj->attempt_url(null, $thispage));

        } catch (Exception $e) {
            // This sucks, if we display our own custom error message, there is no way
            // to display the original stack trace.
            $debuginfo = '';
            if (!empty($e->debuginfo)) {
                $debuginfo = $e->debuginfo;
            }
            print_error('errorprocessingresponses', 'question',
                    $attemptobj->attempt_url(null, $thispage), $e->getMessage(), $debuginfo);
        }

        if (!$becomingoverdue) {
            foreach ($attemptobj->get_slots() as $slot) {
                if (optional_param('redoslot' . $slot, false, PARAM_BOOL)) {
                    $attemptobj->process_redo_question($slot, $timenow);
                }
            }
        }

    } else {
        // The student is too late.
        $attemptobj->process_going_overdue($timenow, true);
    }

    $transaction->allow_commit();
    if ($becomingoverdue) {
        redirect($attemptobj->summary_url());
    } else {
        redirect($nexturl);
    }
}

// Update the quizinvideo attempt record.
try {
    if ($becomingabandoned) {
        $attemptobj->process_abandon($timenow, true);
    } else {
        $attemptobj->process_finish($timenow, !$toolate);
    }

} catch (question_out_of_sequence_exception $e) {
    print_error('submissionoutofsequencefriendlymessage', 'question',
            $attemptobj->attempt_url(null, $thispage));

} catch (Exception $e) {
    // This sucks, if we display our own custom error message, there is no way
    // to display the original stack trace.
    $debuginfo = '';
    if (!empty($e->debuginfo)) {
        $debuginfo = $e->debuginfo;
    }
    print_error('errorprocessingresponses', 'question',
            $attemptobj->attempt_url(null, $thispage), $e->getMessage(), $debuginfo);
}

// Send the user to the review page.
$transaction->allow_commit();
redirect($review_url);
