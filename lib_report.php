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
 * Shared functions between report.php and generate_report_pdf.php
 * Optimized version: bulk data preloading to avoid N×M queries.
 * Requires Moodle 5.0+ (scorm_attempt/element/scoes_value).
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

function block_scormreport_get_data($courseid, $userid, $scormid, $context, $course, $DB, $CFG) {

    // 1. SCORM activities in the course
    $scorms = $DB->get_records_sql(
        "SELECT s.id, s.name FROM {scorm} s
           JOIN {course_modules} cm ON cm.instance = s.id
           JOIN {modules} m ON m.id = cm.module
          WHERE s.course = :courseid AND m.name = 'scorm'
            AND cm.deletioninprogress = 0 ORDER BY s.name",
        ['courseid' => $courseid]
    );
    $totalscorms = count($scorms);
    if ($totalscorms === 0) {
        return ['students' => [], 'scorms' => [], 'globaldata' => [],
                'detaildata' => [], 'coursehours' => '', 'totalscorms' => 0];
    }

    // 2. Enrolled students using the Moodle API
    $students = [];
    $enrolledusers = get_enrolled_users($context, 'block/scormreport:viewownreport', 0,
        'u.id, u.firstname, u.lastname, u.email, u.idnumber, u.institution, u.username,
         u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
        'u.lastname ASC, u.firstname ASC', 0, 0, false); // false = incluir activos e inactivos
    foreach ($enrolledusers as $u) {
        $students[$u->id] = $u;
    }

    // 3. Course hours (configurable custom field)
    $hoursfield = get_config('block_scormreport', 'hours_field') ?: 'hours';
    $coursehours = '';
    $customfield = $DB->get_record_sql(
        "SELECT cd.value FROM {customfield_data} cd
           JOIN {customfield_field} cf ON cf.id = cd.fieldid
          WHERE cf.shortname = :fieldname AND cd.instanceid = :courseid",
        ['fieldname' => $hoursfield, 'courseid' => $courseid]
    );
    if ($customfield && !empty($customfield->value)) {
        $coursehours = $customfield->value;
    }

    // Filter IDs for bulk queries
    $scormids = array_keys($scorms);
    $studentids = array_keys($students);
    if ($userid) {
        $studentids = array_intersect($studentids, [$userid]);
    }
    if ($scormid) {
        $scormids = array_intersect($scormids, [$scormid]);
    }
    if (empty($studentids) || empty($scormids)) {
        return compact('students', 'scorms', 'coursehours', 'totalscorms')
             + ['globaldata' => [], 'detaildata' => []];
    }

    // 5. SCOs per SCORM
    list($insql_sc, $params_sc) = $DB->get_in_or_equal($scormids, SQL_PARAMS_NAMED, 'sc');
    $allscos = $DB->get_records_sql(
        "SELECT id, scorm, title FROM {scorm_scoes}
          WHERE scorm $insql_sc AND launch != ''
          ORDER BY id ASC",
        $params_sc
    );
    $scosbyscorm = [];
    foreach ($allscos as $sco) {
        $scosbyscorm[$sco->scorm][$sco->id] = $sco;
    }
    $scocounts = [];
    foreach ($scosbyscorm as $sid => $scolist) {
        $scocounts[$sid] = count($scolist);
    }

    // Recalculate totalscorms counting individual SCOs (not SCORM packages)
    // A single SCORM = 1 item. A Multi-SCO with 11 sub-SCOs = 11 items.
    $totalscorms = 0;
    foreach ($scorms as $scorm) {
        $totalscorms += max(1, $scocounts[$scorm->id] ?? 1);
    }

    // ─── Version-dependent queries ─────────────────────────

        // 4. Relevant CMI element IDs
        $elementnames = [
            'cmi.core.total_time', 'cmi.total_time',
            'cmi.core.session_time', 'cmi.session_time',
            'cmi.core.score.raw', 'cmi.score.raw',
            'cmi.core.lesson_status', 'cmi.completion_status', 'cmi.success_status',
        ];
        list($insql_el, $params_el) = $DB->get_in_or_equal($elementnames, SQL_PARAMS_NAMED, 'nm');
        $elementrows = $DB->get_records_sql(
            "SELECT id, element FROM {scorm_element} WHERE element $insql_el", $params_el
        );
        $elementids = [];
        foreach ($elementrows as $el) {
            $elementids[$el->element] = $el->id;
        }

        // 6. Attempts per (userid, scormid) — ALL + last for status
        list($insql_u, $params_u) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'us');
        list($insql_sc2, $params_sc2) = $DB->get_in_or_equal($scormids, SQL_PARAMS_NAMED, 'xsc');
        list($insql_u2, $params_u2)   = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'xus');

        // 6a. All attempts (for historical time and dates)
        $allattemptrows = $DB->get_records_sql(
            "SELECT sa.id, sa.userid, sa.scormid, sa.attempt
               FROM {scorm_attempt} sa
              WHERE sa.scormid $insql_sc AND sa.userid $insql_u",
            array_merge($params_sc, $params_u)
        );
        $allattemptids = [];
        $allattemptmap = []; // [userid][scormid] => [attemptid1, attemptid2, ...]
        $attemptcountmap = []; // [userid][scormid] => count
        foreach ($allattemptrows as $row) {
            $allattemptids[] = $row->id;
            $allattemptmap[$row->userid][$row->scormid][] = $row->id;
            $attemptcountmap[$row->userid][$row->scormid] = ($attemptcountmap[$row->userid][$row->scormid] ?? 0) + 1;
        }

        // 6b. Last attempt (for academic score/status)
        $attemptrows = $DB->get_records_sql(
            "SELECT sa.id, sa.userid, sa.scormid, sa.attempt
               FROM {scorm_attempt} sa
              INNER JOIN (
                  SELECT userid, scormid, MAX(attempt) AS maxatt
                    FROM {scorm_attempt}
                   WHERE scormid $insql_sc2 AND userid $insql_u2
                   GROUP BY userid, scormid
              ) latest ON sa.userid = latest.userid
                      AND sa.scormid = latest.scormid
                      AND sa.attempt = latest.maxatt
              WHERE sa.scormid $insql_sc AND sa.userid $insql_u",
            array_merge($params_sc, $params_u, $params_sc2, $params_u2)
        );
        $attemptmap = [];
        $attemptids = [];
        foreach ($attemptrows as $row) {
            $attemptmap[$row->userid][$row->scormid] = $row->id;
            $attemptids[] = $row->id;
        }

        // 6c. Test interactions (questions/answers) for the last attempt.
        $interactionsmap = block_scormreport_get_interactions($attemptids, $DB);

        // 7. CMI values (chunked)
        $valuesmap = [];
        if (!empty($attemptids) && !empty($elementids)) {
            $allelementids = array_values($elementids);
            list($insql_elid, $params_elid) = $DB->get_in_or_equal($allelementids, SQL_PARAMS_NAMED, 'ei');
            foreach (array_chunk($attemptids, 500) as $chunk) {
                list($insql_att, $params_att) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'at');
                $cmirows = $DB->get_records_sql(
                    "SELECT id, attemptid, scoid, elementid, value
                       FROM {scorm_scoes_value}
                      WHERE attemptid $insql_att AND elementid $insql_elid",
                    array_merge($params_att, $params_elid)
                );
                foreach ($cmirows as $row) {
                    $valuesmap[$row->attemptid][$row->scoid][$row->elementid] = $row->value;
                }
            }
        }

        // 7b. Time from ALL attempts (historical total time)
        // Maps [userid][scormid] => total seconds across all attempts.
        $timeallmap = [];
        if (!empty($allattemptids) && !empty($elementids)) {
            $timeelnames = ['cmi.core.total_time', 'cmi.total_time', 'cmi.core.session_time', 'cmi.session_time'];
            $timeelids = [];
            foreach ($timeelnames as $en) {
                if (isset($elementids[$en])) $timeelids[$en] = $elementids[$en];
            }
            if (!empty($timeelids)) {
                list($insql_tel, $params_tel) = $DB->get_in_or_equal(array_values($timeelids), SQL_PARAMS_NAMED, 'tei');
                // Map attemptid → [userid, scormid]
                $attemptowner = [];
                foreach ($allattemptrows as $ar) {
                    $attemptowner[$ar->id] = ['userid' => $ar->userid, 'scormid' => $ar->scormid];
                }
                // cmi.core.total_time is CUMULATIVE: each attempt already stores
                // the total accumulated time for that SCO, not just its session.
                // So we keep the MAXIMUM across attempts per SCO (the last attempt
                // already includes the earlier ones), never the sum. This map is
                // kept across chunks so attempts split between chunks still compare.
                $persco_max = []; // key "userid|scormid|scoid" => ['userid','scormid','secs']
                foreach (array_chunk($allattemptids, 500) as $chunk) {
                    list($insql_tat, $params_tat) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'tat');
                    $timerows = $DB->get_records_sql(
                        "SELECT id, attemptid, scoid, elementid, value
                           FROM {scorm_scoes_value}
                          WHERE attemptid $insql_tat AND elementid $insql_tel",
                        array_merge($params_tat, $params_tel)
                    );
                    $perscovalues = [];
                    foreach ($timerows as $tr) {
                        $perscovalues[$tr->attemptid][$tr->scoid][$tr->elementid] = $tr->value;
                    }
                    foreach ($perscovalues as $attid => $scos) {
                        if (!isset($attemptowner[$attid])) continue;
                        $owner = $attemptowner[$attid];
                        $ownerscormid = $owner['scormid'];
                        foreach ($scos as $scoid => $elvals) {
                            $secs = 0;
                            foreach (['cmi.core.total_time', 'cmi.total_time', 'cmi.core.session_time', 'cmi.session_time'] as $en) {
                                $elid = $timeelids[$en] ?? null;
                                if ($elid && isset($elvals[$elid]) && $elvals[$elid] !== '') {
                                    $secs = block_scormreport_parse_scorm_time($elvals[$elid]);
                                    break;
                                }
                            }
                            $key = $owner['userid'] . '|' . $ownerscormid . '|' . $scoid;
                            if (!isset($persco_max[$key]) || $secs > $persco_max[$key]['secs']) {
                                $persco_max[$key] = ['userid' => $owner['userid'], 'scormid' => $ownerscormid, 'secs' => $secs];
                            }
                        }
                    }
                }
                // Add the max time of each SCO to its SCORM total.
                foreach ($persco_max as $info) {
                    $timeallmap[$info['userid']][$info['scormid']] =
                        ($timeallmap[$info['userid']][$info['scormid']] ?? 0) + $info['secs'];
                }
            }
        }

        // 8. Access dates — ALL attempts (chunked)
        $accessmap = [];
        $scoaccessmap = [];
        if (!empty($allattemptids)) {
            foreach (array_chunk($allattemptids, 500) as $chunk) {
                list($insql_att2, $params_att2) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'at2');
                $accessrows = $DB->get_records_sql(
                    "SELECT MIN(sv.id) AS id, sa.userid, sa.scormid, sv.scoid,
                            MIN(sv.timemodified) AS fa, MAX(sv.timemodified) AS la
                       FROM {scorm_scoes_value} sv
                       JOIN {scorm_attempt} sa ON sa.id = sv.attemptid
                      WHERE sv.attemptid $insql_att2
                      GROUP BY sa.userid, sa.scormid, sv.scoid",
                    $params_att2
                );
                foreach ($accessrows as $row) {
                    if (!isset($accessmap[$row->userid][$row->scormid])) {
                        $accessmap[$row->userid][$row->scormid] = (object)['fa' => $row->fa, 'la' => $row->la];
                    } else {
                        $acc = $accessmap[$row->userid][$row->scormid];
                        if ($row->fa && ($row->fa < $acc->fa || !$acc->fa)) $acc->fa = $row->fa;
                        if ($row->la && ($row->la > $acc->la || !$acc->la)) $acc->la = $row->la;
                    }
                    // Merge MIN/MAX for sco access across chunks (not overwrite).
                    if (!isset($scoaccessmap[$row->userid][$row->scormid][$row->scoid])) {
                        $scoaccessmap[$row->userid][$row->scormid][$row->scoid] = (object)[
                            'fa' => $row->fa, 'la' => $row->la,
                        ];
                    } else {
                        $sca = $scoaccessmap[$row->userid][$row->scormid][$row->scoid];
                        if ($row->fa && ($row->fa < $sca->fa || !$sca->fa)) $sca->fa = $row->fa;
                        if ($row->la && ($row->la > $sca->la || !$sca->la)) $sca->la = $row->la;
                    }
                } // foreach accessrows
            } // foreach chunk
        } // if attemptids


    // ═══════════════════════════════════════════════════════════════════════
    // 9. Build report in memory (no more DB queries)
    // ═══════════════════════════════════════════════════════════════════════
    $globaldata = [];
    $detaildata = [];

    foreach ($students as $student) {
        if ($userid && $student->id != $userid) continue;

        $totalseconds = 0;
        $scores = [];
        $seen = 0;
        $passed = 0;
        $firstaccesses = [];
        $lastaccesses = [];
        $scormdetails = []; // Per-SCORM detail for the dropdown

        foreach ($scorms as $scorm) {
            if ($scormid && $scorm->id != $scormid) continue;

            $attemptid = $attemptmap[$student->id][$scorm->id] ?? null;
            $ismultisco = ($scocounts[$scorm->id] ?? 0) > 1;

            if (!$attemptid) {
                $detaildata[] = block_scormreport_empty_detail($student, $scorm);
                // For Multi-SCO packages without attempts, populate SCOs with their actual names
                $emptyscos = [];
                if ($ismultisco && isset($scosbyscorm[$scorm->id])) {
                    foreach ($scosbyscorm[$scorm->id] as $sco) {
                        $emptyscos[] = [
                            'title'       => $sco->title,
                            'seconds'     => 0,
                            'score'       => false,
                            'status'      => 'notattempted',
                            'firstaccess' => '-',
                            'lastaccess'  => '-',
                        ];
                    }
                }
                $scormdetails[] = [
                    'scormname'      => $scorm->name,
                    'timescorm'      => '-',
                    'seconds'        => 0,
                    'score'          => '-',
                    'status'         => 'notattempted',
                    'firstaccess'    => '-',
                    'lastaccess'     => '-',
                    'firstaccess_ts' => 0,
                    'lastaccess_ts'  => 0,
                    'multisco'       => $ismultisco,
                    'pctscos'        => '-',
                    'scos'           => $emptyscos,
                    'passed'         => false,
                ];
                continue;
            }

            if ($ismultisco) {
                $data = block_scormreport_build_multisco(
                    $scorm, $student, $attemptid, $elementids,
                    $valuesmap, $scoaccessmap, $scosbyscorm[$scorm->id] ?? []
                );
            } else {
                $data = block_scormreport_build_singlesco(
                    $scorm, $student, $attemptid, $elementids,
                    $valuesmap, $accessmap
                );
            }

            if ($data) {
                // Use total time across ALL attempts for this scorm (historical time).
                $scormtime = $timeallmap[$student->id][$scorm->id] ?? $data['seconds'];
                $totalseconds += $scormtime;

                // Collect test interactions for this SCORM's attempt (all SCOs).
                $scorminteractions = [];
                if (isset($interactionsmap[$attemptid])) {
                    foreach ($interactionsmap[$attemptid] as $scoid => $ilist) {
                        foreach ($ilist as $inter) {
                            $scorminteractions[] = $inter;
                        }
                    }
                }
                $has_interactions = !empty($scorminteractions);

                // Average score: only count SCORMs that actually have a test.
                // A score of 0 with NO interactions is a theory-only SCORM that
                // Moodle reports as 0 — ignore it so it doesn't lower the average.
                // A 0 WITH interactions is a real failed test — count it.
                if ($data['score'] !== false) {
                    $scoreval = (float)$data['score'];
                    if ($scoreval != 0 || $has_interactions) {
                        $scores[] = $scoreval;
                    }
                }
                if (!empty($data['firstaccess_ts'])) $firstaccesses[] = $data['firstaccess_ts'];
                if (!empty($data['lastaccess_ts']))  $lastaccesses[]  = $data['lastaccess_ts'];

                $multisco   = !empty($data['multisco']);

                if ($multisco) {
                    // Multi-SCO: count each SCO individually
                    $totalscos  = $data['totalscos'];
                    $passedscos = $data['passedscos'];
                    $seenscos   = 0;
                    if (!empty($data['scos'])) {
                        foreach ($data['scos'] as $sco) {
                            if ($sco['status'] !== 'notattempted') $seenscos++;
                        }
                    }
                    $seen   += $seenscos;
                    $passed += $passedscos;
                } else {
                    // SCORM simple: cuenta como 1
                    $totalscos  = 1;
                    $passedscos = block_scormreport_is_passed($data['status']) ? 1 : 0;
                    $seen++;
                    if (block_scormreport_is_passed($data['status'])) $passed++;
                }
                $pctscos = $totalscos > 0 ? round(($passedscos / $totalscos) * 100, 1) . '%' : '-';

                // Display score: show "-" for theory SCORMs (score 0, no interactions).
                if ($data['score'] === false) {
                    $displayscore = '-';
                } else if ((float)$data['score'] == 0 && !$has_interactions) {
                    $displayscore = '-';
                } else {
                    $displayscore = $data['score'];
                }

                $detaildata[] = [
                    'studentname' => fullname($student),
                    'company'     => $student->institution ?? '',
                    'email'       => $student->email,
                    'dni'         => $student->idnumber ?? '',
                    'scormname'   => $scorm->name,
                    'timescorm'   => block_scormreport_hms($scormtime),
                    'seconds'     => $scormtime,
                    'score'       => $displayscore,
                    'status'      => $data['status'],
                    'firstaccess'    => $data['firstaccess'],
                    'lastaccess'     => $data['lastaccess'],
                    'firstaccess_ts' => $data['firstaccess_ts'] ?? 0,
                    'lastaccess_ts'  => $data['lastaccess_ts'] ?? 0,
                    'multisco'    => $multisco,
                    'pctscos'     => $pctscos,
                    'scos'        => $multisco ? $data['scos'] : [],
                    'scormid'     => $scorm->id,
                    'userid'      => $student->id,
                    'has_interactions' => $has_interactions,
                ];

                // Accumulate for the global dropdown.
                $scormdetails[] = [
                    'scormname'      => $scorm->name,
                    'timescorm'      => block_scormreport_hms($scormtime),
                    'seconds'        => $scormtime,
                    'score'          => $displayscore,
                    'status'         => $data['status'],
                    'firstaccess'    => $data['firstaccess'],
                    'lastaccess'     => $data['lastaccess'],
                    'firstaccess_ts' => $data['firstaccess_ts'] ?? 0,
                    'lastaccess_ts'  => $data['lastaccess_ts'] ?? 0,
                    'multisco'       => $multisco,
                    'pctscos'        => $pctscos,
                    'scos'           => $multisco ? $data['scos'] : [],
                    'interactions'   => $scorminteractions,
                    'passed'         => block_scormreport_is_passed($data['status']),
                ];
            } else {
                $detaildata[] = block_scormreport_empty_detail($student, $scorm);
                $scormdetails[] = [
                    'scormname'      => $scorm->name,
                    'timescorm'      => '-',
                    'seconds'        => 0,
                    'score'          => '-',
                    'status'         => 'notattempted',
                    'firstaccess'    => '-',
                    'lastaccess'     => '-',
                    'firstaccess_ts' => 0,
                    'lastaccess_ts'  => 0,
                    'multisco'       => false,
                    'pctscos'        => '-',
                    'scos'           => [],
                    'passed'         => false,
                ];
            }
        }

        $scormsbase   = $totalscorms;
        $pctvistos    = $scormsbase > 0 ? round(($seen   / $scormsbase) * 100, 1) : 0;
        $pctsuperados = $scormsbase > 0 ? round(($passed / $scormsbase) * 100, 1) : 0;

        if (count($scores) === 0)     $notamedia = '-';
        elseif (count($scores) === 1) $notamedia = $scores[0];
        else                          $notamedia = round(array_sum($scores) / count($scores), 1);

        // Calculate % time over total course hours
        $pcttotal = '-';
        if (!empty($coursehours) && is_numeric(trim($coursehours)) && $totalseconds > 0) {
            $totalcourseseconds = (int)trim($coursehours) * 3600;
            $pcttotal = round(($totalseconds / $totalcourseseconds) * 100, 1) . '%';
        }

        $globaldata[] = [
            'studentname'  => fullname($student),
            'username'     => $student->username ?? '',
            'company'      => $student->institution ?? '',
            'email'        => $student->email,
            'dni'          => $student->idnumber ?? '',
            'timescorm'    => block_scormreport_hms($totalseconds),
            'totalseconds' => $totalseconds,
            'pcttotal'     => $pcttotal,
            'score'        => $notamedia,
            'pctvistos'    => $pctvistos . '%',
            'pctsuperados' => $pctsuperados . '%',
            'seen'         => $seen,
            'passed'       => $passed,
            'scormdetails' => $scormdetails,
            'firstaccess'    => !empty($firstaccesses) ? userdate(min($firstaccesses), '%d/%m/%Y %H:%M') : '-',
            'lastaccess'     => !empty($lastaccesses)  ? userdate(max($lastaccesses), '%d/%m/%Y %H:%M')  : '-',
            'firstaccess_ts' => !empty($firstaccesses) ? min($firstaccesses) : 0,
            'lastaccess_ts'  => !empty($lastaccesses)  ? max($lastaccesses)  : 0,
            'userid'       => $student->id,
            'attempts_total' => array_sum($attemptcountmap[$student->id] ?? []),
            'fullreport'   => $CFG->wwwroot . '/report/outline/user.php?id=' . $student->id . '&course=' . $courseid . '&mode=complete',
            'logentries'   => $CFG->wwwroot . '/report/log/user.php?id='     . $student->id . '&course=' . $courseid . '&mode=all',
        ];
    }

    return compact('students', 'scorms', 'globaldata', 'detaildata', 'coursehours', 'totalscorms');
}

