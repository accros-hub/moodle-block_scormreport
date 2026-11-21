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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_scormreport;

/**
 * Tests for the ActivePresenter answer texts.
 *
 * @package    block_scormreport
 * @category   test
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_scormreport\answer_labels::class)]
final class answer_labels_test extends \basic_testcase {
    /**
     * The text of each item is read from an ActivePresenter presentation file.
     */
    public function test_parse_activepresenter(): void {
        $js = '/*! Copyright (c) Atomi Systems, Inc. */ var d={o:[' .
            '{i:4,m:15,r:"69_4",s:[{w:[],t:["<p style=\\"text-align:left;\\">Continue using it until it breaks.</p>","font"]}]},' .
            '{i:5,m:15,r:"69_5",s:[{w:[],t:["<p>Don\\\'t repair it &amp; ask</p>","font"]}]},' .
            '{i:6,m:15,r:"69_6",s:[{w:[],t:["<p>Caf\\u00e9 <b>outside</b></p>","font"]}]}]};';
        $labels = answer_labels::parse_activepresenter($js);
        $this->assertSame('Continue using it until it breaks.', $labels['69_4']);
        $this->assertSame("Don't repair it & ask", $labels['69_5']);
        $this->assertSame('Café outside', $labels['69_6']);
    }

    /**
     * Files that are not ActivePresenter presentations are ignored.
     */
    public function test_parse_other_files(): void {
        $this->assertSame([], answer_labels::parse_activepresenter('var x = {r:"1_2", t:["<p>Text</p>"]};'));
    }
}
