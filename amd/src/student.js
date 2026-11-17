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
 * Student page JS module for block_scormreport.
 *
 * @module     block_scormreport/student
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    return {
        init: function(params) {
            var strViewtopics = params.strViewtopics;
            var strHidetopics = params.strHidetopics;

            // ── Donut chart (passed / incomplete / not attempted) ──
            /**
             * Draw the progress donut chart.
             */
            function drawDonut() {
                var canvas = document.getElementById("scorm-donut-chart");
                if (!canvas || !canvas.getContext) {
                    return;
                }
                var size = 160;
                var ratio = window.devicePixelRatio || 1;
                canvas.width = size * ratio;
                canvas.height = size * ratio;
                var ctx = canvas.getContext("2d");
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.clearRect(0, 0, size, size);

                var data = [
                    {value: parseInt(params.passed, 10) || 0, color: "#28a745"},
                    {value: parseInt(params.incomplete, 10) || 0, color: "#ffc107"},
                    {value: parseInt(params.notattempted, 10) || 0, color: "#adb5bd"}
                ];
                var total = data.reduce(function(sum, d) {
                    return sum + d.value;
                }, 0);
                var cx = size / 2,
cy = size / 2,
radius = size / 2 - 4,
thickness = 26;
                ctx.lineWidth = thickness;
                ctx.lineCap = "butt";

                if (total === 0) {
                    ctx.beginPath();
                    ctx.strokeStyle = "#e9ecef";
                    ctx.arc(cx, cy, radius - thickness / 2, 0, 2 * Math.PI);
                    ctx.stroke();
                    return;
                }
                var start = -Math.PI / 2;
                var gap = data.filter(function(d) {
                    return d.value > 0;
                }).length > 1 ? 0.02 : 0;
                data.forEach(function(d) {
                    if (d.value <= 0) {
                        return;
                    }
                    var angle = (d.value / total) * 2 * Math.PI;
                    ctx.beginPath();
                    ctx.strokeStyle = d.color;
                    ctx.arc(cx, cy, radius - thickness / 2, start + gap / 2, start + angle - gap / 2);
                    ctx.stroke();
                    start += angle;
                });
            }
            drawDonut();

            // ── Toggle SCO detail rows ──
            document.querySelectorAll(".sco-toggle-s").forEach(function(btn) {
                btn.addEventListener("click", function() {
                    var targetId = btn.getAttribute("data-target");
                    var row = document.getElementById(targetId);
                    if (row) {
                        if (row.style.display === "none") {
                            row.style.display = "";
                            btn.textContent = strHidetopics;
                            btn.setAttribute("aria-expanded", "true");
                        } else {
                            row.style.display = "none";
                            btn.textContent = strViewtopics;
                            btn.setAttribute("aria-expanded", "false");
                        }
                    }
                });
            });

            // ── Sort ──
            /**
             * Sort the rows of a table by one column.
             *
             * @param {HTMLTableElement} table Table to sort.
             * @param {number} col Column index.
             * @param {boolean} asc True for ascending order.
             */
            function sortTable(table, col, asc) {
                var tbody = table.querySelector("tbody");
                var rows = Array.from(tbody.querySelectorAll(":scope > tr:not(.table-primary):not(.sco-detail-row)"));
                rows.sort(function(a, b) {
                    var cellA = a.querySelectorAll("td")[col];
                    var cellB = b.querySelectorAll("td")[col];
                    if (!cellA || !cellB) {
 return 0;
}
                    var sortA = cellA.getAttribute("data-sort");
                    var sortB = cellB.getAttribute("data-sort");
                    if (sortA !== null && sortB !== null) {
                        return asc ? (parseFloat(sortA) - parseFloat(sortB)) : (parseFloat(sortB) - parseFloat(sortA));
                    }
                    var valA = cellA.innerText.trim().replace(/[^0-9a-záéíóúüñA-ZÁÉÍÓÚÜÑ.:% -]/g, "");
                    var valB = cellB.innerText.trim().replace(/[^0-9a-záéíóúüñA-ZÁÉÍÓÚÜÑ.:% -]/g, "");
                    var numA = parseFloat(valA.replace(",", ".").replace("%", ""));
                    var numB = parseFloat(valB.replace(",", ".").replace("%", ""));
                    var result = (!isNaN(numA) && !isNaN(numB))
                        ? numA - numB
                        : valA.localeCompare(valB, "es", {sensitivity: "base"});
                    return asc ? result : -result;
                });
                rows.forEach(function(r) {
                    tbody.appendChild(r);
                    var toggleBtn = r.querySelector(".sco-toggle-s");
                    if (toggleBtn) {
                        var detailRow = document.getElementById(toggleBtn.getAttribute("data-target"));
                        if (detailRow) {
 tbody.appendChild(detailRow);
}
                    }
                });
                table.querySelectorAll(".sortable-col").forEach(function(th) {
                    th.querySelector(".sort-icon").textContent = "↕";
                    th.classList.remove("sort-asc", "sort-desc");
                });
                var activeTh = table.querySelectorAll(".sortable-col")[col];
                if (activeTh) {
                    activeTh.querySelector(".sort-icon").textContent = asc ? "↑" : "↓";
                    activeTh.classList.add(asc ? "sort-asc" : "sort-desc");
                }
            }

            document.querySelectorAll(".sortable-table").forEach(function(table) {
                var sortState = {};
                table.querySelectorAll(".sortable-col").forEach(function(th) {
                    th.style.cursor = "pointer";
                    /**
                     * Sort the table by this column, toggling the direction on each call.
                     */
                    function doSort() {
                        var col = parseInt(th.getAttribute("data-col"));
                        var asc = sortState[col] === undefined ? true : !sortState[col];
                        sortState[col] = asc;
                        sortTable(table, col, asc);
                    }
                    th.addEventListener("click", doSort);
                    th.addEventListener("keydown", function(e) {
                        if (e.key === "Enter" || e.key === " ") {
 e.preventDefault(); doSort();
}
                    });
                });
            });
        }
    };
});