// ═══════════════════════════════════════════════════════════════════════════
// Funciones auxiliares
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Empty row for a student with no data in a SCORM.
 */
function block_scormreport_empty_detail($student, $scorm): array {
    return [
        'studentname' => fullname($student),
        'company'     => $student->institution ?? '',
        'email'       => $student->email,
        'dni'         => $student->idnumber ?? '',
        'scormname'   => $scorm->name,
        'timescorm'   => '-',
        'seconds'     => 0,
        'score'       => '-',
        'status'      => 'notattempted',
        'firstaccess'    => '-',
        'lastaccess'     => '-',
        'firstaccess_ts' => 0,
        'lastaccess_ts'  => 0,
        'multisco'    => false,
        'pctscos'     => '-',
        'scos'        => [],
        'scormid'     => $scorm->id,
        'userid'      => $student->id,
    ];
}

/**
 * Build data for a single-SCO SCORM from the preloaded maps.
 */
function block_scormreport_build_singlesco($scorm, $student, $attemptid, $elementids, $valuesmap, $accessmap) {
    $values = [];
    if (isset($valuesmap[$attemptid])) {
        foreach ($valuesmap[$attemptid] as $scoid => $elvals) {
            foreach ($elvals as $elid => $val) {
                $values[$elid] = $val;
            }
        }
    }

    if (empty($values)) return null;

    $totalseconds = block_scormreport_calc_time($values, $elementids);
    $score  = block_scormreport_calc_score($values, $elementids);
    $status = block_scormreport_calc_status($values, $elementids);

    $acc = $accessmap[$student->id][$scorm->id] ?? null;
    $fa_ts = ($acc && !empty($acc->fa)) ? (int)$acc->fa : 0;
    $la_ts = ($acc && !empty($acc->la)) ? (int)$acc->la : 0;
    return [
        'seconds'        => $totalseconds,
        'score'          => $score,
        'status'         => $status,
        'firstaccess'    => $fa_ts ? userdate($fa_ts, '%d/%m/%Y %H:%M') : '-',
        'lastaccess'     => $la_ts ? userdate($la_ts, '%d/%m/%Y %H:%M') : '-',
        'firstaccess_ts' => $fa_ts,
        'lastaccess_ts'  => $la_ts,
        'multisco'       => false,
        'scos'           => [],
    ];
}

