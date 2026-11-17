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
 * Report page JS module for block_scormreport.
 *
 * @module     block_scormreport/report
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    return {
        init: function(params) {
            var showingTemplate = params.showingTemplate;
            var aptLabel = params.aptLabel;
            var notaptLabel = params.notaptLabel;
            var aptThreshold = params.aptThreshold;
            var strViewtopics = params.strViewtopics;
            var strHidetopics = params.strHidetopics;

            // ── Columns selector modal (global view only) ──
            var colsBtn = document.getElementById("cols-toggle-btn");
            var colsOverlay = document.getElementById("cols-modal-overlay");
            if (colsBtn && colsOverlay) {
                var openCols = function() {
 colsOverlay.style.display = "flex";
};
                var closeCols = function() {
 colsOverlay.style.display = "none";
};
                colsBtn.addEventListener("click", openCols);
                var colsClose = document.getElementById("cols-modal-close");
                var colsCancel = document.getElementById("cols-modal-cancel");
                if (colsClose) {
                    colsClose.addEventListener("click", closeCols);
                }
                if (colsCancel) {
                    colsCancel.addEventListener("click", closeCols);
                }
                // Close when clicking the dark backdrop (outside the dialog).
                colsOverlay.addEventListener("click", function(e) {
                    if (e.target === colsOverlay) {
                        closeCols();
                    }
                });
                // Close with the Escape key.
                document.addEventListener("keydown", function(e) {
                    if (e.key === "Escape") {
                        closeCols();
                    }
                });
            }

            // ── Chart toggle ──
            document.querySelectorAll(".chart-toggle").forEach(function(toggle) {
                /**
                 * Show or hide the chart linked to this toggle.
                 */
                function doToggle() {
                    var targetId = toggle.getAttribute("data-target");
                    var el = document.getElementById(targetId);
                    var arrow = toggle.querySelector(".chart-toggle-arrow");
                    if (el) {
                        if (el.style.display === "none") {
                            el.style.display = "block";
                            if (arrow) {
 arrow.style.transform = "rotate(90deg)";
}
                            toggle.setAttribute("aria-expanded", "true");
                        } else {
                            el.style.display = "none";
                            if (arrow) {
 arrow.style.transform = "rotate(0deg)";
}
                            toggle.setAttribute("aria-expanded", "false");
                        }
                    }
                }
                toggle.addEventListener("click", doToggle);
                toggle.addEventListener("keydown", function(e) {
                    if (e.key === "Enter" || e.key === " ") {
 e.preventDefault(); doToggle();
}
                });
            });

            // ── Toggle SCO detail rows ──
            document.querySelectorAll(".sco-toggle").forEach(function(btn) {
                btn.addEventListener("click", function() {
                    var targetId = btn.getAttribute("data-target");
                    var row = document.getElementById(targetId);
                    // Each button can define its own show/hide labels; fall back to topic labels.
                    var showLbl = btn.getAttribute("data-show") || strViewtopics;
                    var hideLbl = btn.getAttribute("data-hide") || strHidetopics;
                    if (row) {
                        if (row.style.display === "none") {
                            row.style.display = "";
                            btn.textContent = hideLbl;
                            btn.setAttribute("aria-expanded", "true");
                        } else {
                            row.style.display = "none";
                            btn.textContent = showLbl;
                            btn.setAttribute("aria-expanded", "false");
                        }
                    }
                });
            });

            // ── Filters ──
            var filterVistos = document.getElementById("js-filter-vistos");
            var filterNota = document.getElementById("js-filter-nota");
            var filterApto = document.getElementById("js-filter-apto");
            var filterCompany = document.getElementById("js-filter-company");
            var countDiv = document.getElementById("js-filter-count");

            if (!filterVistos) {
 return;
}

            /**
             * Show only the rows that match the selected filters.
             */
            function applyFilters() {
                var minVistos = parseFloat(filterVistos.value) || 0;
                var minNota = parseFloat(filterNota.value) || 0;
                var aptoMode = filterApto.value;
                var companyVal = filterCompany ? filterCompany.value : "";
                var rows = document.querySelectorAll(".global-data-row");
                var visible = 0,
total = rows.length,
aptos = 0,
noaptos = 0;

                rows.forEach(function(row) {
                    var pcts = parseFloat(row.getAttribute("data-pctsuperados")) || 0;
                    var score = parseFloat(row.getAttribute("data-score")) || 0;
                    var company = row.getAttribute("data-company") || "";
                    var isApto = pcts >= aptThreshold;
                    if (isApto) {
 aptos++;
} else {
 noaptos++;
}

                    var matchVisto = pcts >= minVistos;
                    var matchNota = minNota === 0 || score >= minNota;
                    var matchApto = aptoMode === "all"
                        || (aptoMode === "apto" && isApto)
                        || (aptoMode === "noapto" && !isApto);
                    var matchCompany = companyVal === "" || company === companyVal;
                    var show = matchVisto && matchNota && matchApto && matchCompany;

                    row.style.display = show ? "" : "none";
                    if (show) {
 visible++;
}

                    // Also hide/show associated detail row
                    var toggleBtn = row.querySelector(".sco-toggle");
                    if (toggleBtn) {
                        var detailRow = document.getElementById(toggleBtn.getAttribute("data-target"));
                        if (detailRow && !show) {
 detailRow.style.display = "none";
}
                    }
                });

                if (countDiv) {
                    countDiv.innerHTML = showingTemplate
                        .replace("__VISIBLE__", visible)
                        .replace("__TOTAL__", total)
                        + " &nbsp;|&nbsp; "
                        + "<span style='color:#28a745;'>▲ " + aptLabel + ": <strong>" + aptos + "</strong></span> &nbsp;|&nbsp; "
                        + "<span style='color:#dc3545;'>▼ " + notaptLabel + ": <strong>" + noaptos + "</strong></span>";
                }
            }

            filterVistos.addEventListener("change", applyFilters);
            filterNota.addEventListener("change", applyFilters);
            filterApto.addEventListener("change", applyFilters);
            if (filterCompany) {
 filterCompany.addEventListener("change", applyFilters);
}
            applyFilters();

            var resetBtn = document.getElementById("js-reset-filters");
            if (resetBtn) {
                resetBtn.addEventListener("click", function() {
                    filterVistos.value = "0";
                    filterNota.value = "0";
                    filterApto.value = "all";
                    if (filterCompany) {
 filterCompany.value = "";
}
                    applyFilters();
                });
            }

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
                var rows = Array.from(tbody.querySelectorAll(":scope > tr.global-data-row"));
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
                    var result;
                    if (!isNaN(numA) && !isNaN(numB)) {
                        result = numA - numB;
                    } else {
                        result = valA.localeCompare(valB, "es", {sensitivity: "base"});
                    }
                    return asc ? result : -result;
                });
                rows.forEach(function(r) {
                    tbody.appendChild(r);
                    var toggleBtn = r.querySelector(".sco-toggle");
                    if (toggleBtn) {
                        var detailRow = document.getElementById(toggleBtn.getAttribute("data-target"));
                        if (detailRow) {
 tbody.appendChild(detailRow);
}
                    }
                });
                table.querySelectorAll(".sortable-col").forEach(function(th) {
                    var icon = th.querySelector(".sort-icon");
                    if (icon) {
 icon.textContent = "↕";
}
                    th.classList.remove("sort-asc", "sort-desc");
                    if (th.cellIndex === col) {
                        if (icon) {
 icon.textContent = asc ? "↑" : "↓";
}
                        th.classList.add(asc ? "sort-asc" : "sort-desc");
                    }
                });
            }

            document.querySelectorAll(".sortable-table").forEach(function(table) {
                var sortState = {};
                table.querySelectorAll(".sortable-col").forEach(function(th) {
                    th.style.cursor = "pointer";
                    /**
                     * Sort the table by this column, toggling the direction on each call.
                     */
                    function doSort() {
                        // Use the real cell position (works regardless of which
                        // optional columns are shown).
                        var col = th.cellIndex;
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
