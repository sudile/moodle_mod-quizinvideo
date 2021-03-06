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

namespace quizinvideo_statistics;
defined('MOODLE_INTERNAL') || die();

/**
 * Class to calculate and also manage caching of quizinvideo statistics.
 *
 * These quizinvideo statistics calculations are described here :
 *
 * http://docs.moodle.org/dev/quizinvideo_statistics_calculations#Test_statistics
 *
 * @package    quizinvideo_statistics
 * @copyright  2013 The Open University
 * @author     James Pratt me@jamiep.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calculator {

    /**
     * @var \core\progress\base
     */
    protected $progress;

    public function __construct(\core\progress\base $progress = null) {
        if ($progress === null) {
            $progress = new \core\progress\null();
        }
        $this->progress = $progress;
    }

    /**
     * Compute the quizinvideo statistics.
     *
     * @param int   $quizinvideoid            the quizinvideo id.
     * @param int $whichattempts which attempts to use, represented internally as one of the constants as used in
     *                                   $quizinvideo->grademethod ie.
     *                                   quizinvideo_GRADEAVERAGE, quizinvideo_GRADEHIGHEST, quizinvideo_ATTEMPTLAST or quizinvideo_ATTEMPTFIRST
     *                                   we calculate stats based on which attempts would affect the grade for each student.
     * @param array $groupstudents     students in this group.
     * @param int   $p                 number of positions (slots).
     * @param float $sumofmarkvariance sum of mark variance, calculated as part of question statistics
     * @return calculated $quizinvideostats The statistics for overall attempt scores.
     */
    public function calculate($quizinvideoid, $whichattempts, $groupstudents, $p, $sumofmarkvariance) {

        $this->progress->start_progress('', 3);

        $quizinvideostats = new calculated($whichattempts);

        $countsandaverages = $this->attempt_counts_and_averages($quizinvideoid, $groupstudents);
        $this->progress->progress(1);

        foreach ($countsandaverages as $propertyname => $value) {
            $quizinvideostats->{$propertyname} = $value;
        }

        $s = $quizinvideostats->s();
        if ($s != 0) {

            // Recalculate sql again this time possibly including test for first attempt.
            list($fromqa, $whereqa, $qaparams) =
                quizinvideo_statistics_attempts_sql($quizinvideoid, $groupstudents, $whichattempts);

            $quizinvideostats->median = $this->median($s, $fromqa, $whereqa, $qaparams);
            $this->progress->progress(2);

            if ($s > 1) {

                $powers = $this->sum_of_powers_of_difference_to_mean($quizinvideostats->avg(), $fromqa, $whereqa, $qaparams);
                $this->progress->progress(3);

                $quizinvideostats->standarddeviation = sqrt($powers->power2 / ($s - 1));

                // Skewness.
                if ($s > 2) {
                    // See http://docs.moodle.org/dev/quizinvideo_item_analysis_calculations_in_practise#Skewness_and_Kurtosis.
                    $m2 = $powers->power2 / $s;
                    $m3 = $powers->power3 / $s;
                    $m4 = $powers->power4 / $s;

                    $k2 = $s * $m2 / ($s - 1);
                    $k3 = $s * $s * $m3 / (($s - 1) * ($s - 2));
                    if ($k2 != 0) {
                        $quizinvideostats->skewness = $k3 / (pow($k2, 3 / 2));

                        // Kurtosis.
                        if ($s > 3) {
                            $k4 = $s * $s * ((($s + 1) * $m4) - (3 * ($s - 1) * $m2 * $m2)) / (($s - 1) * ($s - 2) * ($s - 3));
                            $quizinvideostats->kurtosis = $k4 / ($k2 * $k2);
                        }

                        if ($p > 1) {
                            $quizinvideostats->cic = (100 * $p / ($p - 1)) * (1 - ($sumofmarkvariance / $k2));
                            $quizinvideostats->errorratio = 100 * sqrt(1 - ($quizinvideostats->cic / 100));
                            $quizinvideostats->standarderror = $quizinvideostats->errorratio *
                                $quizinvideostats->standarddeviation / 100;
                        }
                    }

                }
            }

            $quizinvideostats->cache(quizinvideo_statistics_qubaids_condition($quizinvideoid, $groupstudents, $whichattempts));
        }
        $this->progress->end_progress();
        return $quizinvideostats;
    }

    /** @var integer Time after which statistics are automatically recomputed. */
    const TIME_TO_CACHE = 900; // 15 minutes.

    /**
     * Load cached statistics from the database.
     *
     * @param $qubaids \qubaid_condition
     * @return calculated The statistics for overall attempt scores or false if not cached.
     */
    public function get_cached($qubaids) {
        global $DB;

        $timemodified = time() - self::TIME_TO_CACHE;
        $fromdb = $DB->get_record_select('quizinvideo_statistics', 'hashcode = ? AND timemodified > ?',
                                         array($qubaids->get_hash_code(), $timemodified));
        $stats = new calculated();
        $stats->populate_from_record($fromdb);
        return $stats;
    }

    /**
     * Find time of non-expired statistics in the database.
     *
     * @param $qubaids \qubaid_condition
     * @return integer|boolean Time of cached record that matches this qubaid_condition or false is non found.
     */
    public function get_last_calculated_time($qubaids) {
        global $DB;

        $timemodified = time() - self::TIME_TO_CACHE;
        return $DB->get_field_select('quizinvideo_statistics', 'timemodified', 'hashcode = ? AND timemodified > ?',
                                         array($qubaids->get_hash_code(), $timemodified));
    }

    /**
     * Given a particular quizinvideo grading method return a lang string describing which attempts contribute to grade.
     *
     * Note internally we use the grading method constants to represent which attempts we are calculating statistics for, each
     * grading method corresponds to different attempts for each user.
     *
     * @param  int $whichattempts which attempts to use, represented internally as one of the constants as used in
     *                                   $quizinvideo->grademethod ie.
     *                                   quizinvideo_GRADEAVERAGE, quizinvideo_GRADEHIGHEST, quizinvideo_ATTEMPTLAST or quizinvideo_ATTEMPTFIRST
     *                                   we calculate stats based on which attempts would affect the grade for each student.
     * @return string the appropriate lang string to describe this option.
     */
    public static function using_attempts_lang_string($whichattempts) {
         return get_string(static::using_attempts_string_id($whichattempts), 'quizinvideo_statistics');
    }

    /**
     * Given a particular quizinvideo grading method return a string id for use as a field name prefix in mdl_quizinvideo_statistics or to
     * fetch the appropriate language string describing which attempts contribute to grade.
     *
     * Note internally we use the grading method constants to represent which attempts we are calculating statistics for, each
     * grading method corresponds to different attempts for each user.
     *
     * @param  int $whichattempts which attempts to use, represented internally as one of the constants as used in
     *                                   $quizinvideo->grademethod ie.
     *                                   quizinvideo_GRADEAVERAGE, quizinvideo_GRADEHIGHEST, quizinvideo_ATTEMPTLAST or quizinvideo_ATTEMPTFIRST
     *                                   we calculate stats based on which attempts would affect the grade for each student.
     * @return string the string id for this option.
     */
    public static function using_attempts_string_id($whichattempts) {
        switch ($whichattempts) {
            case quizinvideo_ATTEMPTFIRST :
                return 'firstattempts';
            case quizinvideo_GRADEHIGHEST :
                return 'highestattempts';
            case quizinvideo_ATTEMPTLAST :
                return 'lastattempts';
            case quizinvideo_GRADEAVERAGE :
                return 'allattempts';
        }
    }

    /**
     * Calculating count and mean of marks for first and ALL attempts by students.
     *
     * See : http://docs.moodle.org/dev/quizinvideo_item_analysis_calculations_in_practise
     *                                      #Calculating_MEAN_of_grades_for_all_attempts_by_students
     * @param int $quizinvideoid
     * @param array $groupstudents
     * @return \stdClass with properties with count and avg with prefixes firstattempts, highestattempts, etc.
     */
    protected function attempt_counts_and_averages($quizinvideoid, $groupstudents) {
        global $DB;

        $attempttotals = new \stdClass();
        foreach (array_keys(quizinvideo_get_grading_options()) as $which) {

            list($fromqa, $whereqa, $qaparams) = quizinvideo_statistics_attempts_sql($quizinvideoid, $groupstudents, $which);

            $fromdb = $DB->get_record_sql("SELECT COUNT(*) AS rcount, AVG(sumgrades) AS average FROM $fromqa WHERE $whereqa",
                                            $qaparams);
            $fieldprefix = static::using_attempts_string_id($which);
            $attempttotals->{$fieldprefix.'avg'} = $fromdb->average;
            $attempttotals->{$fieldprefix.'count'} = $fromdb->rcount;
        }
        return $attempttotals;
    }

    /**
     * Median mark.
     *
     * http://docs.moodle.org/dev/quizinvideo_statistics_calculations#Median_Score
     *
     * @param $s integer count of attempts
     * @param $fromqa string
     * @param $whereqa string
     * @param $qaparams string
     * @return float
     */
    protected function median($s, $fromqa, $whereqa, $qaparams) {
        global $DB;

        if ($s % 2 == 0) {
            // An even number of attempts.
            $limitoffset = $s / 2 - 1;
            $limit = 2;
        } else {
            $limitoffset = floor($s / 2);
            $limit = 1;
        }
        $sql = "SELECT id, sumgrades
                FROM $fromqa
                WHERE $whereqa
                ORDER BY sumgrades";

        $medianmarks = $DB->get_records_sql_menu($sql, $qaparams, $limitoffset, $limit);

        return array_sum($medianmarks) / count($medianmarks);
    }

    /**
     * Fetch the sum of squared, cubed and to the power 4 differences between sumgrade and it's mean.
     *
     * Explanation here : http://docs.moodle.org/dev/quizinvideo_item_analysis_calculations_in_practise
     *              #Calculating_Standard_Deviation.2C_Skewness_and_Kurtosis_of_grades_for_all_attempts_by_students
     *
     * @param $mean
     * @param $fromqa
     * @param $whereqa
     * @param $qaparams
     * @return object with properties power2, power3, power4
     */
    protected function sum_of_powers_of_difference_to_mean($mean, $fromqa, $whereqa, $qaparams) {
        global $DB;

        $sql = "SELECT
                    SUM(POWER((quizinvideoa.sumgrades - $mean), 2)) AS power2,
                    SUM(POWER((quizinvideoa.sumgrades - $mean), 3)) AS power3,
                    SUM(POWER((quizinvideoa.sumgrades - $mean), 4)) AS power4
                    FROM $fromqa
                    WHERE $whereqa";
        $params = array('mean1' => $mean, 'mean2' => $mean, 'mean3' => $mean) + $qaparams;

        return $DB->get_record_sql($sql, $params, MUST_EXIST);
    }

}