/**
 * Build data for a multi-SCO SCORM from the preloaded maps.
 */
function block_scormreport_build_multisco($scorm, $student, $attemptid, $elementids, $valuesmap, $scoaccessmap, $scos) {
    if (empty($scos)) return null;

    $totalseconds = 0;
    $scores = [];
    $passed = 0;
    $initiated = 0;
    $firstaccess = PHP_INT_MAX;
    $lastaccess = 0;
    $scodetails = [];

    foreach ($scos as $sco) {
        $values = $valuesmap[$attemptid][$sco->id] ?? [];

        $secs = block_scormreport_calc_time($values, $elementids);
        if ($secs <= 0 && empty($values)) {
            $scodetails[] = [
                'title' => $sco->title, 'seconds' => 0, 'score' => false,
                'status' => 'notattempted', 'firstaccess' => '-', 'lastaccess' => '-',
            ];
            continue;
        }

        $initiated++;
        $totalseconds += $secs;
        $sc = block_scormreport_calc_score($values, $elementids);
        if ($sc !== false) $scores[] = (float)$sc;
        $st = block_scormreport_calc_status($values, $elementids);
        if (block_scormreport_is_passed($st)) $passed++;

        $acc = $scoaccessmap[$student->id][$scorm->id][$sco->id] ?? null;
        $fa = ($acc && !empty($acc->fa)) ? userdate($acc->fa, '%d/%m/%Y %H:%M') : '-';
        $la = ($acc && !empty($acc->la)) ? userdate($acc->la, '%d/%m/%Y %H:%M') : '-';
        if ($acc && !empty($acc->fa) && $acc->fa < $firstaccess) $firstaccess = $acc->fa;
        if ($acc && !empty($acc->la) && $acc->la > $lastaccess) $lastaccess = $acc->la;

        $scodetails[] = [
            'title' => $sco->title, 'seconds' => $secs, 'score' => $sc,
            'status' => $st, 'firstaccess' => $fa, 'lastaccess' => $la,
        ];
    }

    if ($initiated === 0) return null;

    $totalscos = count($scos);
    $globalstatus = ($passed === $totalscos) ? 'passed' : 'incomplete';
    $score = empty($scores) ? false : (count($scores) === 1 ? $scores[0] : round(array_sum($scores) / count($scores), 1));

    $fa_ts = ($firstaccess < PHP_INT_MAX) ? (int)$firstaccess : 0;
    $la_ts = ($lastaccess > 0) ? (int)$lastaccess : 0;

    return [
        'seconds'        => $totalseconds,
        'score'          => $score,
        'status'         => $globalstatus,
        'firstaccess'    => $fa_ts ? userdate($fa_ts, '%d/%m/%Y %H:%M') : '-',
        'lastaccess'     => $la_ts ? userdate($la_ts, '%d/%m/%Y %H:%M') : '-',
        'firstaccess_ts' => $fa_ts,
        'lastaccess_ts'  => $la_ts,
        'multisco'       => true,
        'scos'           => $scodetails,
        'totalscos'      => $totalscos,
        'passedscos'     => $passed,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// Pure calculation functions (no DB)
// ═══════════════════════════════════════════════════════════════════════════

function block_scormreport_calc_time(array $values, array $elementids): int {
    // Priority: total_time > session_time as fallback.
    foreach (['cmi.core.total_time', 'cmi.total_time'] as $t) {
        if (isset($elementids[$t], $values[$elementids[$t]])) {
            $secs = block_scormreport_parse_scorm_time($values[$elementids[$t]]);
            if ($secs > 0) return $secs;
        }
    }
    // Fallback to session_time only if no total_time (SCORM 1.2 and 2004).
    foreach (['cmi.core.session_time', 'cmi.session_time'] as $t) {
        if (isset($elementids[$t], $values[$elementids[$t]])) {
            return block_scormreport_parse_scorm_time($values[$elementids[$t]]);
        }
    }
    return 0;
}

function block_scormreport_calc_score(array $values, array $elementids) {
    foreach (['cmi.core.score.raw', 'cmi.score.raw'] as $s) {
        if (isset($elementids[$s], $values[$elementids[$s]])
            && $values[$elementids[$s]] !== '' && is_numeric($values[$elementids[$s]])) {
            return $values[$elementids[$s]];
        }
    }
    return false;
}

function block_scormreport_calc_status(array $values, array $elementids): string {
    // SCORM 2004: when present, success_status decides passed/failed.
    if (isset($elementids['cmi.success_status'], $values[$elementids['cmi.success_status']])) {
        $success = trim(strtolower($values[$elementids['cmi.success_status']]));
        if (in_array($success, ['passed', 'failed'], true)) {
            return $success;
        }
    }

    // SCORM 1.2: lesson_status is usually the primary status.
    if (isset($elementids['cmi.core.lesson_status'], $values[$elementids['cmi.core.lesson_status']])
        && $values[$elementids['cmi.core.lesson_status']] !== '') {
        return $values[$elementids['cmi.core.lesson_status']];
    }

    // SCORM 2004: completion_status como fallback.
    if (isset($elementids['cmi.completion_status'], $values[$elementids['cmi.completion_status']])
        && $values[$elementids['cmi.completion_status']] !== '') {
        return $values[$elementids['cmi.completion_status']];
    }

    return 'incomplete';
}

function block_scormreport_is_passed($status) {
    // Both 'passed' and 'completed' count as passed for percentage/threshold.
    // The visual badge still distinguishes them (see block_scormreport_get_badge_info).
    return in_array(strtolower(trim($status)), ['passed', 'completed']);
}

function block_scormreport_is_completed($status) {
    return in_array(strtolower(trim($status)), ['passed', 'completed']);
}

function block_scormreport_hms($seconds) {
    if ($seconds <= 0) return '-';
    return sprintf('%02d:%02d:%02d',
        (int)floor($seconds / 3600),
        (int)floor(($seconds % 3600) / 60),
        (int)($seconds % 60)
    );
}

/**
 * Parse a SCORM time value (SCORM 1.2 HH:MM:SS or SCORM 2004 ISO 8601 duration)
 * into seconds.
 *
 * @param string $timestr
 * @return int seconds
 */
function block_scormreport_parse_scorm_time($timestr): int {
    if (empty($timestr)) return 0;
    // SCORM 2004 ISO 8601 duration: PT1H30M45S
    if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $timestr, $m)) {
        return (int)(
            (isset($m[1]) ? (int)$m[1] : 0) * 3600 +
            (isset($m[2]) ? (int)$m[2] : 0) * 60 +
            (isset($m[3]) ? (float)$m[3] : 0)
        );
    }
    // SCORM 1.2 CMITimespan: HH:MM:SS(.ss)
    if (preg_match('/^(\d+):(\d{2}):(\d{2})/', $timestr, $m)) {
        return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
    }
    return 0;
}

