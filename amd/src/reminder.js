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
 * Reminder page JS module for block_scormreport.
 *
 * @module     block_scormreport/reminder
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    return {
        init: function(params) {
            var previewHeader = params.previewHeader;
            var previewFooter = params.previewFooter;

            /**
             * Refresh the message preview with the current subject and text.
             */
            function updatePreview() {
                var body = document.getElementById("msg-body");
                if (!body) {
 return;
}
                var full = previewHeader + body.value + "\n\n" + previewFooter;
                var box = document.getElementById("preview-box");
                if (box) {
 box.textContent = full;
}
            }

            /**
             * Update the number of selected recipients shown on the send button.
             */
            function updateSendCount() {
                var count = document.querySelectorAll(".recipient-check:checked").length;
                var el = document.getElementById("send-count");
                if (el) {
 el.textContent = count;
}
                var btn = document.getElementById("btn-send");
                if (btn) {
                    btn.disabled = (count === 0);
                    btn.style.opacity = count === 0 ? "0.5" : "1";
                }
            }

            // Bind events
            updatePreview();
            var ta = document.getElementById("msg-body");
            if (ta) {
 ta.addEventListener("input", updatePreview);
}
            var subj = document.getElementById("msg-subject");
            if (subj) {
 subj.addEventListener("input", updatePreview);
}

            // Refresh the recipient preview when filtering or changing channel.
            var minpct = document.querySelector('select[name="minpct"]');
            if (minpct) {
                minpct.addEventListener("change", function() {
                    this.form.submit();
                });
            }
            document.querySelectorAll('input[name="channel"]').forEach(function(input) {
                input.addEventListener("change", function() {
                    this.form.submit();
                });
            });

            // Select all checkbox
            var selectAll = document.getElementById("select-all");
            if (selectAll) {
                selectAll.addEventListener("change", function() {
                    var checked = this.checked;
                    document.querySelectorAll(".recipient-check").forEach(function(c) {
                        c.checked = checked;
                    });
                    updateSendCount();
                });
            }

            // Individual checkboxes
            document.querySelectorAll(".recipient-check").forEach(function(c) {
                c.addEventListener("change", updateSendCount);
            });

            // Send button: confirm + set action (moved from inline onclick)
            var sendBtn = document.getElementById("btn-send");
            if (sendBtn) {
                sendBtn.addEventListener("click", function(e) {
                    var count = document.querySelectorAll(".recipient-check:checked").length;
                    if (count === 0) {
                        e.preventDefault();
                        window.alert(sendBtn.getAttribute("data-norecipients") || "");
                        return false;
                    }
                    var tpl = sendBtn.getAttribute("data-confirm") || "";
                    var msg = tpl.replace("__COUNT__", count);
                    if (!window.confirm(msg)) {
                        e.preventDefault();
                        return false;
                    }
                    var actionField = document.getElementById("form-action");
                    if (actionField) {
 actionField.value = "send";
}
                    return true;
                });
            }

        }
    };
});
