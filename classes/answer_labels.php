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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Answer texts for SCORM packages that report choice answers as identifiers.
 *
 * ActivePresenter reports the answers of choice questions as the internal id
 * of the selected item (for example "69_6") instead of its text. The text of
 * every item is stored in the package itself (HTML5/rlprez.js), so it can be
 * looked up there.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport;

/**
 * Looks up the text of answer identifiers in the SCORM package.
 */
class answer_labels {

    /** @var array Labels already read in this request, keyed by scorm id. */
    protected static $byscorm = [];

    /** @var array Scorm id of each sco, keyed by sco id. */
    protected static $scorms = [];

    /** Largest presentation file that is read (bytes). */
    const MAX_FILE_SIZE = 20971520;

    /**
     * Text of an answer as reported by the package, or null if it cannot be resolved.
     *
     * @param int $scoid SCO the answer belongs to.
     * @param string $response Raw learner_response / student_response.
     * @return string|null
     */
    public static function resolve(int $scoid, string $response): ?string {
        $response = trim($response);
        // Only identifiers such as "69_6", or several of them separated by commas.
        if (!preg_match('/^\d+_\d+(\s*,\s*\d+_\d+)*$/', $response)) {
            return null;
        }
        $labels = self::for_sco($scoid);
        if (empty($labels)) {
            return null;
        }
        $texts = [];
        foreach (preg_split('/\s*,\s*/', $response) as $id) {
            if (!isset($labels[$id])) {
                return null;
            }
            $texts[] = $labels[$id];
        }
        return implode(', ', $texts);
    }

    /**
     * Labels of every answer item in the package of a SCO.
     *
     * @param int $scoid
     * @return array [identifier => text]
     */
    public static function for_sco(int $scoid): array {
        global $DB;

        if (!array_key_exists($scoid, self::$scorms)) {
            self::$scorms[$scoid] = (int)$DB->get_field('scorm_scoes', 'scorm', ['id' => $scoid]);
        }
        $scormid = self::$scorms[$scoid];
        if (!$scormid) {
            return [];
        }
        if (!array_key_exists($scormid, self::$byscorm)) {
            self::$byscorm[$scormid] = self::read_package($scormid);
        }
        return self::$byscorm[$scormid];
    }

    /**
     * Read the answer labels from the files of a SCORM activity.
     *
     * @param int $scormid
     * @return array [identifier => text]
     */
    protected static function read_package(int $scormid): array {
        $labels = [];
        try {
            $cm = get_coursemodule_from_instance('scorm', $scormid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return [];
            }
            $context = \context_module::instance($cm->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_scorm', 'content', 0, 'filepath, filename', false);
            foreach ($files as $file) {
                if ($file->get_filename() !== 'rlprez.js' || $file->get_filesize() > self::MAX_FILE_SIZE) {
                    continue;
                }
                $labels += self::parse_activepresenter($file->get_content());
            }
        } catch (\Throwable $e) {
            debugging('block_scormreport: could not read the answer texts of SCORM ' . $scormid . ': ' .
                $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
        return $labels;
    }

    /**
     * Extract [identifier => text] from an ActivePresenter presentation file.
     *
     * Each item is written as {..., r:"69_6", ..., s:[{..., t:["<p>Text</p>", ...]}]}:
     * the first text after the identifier, and before the next identifier, is the item text.
     *
     * @param string $js Content of rlprez.js.
     * @return array
     */
    public static function parse_activepresenter(string $js): array {
        if (strpos($js, 'Atomi Systems') === false) {
            return [];
        }
        if (!preg_match_all('/\br:"(\d+_\d+)"/', $js, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $labels = [];
        $count = count($matches[1]);
        for ($i = 0; $i < $count; $i++) {
            $id = $matches[1][$i][0];
            if (isset($labels[$id])) {
                continue;
            }
            $start = $matches[0][$i][1];
            $end = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($js);
            $chunk = substr($js, $start, min($end - $start, 50000));
            if (preg_match('/\bt:\["((?:[^"\\\\]|\\\\.)*)"/s', $chunk, $t)) {
                $text = self::clean_text($t[1]);
                if ($text !== '') {
                    $labels[$id] = $text;
                }
            }
        }
        return $labels;
    }

    /**
     * Turn an escaped HTML fragment from the presentation file into plain text.
     *
     * @param string $raw
     * @return string
     */
    protected static function clean_text(string $raw): string {
        $text = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m) {
            return \core_text::code2utf8(hexdec($m[1]));
        }, $raw);
        $text = str_replace(['\\"', "\\'", '\\/', '\\n', '\\r', '\\t', '\\\\'], ['"', "'", '/', ' ', ' ', ' ', '\\'], $text);
        $text = preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/li>/i', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