/**
 * Legacy alias for block_scormreport_parse_scorm_time.
 * @deprecated use block_scormreport_parse_scorm_time
 */
function block_scormreport_time_to_seconds($timestr) {
    return block_scormreport_parse_scorm_time($timestr);
}

/**
 * Sanitize a value for spreadsheet export to prevent formula injection.
 * Prefixes with a single quote if the value starts with =, +, - or @.
 */
function block_scormreport_escape_spreadsheet($value) {
    if (is_string($value) && preg_match('/^\s*[=+\-@]/u', $value)) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Get badge class and label for a SCORM status.
 */
function block_scormreport_get_badge_info($status) {
    switch (strtolower(trim($status))) {
        case 'passed':
            return ['badge' => 'badge-success', 'label' => get_string('passed',       'block_scormreport')];
        case 'completed':
            return ['badge' => 'badge-info',    'label' => get_string('completed',     'block_scormreport')];
        case 'failed':
            return ['badge' => 'badge-danger',  'label' => get_string('failed',        'block_scormreport')];
        case 'incomplete':
            return ['badge' => 'badge-warning', 'label' => get_string('incomplete',    'block_scormreport')];
        case 'notattempted':
        default:
            return ['badge' => 'badge-secondary','label' => get_string('notattempted', 'block_scormreport')];
    }
}

/**
 * Calculate deadline status for a student.
 *
 * @param float $pctsuperados Percentage of topics passed
 * @param int $startdate Course start timestamp
 * @param int $enddate Course end timestamp
 * @param int $tolerance Tolerance in points (default 15)
 * @return array ['status' => string, 'badge' => string, 'pctdeadline' => float, 'gap' => float]
 */
function block_scormreport_calc_deadline_status(float $pctsuperados, int $startdate, int $enddate, int $tolerance = 15): array {
    $now = time();

    // No dates configured
    if (!$startdate || !$enddate || $enddate <= $startdate) {
        return [
            'status' => 'nodate',
            'badge'  => 'badge-secondary',
            'label'  => get_string('deadline_nodate', 'block_scormreport'),
            'pctdeadline' => 0,
            'gap' => 0,
        ];
    }

    // Deadline already passed
    if ($now > $enddate) {
        return [
            'status' => 'finished',
            'badge'  => $pctsuperados >= 100 ? 'badge-success' : 'badge-danger',
            'label'  => get_string('deadline_finished', 'block_scormreport'),
            'pctdeadline' => 100,
            'gap' => 100 - $pctsuperados,
        ];
    }

    // Course hasn't started yet
    if ($now < $startdate) {
        return [
            'status' => 'ontrack',
            'badge'  => 'badge-success',
            'label'  => get_string('deadline_ontrack', 'block_scormreport'),
            'pctdeadline' => 0,
            'gap' => 0 - $pctsuperados,
        ];
    }

    // Normal calculation
    $totaldays = $enddate - $startdate;
    $elapsed = $now - $startdate;
    $pctdeadline = round(($elapsed / $totaldays) * 100, 1);
    $gap = $pctdeadline - $pctsuperados;

    if ($gap > $tolerance) {
        return [
            'status' => 'atrisk',
            'badge'  => 'badge-danger',
            'label'  => get_string('deadline_atrisk', 'block_scormreport'),
            'pctdeadline' => $pctdeadline,
            'gap' => round($gap, 1),
        ];
    } else if ($gap > 0) {
        return [
            'status' => 'tight',
            'badge'  => 'badge-warning',
            'label'  => get_string('deadline_tight', 'block_scormreport'),
            'pctdeadline' => $pctdeadline,
            'gap' => round($gap, 1),
        ];
    } else {
        return [
            'status' => 'ontrack',
            'badge'  => 'badge-success',
            'label'  => get_string('deadline_ontrack', 'block_scormreport'),
            'pctdeadline' => $pctdeadline,
            'gap' => round($gap, 1),
        ];
    }
}

/**
 * Calculate connection sessions from Moodle logs for students in a course.
 * Groups log entries into sessions with a configurable timeout (default 30 min).
 *
 * @param int $courseid
 * @param array $studentids Array of user IDs
 * @param int $timeout Session timeout in seconds (default 1800 = 30 min)
 * @param string[] $crud Log operations that count as activity (default: read and update).
 * @return array [userid => ['sessions' => int, 'total_seconds' => int, 'first_login' => int, 'last_login' => int, 'dates' => [date => seconds]]]
 */
function block_scormreport_get_connections(int $courseid, array $studentids, int $timeout = 1800,
        array $crud = ['r', 'u']): array {
    global $DB;

    if (empty($studentids)) return [];

    $result = [];
    foreach ($studentids as $uid) {
        $result[$uid] = [
            'sessions'      => 0,
            'total_seconds' => 0,
            'first_login'   => 0,
            'last_login'    => 0,
            'dates'         => [],
        ];
    }

    // Use bounded time windows and stream rows. Session state is retained across
    // window boundaries, so historical totals are never truncated.
    foreach (array_chunk($studentids, 500) as $chunk) {
        list($insql, $params) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'uid');
        list($crudsql, $crudparams) = $DB->get_in_or_equal($crud, SQL_PARAMS_NAMED, 'crud');
        $params = array_merge($params, $crudparams);
        $params['courseid'] = $courseid;
        $where = "courseid = :courseid AND userid $insql AND anonymous = 0 AND crud $crudsql";
        $bounds = $DB->get_record_sql(
            "SELECT MIN(timecreated) AS mintime, MAX(timecreated) AS maxtime
               FROM {logstore_standard_log} WHERE $where",
            $params
        );
        if (!$bounds || $bounds->mintime === null) {
            continue;
        }

        $states = [];
        $windowseconds = 90 * DAYSECS;
        for ($from = (int)$bounds->mintime; $from <= (int)$bounds->maxtime; $from += $windowseconds) {
            $windowparams = $params + ['fromtime' => $from, 'totime' => $from + $windowseconds];
            $logs = $DB->get_recordset_sql(
                "SELECT id, userid, timecreated
                   FROM {logstore_standard_log}
                  WHERE $where AND timecreated >= :fromtime AND timecreated < :totime
                  ORDER BY userid ASC, timecreated ASC, id ASC",
                $windowparams
            );
            try {
                foreach ($logs as $log) {
                    $uid = (int)$log->userid;
                    $timestamp = (int)$log->timecreated;
                    if (!isset($states[$uid])) {
                        $states[$uid] = ['start' => $timestamp, 'prev' => $timestamp];
                        $result[$uid]['sessions'] = 1;
                        $result[$uid]['first_login'] = $timestamp;
                        $result[$uid]['last_login'] = $timestamp;
                        continue;
                    }
                    $state = &$states[$uid];
                    if ($timestamp - $state['prev'] > $timeout) {
                        $duration = $state['prev'] - $state['start'];
                        $result[$uid]['total_seconds'] += $duration;
                        $date = userdate($state['start'], '%Y-%m-%d');
                        $result[$uid]['dates'][$date] = ($result[$uid]['dates'][$date] ?? 0) + $duration;
                        $result[$uid]['sessions']++;
                        $state['start'] = $timestamp;
                    }
                    $state['prev'] = $timestamp;
                    $result[$uid]['last_login'] = $timestamp;
                    unset($state);
                }
            } finally {
                $logs->close();
            }
        }
        foreach ($states as $uid => $state) {
            $duration = $state['prev'] - $state['start'];
            $result[$uid]['total_seconds'] += $duration;
            $date = userdate($state['start'], '%Y-%m-%d');
            $result[$uid]['dates'][$date] = ($result[$uid]['dates'][$date] ?? 0) + $duration;
        }
    }

    return $result;
}

/**
 * Get test interactions (questions/answers) for a set of attempts.
 * Reads cmi.interactions_N.* elements from SCORM tracking.
 *
 * @param array $attemptids Array of attempt IDs
 * @param object $DB
 * @return array [attemptid][scoid] => [ ['id'=>, 'response'=>, 'correct'=>, 'result'=>, 'type'=>], ... ]
 */
function block_scormreport_get_interactions(array $attemptids, $DB): array {
    if (empty($attemptids)) return [];

    $result = [];

    foreach (array_chunk($attemptids, 300) as $chunk) {
        list($insql, $params) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'iat');

        // Read all interaction elements for these attempts.
        $rows = $DB->get_records_sql(
            "SELECT sv.id, sv.attemptid, sv.scoid, e.element, sv.value
               FROM {scorm_scoes_value} sv
               JOIN {scorm_element} e ON e.id = sv.elementid
              WHERE sv.attemptid $insql
                AND " . $DB->sql_like('e.element', ':pattern'),
            array_merge($params, ['pattern' => 'cmi.interactions\_%'])
        );

        // Group raw values by attempt/sco/interaction-index/field.
        $raw = []; // [attemptid][scoid][idx][field] = value
        foreach ($rows as $r) {
            // Parse element: cmi.interactions_N.field  or  cmi.interactions_N.correct_responses_0.pattern
            if (!preg_match('/^cmi\.interactions_(\d+)\.(.+)$/', $r->element, $m)) {
                continue;
            }
            $idx = (int)$m[1];
            $field = $m[2];
            $raw[$r->attemptid][$r->scoid][$idx][$field] = $r->value;
        }

        // Build ordered interaction list per attempt/sco.
        foreach ($raw as $attemptid => $scos) {
            foreach ($scos as $scoid => $interactions) {
                ksort($interactions);
                $list = [];
                foreach ($interactions as $idx => $fields) {
                    // Collect correct responses (may be several patterns).
                    $corrects = [];
                    foreach ($fields as $fname => $fval) {
                        if (preg_match('/^correct_responses_\d+\.pattern$/', $fname)) {
                            if ($fval !== '') $corrects[] = $fval;
                        }
                    }
                    $response = $fields['learner_response'] ?? $fields['student_response'] ?? '-';
                    $list[] = [
                        'id'       => $fields['id'] ?? ('#' . ($idx + 1)),
                        'response' => $response,
                        // Text of the answer when the package reports identifiers (ActivePresenter).
                        'responsetext' => \block_scormreport\answer_labels::resolve((int)$scoid, (string)$response),
                        'correct'  => !empty($corrects) ? implode(', ', $corrects) : '-',
                        'result'   => $fields['result'] ?? '-',
                        'type'     => $fields['type'] ?? '',
                    ];
                }
                if (!empty($list)) {
                    $result[$attemptid][$scoid] = $list;
                }
            }
        }
    }

    return $result;
}

/**
 * Show the full-lock page when the license/trial has expired, then exit.
 * The message depends on whether it was a trial or a paid license.
 *
 * @param moodle_page $PAGE
 * @param core_renderer $OUTPUT
 * @param stdClass $course
 * @param int $courseid
 */
function block_scormreport_show_locked_page($PAGE, $OUTPUT, $course, $courseid) {
    global $CFG;

    $blocked = \block_scormreport\license::blocked_reason();
    $until = \block_scormreport\license::licensed_until();
    if ($blocked !== '') {
        // Suspended or cancelled by Accros: every course is locked.
        $title = get_string('locked_' . $blocked . '_title', 'block_scormreport');
        $desc  = get_string('locked_blocked_desc', 'block_scormreport');
    } else if ($until > 0) {
        // The license ended and this course started after its end date.
        $title = get_string('locked_course_title', 'block_scormreport');
        $desc  = get_string('locked_course_desc', 'block_scormreport',
            userdate($until, get_string('strftimedate', 'langconfig')));
    } else {
        $title = get_string('locked_none_title', 'block_scormreport');
        $desc  = get_string('locked_none_desc', 'block_scormreport');
    }

    $PAGE->set_url('/blocks/scormreport/report.php', ['courseid' => $courseid]);
    $PAGE->set_context(context_course::instance($courseid));
    $PAGE->set_course($course);
    $PAGE->set_title(get_string('reporttitle', 'block_scormreport'));
    $PAGE->set_heading($course->fullname);
    $PAGE->set_pagelayout('incourse');

    echo $OUTPUT->header();
    echo '<div style="max-width:640px;margin:40px auto;text-align:center;
        background:#eaf4fb;border:1px solid #b6d9f0;color:#0b4a6f;
        border-radius:16px;padding:40px 32px;box-shadow:0 4px 18px rgba(0,115,170,0.12);">
        <div style="font-size:2.6rem;margin-bottom:12px;">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#0073aa" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 style="color:#0b4a6f;margin:0 0 16px;font-weight:700;">' . $title . '</h2>
        <p style="font-size:1.05rem;line-height:1.5;color:#2c5f7a;">' . $desc . '</p>
    </div>';

    // Administrators get a direct link to the license page (activate, renew or check).
    $licensebutton = '';
    if (has_capability('moodle/site:config', context_system::instance())) {
        $licenseurl = new moodle_url('/blocks/scormreport/license_page.php');
        $label = ($blocked === '' && $until <= 0)
            ? get_string('locked_activate_button', 'block_scormreport')
            : get_string('license_manage', 'block_scormreport');
        $licensebutton = html_writer::link($licenseurl, $label, ['class' => 'btn btn-primary me-2']);
    }

    $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
    echo '<div style="text-align:center;margin-top:20px;">'
        . $licensebutton
        . html_writer::link($courseurl, get_string('back', 'block_scormreport'),
            ['class' => 'btn btn-secondary'])
        . '</div>';

    echo $OUTPUT->footer();
    exit;
}

/**
 * Daily connection summary per student, broken down by activity.
 * Produces one row per (day, activity): the day, the first connection time to
 * that activity that day, the activity name, and the distinct IP addresses used.
 *
 * @param int $courseid
 * @param int $userid
 * @param string[] $crud Log operations that count as activity (default: read and update).
 * @return array Ordered by date then first time:
 *               [ ['date'=>ts, 'first'=>ts, 'activity'=>string, 'ips'=>[...]], ... ]
 */
function block_scormreport_get_daily_connections(int $courseid, int $userid, array $crud = ['r', 'u']): array {
    global $DB, $CFG;

    list($crudsql, $params) = $DB->get_in_or_equal($crud, SQL_PARAMS_NAMED, 'crud');
    $params['courseid'] = $courseid;
    $params['userid'] = $userid;
    $logs = $DB->get_recordset_sql(
        "SELECT id, timecreated, ip, contextid, contextlevel, contextinstanceid, component
           FROM {logstore_standard_log}
          WHERE courseid = :courseid AND userid = :userid
            AND anonymous = 0 AND crud $crudsql
          ORDER BY timecreated ASC",
        $params
    );

    // Resolve activity names once (map course_module id => activity name).
    $cmnames = [];
    $modinfo = get_fast_modinfo($courseid);
    foreach ($modinfo->get_cms() as $cm) {
        $cmnames[$cm->id] = $cm->name;
    }
    $courselabel = get_string('coursegeneral', 'block_scormreport');

    // Group by (day + activity).
    $groups = [];
    try {
        foreach ($logs as $log) {
        $day = userdate($log->timecreated, '%Y-%m-%d');

        // Determine the activity: context level 70 = module.
        if ((int)$log->contextlevel === CONTEXT_MODULE && !empty($log->contextinstanceid)
            && isset($cmnames[$log->contextinstanceid])) {
            $activity = $cmnames[$log->contextinstanceid];
        } else {
            $activity = $courselabel; // course-level event (course view, etc.)
        }

        $key = $day . '||' . $activity;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'date'     => (int)$log->timecreated,
                'first'    => (int)$log->timecreated,
                'activity' => $activity,
                'ips'      => [],
            ];
        }
        // Keep the earliest time for this day+activity.
        if ((int)$log->timecreated < $groups[$key]['first']) {
            $groups[$key]['first'] = (int)$log->timecreated;
            $groups[$key]['date']  = (int)$log->timecreated;
        }
        if (!empty($log->ip)) {
            $groups[$key]['ips'][$log->ip] = true;
        }
    }

    } finally {
        $logs->close();
    }

    // Build the ordered result (by date, then first time).
    $result = [];
    foreach ($groups as $g) {
        $result[] = [
            'date'     => $g['date'],
            'first'    => $g['first'],
            'activity' => $g['activity'],
            'ips'      => array_keys($g['ips']),
        ];
    }
    usort($result, function($a, $b) {
        return $a['first'] <=> $b['first'];
    });

    return $result;
}

/**
 * Role ids that count as tutors (teachers with or without editing rights).
 *
 * Taken from the "Tutor roles" setting; when it has never been saved, the roles
 * based on the editingteacher and teacher archetypes are used.
 *
 * @return int[]
 */
function block_scormreport_get_tutor_roleids(): array {
    $config = get_config('block_scormreport', 'tutor_roles');
    if ($config !== false) {
        return array_values(array_filter(array_map('intval', explode(',', (string)$config))));
    }
    $ids = [];
    foreach (['editingteacher', 'teacher'] as $archetype) {
        foreach (get_archetype_roles($archetype) as $role) {
            $ids[] = (int)$role->id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Tutors of a course: users with a tutor role assigned in the course.
 *
 * @param context_course $context Course context.
 * @return array [userid => stdClass user record plus 'rolenames' (string)]
 */
function block_scormreport_get_course_tutors(context_course $context): array {
    global $DB;

    $roleids = block_scormreport_get_tutor_roleids();
    if (empty($roleids)) {
        return [];
    }
    list($rolesql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rid');
    $params['contextid'] = $context->id;

    $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    $rows = $DB->get_records_sql(
        "SELECT ra.id AS raid, ra.roleid, u.id, u.username, u.email, u.idnumber, $userfields
           FROM {role_assignments} ra
           JOIN {user} u ON u.id = ra.userid
          WHERE ra.contextid = :contextid AND ra.roleid $rolesql AND u.deleted = 0
       ORDER BY u.lastname, u.firstname",
        $params
    );

    $rolenames = role_fix_names(get_all_roles($context), $context, ROLENAME_ALIAS, true);
    $tutors = [];
    foreach ($rows as $row) {
        if (!isset($tutors[$row->id])) {
            $user = clone $row;
            unset($user->raid, $user->roleid);
            $user->rolelist = [];
            $tutors[$row->id] = $user;
        }
        $tutors[$row->id]->rolelist[] = $rolenames[$row->roleid] ?? '';
    }
    foreach ($tutors as $tutor) {
        $tutor->rolenames = implode(', ', array_unique(array_filter($tutor->rolelist)));
        unset($tutor->rolelist);
    }
    return $tutors;
}

/**
 * Whether a user has a tutor role in a course.
 *
 * @param context_course $context Course context.
 * @param int $userid User id.
 * @return bool
 */
function block_scormreport_is_course_tutor(context_course $context, int $userid): bool {
    global $DB;
    $roleids = block_scormreport_get_tutor_roleids();
    if (empty($roleids)) {
        return false;
    }
    list($rolesql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rid');
    $params['contextid'] = $context->id;
    $params['userid'] = $userid;
    return $DB->record_exists_select('role_assignments',
        "contextid = :contextid AND userid = :userid AND roleid $rolesql", $params);
}

/**
 * Answer to show for an interaction: the answer text read from the package when
 * the package reports identifiers, otherwise the readable form of the raw value.
 *
 * @param array $inter Interaction as returned by block_scormreport_get_interactions().
 * @return string
 */
function block_scormreport_display_response(array $inter): string {
    if (isset($inter['responsetext']) && $inter['responsetext'] !== '') {
        return $inter['responsetext'];
    }
    return block_scormreport_format_response((string)($inter['response'] ?? ''));
}

/**
 * Make a SCORM answer readable.
 *
 * SCORM stores choice answers as identifiers, where authoring tools replace
 * spaces, punctuation and accented letters with "_". This turns "__" (comma and
 * space) into ", ", the remaining "_" into spaces, and the SCORM 2004
 * separators "[,]" and "[.]" into ", " and " → ". Letters that the package
 * already replaced cannot be recovered.
 *
 * @param string $value Raw learner_response / student_response.
 * @return string
 */
function block_scormreport_format_response(string $value): string {
    $value = trim($value);
    if ($value === '' || $value === '-') {
        return '-';
    }
    if (preg_match('/%[0-9A-Fa-f]{2}/', $value)) {
        $value = rawurldecode($value);
    }
    $value = str_replace(['[,]', '[.]', '[:]'], ['__', ' → ', ': '], $value);
    $value = preg_replace('/_{2,}/', ', ', $value);
    $value = str_replace('_', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = preg_replace('/\s+([,.])/u', '$1', $value);
    return trim($value);
}

/**
 * Tutor connections per day.
 *
 * A new connection starts when the tutor logs in to Moodle, after more than
 * $timeout seconds without activity in the course, or on a new day. Only
 * connections with activity in the course are counted. Every logged action in the
 * course counts as activity (create, read, update and delete).
 *
 * @param int $courseid Course id.
 * @param int[] $userids Tutor ids.
 * @param int $timeout Seconds without activity that close a connection (default 30 min).
 * @return array [userid => ['connections' => int, 'first' => int, 'last' => int,
 *               'days' => [Y-m-d => ['date' => int, 'connections' => int, 'first' => int, 'last' => int]]]]
 */
function block_scormreport_get_tutor_daily_activity(int $courseid, array $userids, int $timeout = 1800): array {
    global $DB;

    $result = [];
    if (empty($userids)) {
        return $result;
    }
    $tz = core_date::get_user_timezone_object();

    // Process one tutor at a time. Never retain the complete course activity log.
    foreach (array_unique(array_map('intval', $userids)) as $uid) {
        $bounds = $DB->get_record_sql(
            "SELECT MIN(timecreated) AS mintime, MAX(timecreated) AS maxtime
               FROM {logstore_standard_log}
              WHERE courseid = :courseid AND userid = :userid AND anonymous = 0",
            ['courseid' => $courseid, 'userid' => $uid]
        );
        if (!$bounds || $bounds->mintime === null) {
            continue;
        }

        // Login events are independent of the course, but only events in the
        // original course-activity window can start another course connection.
        $logins = [];
        $loginrs = $DB->get_recordset_sql(
            "SELECT id, timecreated
               FROM {logstore_standard_log}
              WHERE eventname = :eventname AND userid = :userid
                AND timecreated >= :mintime AND timecreated <= :maxtime
              ORDER BY timecreated ASC, id ASC",
            [
                'eventname' => '\\core\\event\\user_loggedin',
                'userid' => $uid,
                'mintime' => (int)$bounds->mintime - DAYSECS,
                'maxtime' => (int)$bounds->maxtime,
            ]
        );
        try {
            foreach ($loginrs as $login) {
                $logins[] = (int)$login->timecreated;
            }
        } finally {
            $loginrs->close();
        }

        $logs = $DB->get_recordset_sql(
            "SELECT id, timecreated
               FROM {logstore_standard_log}
              WHERE courseid = :courseid AND userid = :userid AND anonymous = 0
              ORDER BY timecreated ASC, id ASC",
            ['courseid' => $courseid, 'userid' => $uid]
        );
        $li = 0;
        $prev = null;
        $prevday = null;
        $data = ['connections' => 0, 'first' => (int)$bounds->mintime,
            'last' => (int)$bounds->maxtime, 'days' => []];
        try {
            foreach ($logs as $log) {
                $t = (int)$log->timecreated;
                $dt = new DateTime('@' . $t);
                $dt->setTimezone($tz);
                $day = $dt->format('Y-m-d');
                $loggedin = false;
                while ($li < count($logins) && $logins[$li] <= $t) {
                    if ($prev === null || $logins[$li] > $prev) {
                        $loggedin = true;
                    }
                    $li++;
                }
                $isnew = $prev === null || $day !== $prevday || ($t - $prev) > $timeout || $loggedin;
                if (!isset($data['days'][$day])) {
                    $data['days'][$day] = ['date' => $t, 'connections' => 0, 'first' => $t, 'last' => $t];
                }
                if ($isnew) {
                    $data['days'][$day]['connections']++;
                    $data['connections']++;
                }
                $data['days'][$day]['last'] = $t;
                $prev = $t;
                $prevday = $day;
            }
        } finally {
            $logs->close();
        }
        $result[$uid] = $data;
    }
    return $result;
}
