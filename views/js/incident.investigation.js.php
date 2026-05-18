<?php
$chart_data = $chart_data ?? [];
?>
window.mnzInvestigationData = <?= json_encode($chart_data) ?>;

jQuery(document).ready(function() {
	var d = window.mnzInvestigationData;
	if (!d) return;

	function escHtml(s) {
		if (s == null) return "";
		return String(s)
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#39;");
	}

	var clocks = (d.eventClocks || []).map(function(t) { return parseInt(t, 10); }).filter(function(t) { return !isNaN(t); });
	var monthKeys = d.monthKeys || [];
	var isDark = document.body.getAttribute("theme") === "dark-theme" || document.documentElement.getAttribute("theme") === "dark-theme";
	var barBg = isDark ? "#3a3a3a" : "#e9ecef";
	var barFill = "#0275b8";
	var tc = isDark ? "#e0e0e0" : "#333333";
	var lc = isDark ? "#999999" : "#666666";

	function computeAggregates(clkList) {
		var hourly = []; for (var h = 0; h < 24; h++) hourly[h] = 0;
		var weekly = []; for (var w = 0; w < 7; w++) weekly[w] = 0;
		var weeklyHourly = []; for (var w = 0; w < 7; w++) { weeklyHourly[w] = []; for (var h = 0; h < 24; h++) weeklyHourly[w][h] = 0; }
		var monthly = {}; var monthlyDaily = {};
		for (var m = 0; m < monthKeys.length; m++) { monthly[monthKeys[m]] = 0; monthlyDaily[monthKeys[m]] = []; for (var dd = 0; dd < 31; dd++) monthlyDaily[monthKeys[m]][dd] = 0; }
		for (var i = 0; i < clkList.length; i++) {
			var t = clkList[i]; var dt = new Date(t * 1000);
			var h = dt.getHours(); var w = dt.getDay();
			var ym = dt.getFullYear() + "-" + (String(dt.getMonth() + 1).padStart(2, "0")); var day = dt.getDate() - 1;
			hourly[h]++; weekly[w]++;
			if (weeklyHourly[w]) weeklyHourly[w][h]++;
			if (monthly[ym] !== undefined) { monthly[ym]++; if (monthlyDaily[ym] && day >= 0 && day < 31) monthlyDaily[ym][day]++; }
		}
		var monthVals = []; var monthDrill = [];
		for (var m = 0; m < monthKeys.length; m++) {
			var mk = monthKeys[m]; monthVals.push(monthly[mk] || 0); monthDrill.push(monthlyDaily[mk] ? monthlyDaily[mk].slice(0, 31) : []);
		}
		return { hourly: hourly, weekly: weekly, monthly: monthVals, weeklyHourlyDetails: weeklyHourly.map(function(arr) { return arr.slice(0, 24); }), monthlyDailyDetails: monthDrill };
	}

	var currentFilter = null; var currentAgg = null;
	function getCurrentClocks() {
		if (!currentFilter) return clocks;
		if (currentFilter.type === "heatmap") return clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getDay() === currentFilter.weekday && dt.getHours() === currentFilter.hour; });
		if (currentFilter.type === "month" && currentFilter.value != null) { var mk = monthKeys[currentFilter.value]; return clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getFullYear() + "-" + (String(dt.getMonth() + 1).padStart(2, "0")) === mk; }); }
		if (currentFilter.type === "day" && currentFilter.monthIdx != null && currentFilter.day != null) { var mk = monthKeys[currentFilter.monthIdx]; var dateStr = mk + "-" + (String(currentFilter.day).padStart(2, "0")); return clocks.filter(function(t) { var dt = new Date(t * 1000); var ds = dt.getFullYear() + "-" + (String(dt.getMonth() + 1).padStart(2, "0")) + "-" + (String(dt.getDate()).padStart(2, "0")); return ds === dateStr; }); }
		return clocks;
	}
	var avgH = (d.avgPerHour && d.avgPerHour > 0) ? d.avgPerHour : 0;
	var avgW = (d.avgPerWeekday && d.avgPerWeekday > 0) ? d.avgPerWeekday : 0;
	var avgM = (d.avgPerMonth && d.avgPerMonth > 0) ? d.avgPerMonth : 0;
	var avgSlot = (d.avgPerSlot && d.avgPerSlot > 0) ? d.avgPerSlot : 0;
	var timesStr = d.times || "x";

	function renderBar(elId, data, labels, color, clickable, dataType) {
		var el = document.getElementById(elId); if (!el) return; var avg = (dataType === "hourly" ? avgH : (dataType === "weekly" ? avgW : avgSlot));
		var max = Math.max.apply(Math, data); if (max === 0) max = 1;
		var html = '<div class="mnz-investigation-bars">';
		for (var i = 0; i < data.length; i++) {
			var h = (data[i] / max) * 80;
			var cls = clickable && data[i] > 0 ? " mnz-bar-clickable" : "";
			var idx = clickable ? ' data-index="' + i + '" data-type="' + escHtml(dataType) + '"' : "";
			var comp = ""; if (avg > 0 && data[i] > avg * 1.2) { var mult = (data[i] / avg).toFixed(1); comp = ' <span class="mnz-bar-compare">' + escHtml(mult + timesStr) + '</span>'; }
			html += '<div class="mnz-investigation-bar-item' + cls + '"' + idx + ' title="' + escHtml(labels[i] + ': ' + data[i]) + '"><div class="mnz-investigation-bar" style="height:' + Math.max(h, 4) + 'px;background:' + (data[i] > 0 ? color : barBg) + '"></div><span class="mnz-investigation-bar-label">' + escHtml(labels[i]) + comp + '</span></div>';
		}
		html += '</div>'; el.innerHTML = html;
	}

	function renderHeatmap(agg) {
		var el = document.getElementById("mnz-investigation-heatmap"); if (!el) return;
		var wh = agg.weeklyHourlyDetails || []; var maxVal = 0; for (var w = 0; w < 7; w++) for (var h = 0; h < 24; h++) if (wh[w] && wh[w][h] > maxVal) maxVal = wh[w][h]; if (maxVal === 0) maxVal = 1;
		var maintCells = getMaintenanceCells(getCurrentClocks());
		var html = '<div class="mnz-heatmap-grid"><div class="mnz-heatmap-labels-col"><div class="mnz-heatmap-corner"></div>';
		for (var w = 0; w < 7; w++) html += '<div class="mnz-heatmap-row-label">' + (d.weekLabels[w] || "") + '</div>';
		html += '</div><div class="mnz-heatmap-body"><div class="mnz-heatmap-hours-row">';
		for (var h = 0; h < 24; h++) html += '<div class="mnz-heatmap-hour-label">' + (d.hourLabels[h] || h) + '</div>';
		html += '</div>';
		for (var w = 0; w < 7; w++) {
			html += '<div class="mnz-heatmap-row">';
			for (var h = 0; h < 24; h++) {
				var v = (wh[w] && wh[w][h]) || 0; var intensity = v / maxVal;
				var col = intensity > 0 ? (intensity > 0.5 ? (intensity > 0.8 ? "#c0392b" : "#e67e22") : "#27ae60") : barBg;
				var cls = v > 0 ? " mnz-heatmap-cell-active" : "";
				var key = w + "-" + h; var isMaint = maintCells[key];
				var maintIcon = isMaint ? '<span class="mnz-heatmap-maint-icon ' + escHtml(d.maintIconClass || "zi zi-wrench-alt-small") + '" aria-hidden="true"></span>' : '';
				var maintTipRaw = isMaint ? " | " + (d.legendMaintenanceBorder || "Maintenance") + ": " + (Array.isArray(isMaint) ? isMaint.join(", ") : isMaint) : "";
				var titleRaw = (d.weekLabels[w] || "") + ' ' + (d.hourLabels[h] || h) + ': ' + v + maintTipRaw;
				html += '<div class="mnz-heatmap-cell' + cls + '" data-w="' + w + '" data-h="' + h + '" style="background:' + col + '" title="' + escHtml(titleRaw) + '"><span class="mnz-heatmap-cell-val">' + v + '</span>' + maintIcon + '</div>';
			}
			html += '</div>';
		}
		html += '</div></div>';
		el.innerHTML = html;
		var legEl = document.querySelector(".mnz-incident-section-heatmap .mnz-heatmap-legend");
		if (legEl) {
			var existing = legEl.querySelector(".mnz-heatmap-legend-maint");
			if (existing) existing.remove();
			var maintLeg = document.createElement("div");
			maintLeg.className = "mnz-heatmap-legend-maint";
			maintLeg.innerHTML = '<span class="mnz-heatmap-legend-maint-sample ' + escHtml(d.maintIconClass || "zi zi-wrench-alt-small") + '"></span><span class="mnz-heatmap-legend-maint-label">' + escHtml(d.legendMaintenanceBorder || "Icon = incident during maintenance") + '</span>';
			legEl.appendChild(maintLeg);
		}
		jQuery("#mnz-investigation-heatmap").off("click", ".mnz-heatmap-cell-active").on("click", ".mnz-heatmap-cell-active", function() {
			var center = jQuery(this); var w = parseInt(center.data("w"), 10); var h = parseInt(center.data("h"), 10);
			if (currentFilter && currentFilter.type === "heatmap" && currentFilter.weekday === w && currentFilter.hour === h) {
				currentFilter = null; currentAgg = computeAggregates(clocks); var m = jQuery("#mnz-monthly-drilldown"); m.removeClass("mnz-drilldown-visible").empty().append('<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + (d.hintMonth || "") + '</div>'); applyAndRender(); updateFilterBar(); return;
			}
			var filtered = clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getDay() === w && dt.getHours() === h; });
			currentFilter = { type: "heatmap", weekday: w, hour: h }; currentAgg = computeAggregates(filtered); jQuery("#mnz-weekly-drilldown, #mnz-monthly-drilldown").removeClass("mnz-drilldown-visible"); applyAndRender(); updateFilterBar();
		});
	}

	function getMaintenanceCells(clkList) {
		var cells = {}, m = (d.maintenances || []);
		clkList = clkList || clocks;
		var filter = currentFilter;
		for (var i = 0; i < clkList.length; i++) {
			var t = parseInt(clkList[i], 10);
			if (isNaN(t)) continue;
			for (var j = 0; j < m.length; j++) {
				if (t >= m[j].active_since && t <= m[j].active_till) {
					var dt = new Date(t * 1000);
					var w = dt.getDay(), h = dt.getHours();
					var key = w + "-" + h;
					if (!cells[key]) cells[key] = [];
					if (cells[key].indexOf(m[j].name) === -1) cells[key].push(m[j].name);
					break;
				}
			}
		}
		if (filter && filter.type === "day" && filter.weekday != null) {
			var mk = monthKeys[filter.monthIdx]; if (!mk) return cells;
			var parts = mk.split("-"); var y = parseInt(parts[0], 10), mo = parseInt(parts[1], 10) - 1;
			var dayStart = Math.floor(new Date(y, mo, filter.day, 0, 0, 0).getTime() / 1000);
			var dayEnd = Math.floor(new Date(y, mo, filter.day, 23, 59, 59).getTime() / 1000);
			for (var j = 0; j < m.length; j++) {
				if (m[j].active_till >= dayStart && m[j].active_since <= dayEnd) {
					for (var t = Math.max(m[j].active_since, dayStart); t <= Math.min(m[j].active_till, dayEnd); t += 3600) {
						var dt = new Date(t * 1000);
						var h = dt.getHours();
						var key = filter.weekday + "-" + h;
						if (!cells[key]) cells[key] = [];
						if (cells[key].indexOf(m[j].name) === -1) cells[key].push(m[j].name);
					}
				}
			}
		}
		return cells;
	}
	function getMonthMaintenances(monthKey) {
		var m = (d.maintenances || []), result = [];
		var parts = (monthKey || "").split("-");
		if (parts.length < 2) return result;
		var y = parseInt(parts[0], 10), mo = parseInt(parts[1], 10) - 1;
		var monthStart = Math.floor(new Date(y, mo, 1).getTime() / 1000);
		var monthEnd = Math.floor(new Date(y, mo + 1, 0, 23, 59, 59).getTime() / 1000);
		var fmt = function(d) { return String(d.getDate()).padStart(2, "0") + "/" + (String(d.getMonth() + 1).padStart(2, "0")); };
		for (var j = 0; j < m.length; j++) {
			if (m[j].active_till >= monthStart && m[j].active_since <= monthEnd) {
				var clampS = Math.max(m[j].active_since, monthStart);
				var clampT = Math.min(m[j].active_till, monthEnd);
				var dS = new Date(clampS * 1000), dT = new Date(clampT * 1000);
				var range = fmt(dS) === fmt(dT) ? fmt(dS) : fmt(dS) + "-" + fmt(dT);
				result.push({ name: m[j].name, range: range });
			}
		}
		return result;
	}
	function getMonthMaintenanceDays(monthKey) {
		var days = {}, m = (d.maintenances || []);
		var parts = (monthKey || "").split("-");
		if (parts.length < 2) return days;
		var y = parseInt(parts[0], 10), mo = parseInt(parts[1], 10) - 1;
		var daysInMonth = new Date(y, mo + 1, 0).getDate();
		for (var day = 1; day <= daysInMonth; day++) {
			var dayStart = Math.floor(new Date(y, mo, day, 0, 0, 0).getTime() / 1000);
			var dayEnd = Math.floor(new Date(y, mo, day, 23, 59, 59).getTime() / 1000);
			for (var j = 0; j < m.length; j++) {
				if (m[j].active_till >= dayStart && m[j].active_since <= dayEnd) {
					days[day] = 1;
					break;
				}
			}
		}
		return days;
	}
	function getDayMaintenances(monthKey, dayNum) {
		var result = [], m = (d.maintenances || []);
		var parts = (monthKey || "").split("-");
		if (parts.length < 2) return result;
		var y = parseInt(parts[0], 10), mo = parseInt(parts[1], 10) - 1;
		var dayStart = Math.floor(new Date(y, mo, dayNum, 0, 0, 0).getTime() / 1000);
		var dayEnd = Math.floor(new Date(y, mo, dayNum, 23, 59, 59).getTime() / 1000);
		var fmt = function(d) { return String(d.getDate()).padStart(2, "0") + "/" + (String(d.getMonth() + 1).padStart(2, "0")); };
		for (var j = 0; j < m.length; j++) {
			if (m[j].active_till >= dayStart && m[j].active_since <= dayEnd) {
				var clampS = Math.max(m[j].active_since, dayStart);
				var clampT = Math.min(m[j].active_till, dayEnd);
				var dS = new Date(clampS * 1000), dT = new Date(clampT * 1000);
				var range = fmt(dS) === fmt(dT) ? fmt(dS) : fmt(dS) + "-" + fmt(dT);
				result.push({ name: m[j].name, range: range });
			}
		}
		return result;
	}
	function monthHadMaintenance(monthKey) {
		var arr = getMonthMaintenances(monthKey);
		return arr.length > 0 ? arr : null;
	}
	function renderMonthly(elId, data, labels) {
		var el = document.getElementById(elId); if (!el) return;
		var max = Math.max.apply(Math, data); if (max === 0) max = 1;
		var html = '<div class="mnz-investigation-monthly-bars">';
		for (var i = 0; i < data.length; i++) {
			var mk = monthKeys[i];
			var h = (data[i] / max) * 100; var col = data[i] > 0 ? (i === data.length - 1 ? "#ff9800" : barFill) : barBg; var cls = data[i] > 0 ? " mnz-bar-clickable" : "";
			var comp = ""; if (avgM > 0 && data[i] > avgM * 1.2) { var mult = (data[i] / avgM).toFixed(1); comp = ' <span class="mnz-bar-compare">' + mult + timesStr + '</span>'; }
			var maintList = monthHadMaintenance(mk);
			var maintTooltip = maintList ? " " + (d.tooltipMaintenances || "Maintenances") + ": " + maintList.map(function(m) { return m.name + " (" + m.range + ")"; }).join(", ") + "." : "";
			var title = labels[i] + ": " + data[i] + " " + (d.tooltipIncidents || "incidents") + maintTooltip;
			var stripHtml = "";
			var hadMaint = maintList && maintList.length > 0;
			stripHtml = '<div class="mnz-month-maint-indicator">';
			var maintData = hadMaint ? escHtml(JSON.stringify(maintList)) : "";
			var maintCount = hadMaint ? maintList.length : 0;
			stripHtml += '<span class="mnz-month-maint-bar' + (hadMaint ? " mnz-maint-day-active mnz-maint-clickable" : "") + '"' + (maintData ? ' data-maints="' + maintData + '"' : '') + '>' + (maintCount > 0 ? maintCount : '') + '</span>';
			stripHtml += '</div>';
			html += '<div class="mnz-investigation-monthly-item' + cls + '" data-index="' + i + '" data-type="monthly" title="' + escHtml(title) + '"><div class="mnz-monthly-item-header"><span class="mnz-monthly-value">' + data[i] + '</span>' + comp + '</div><div class="mnz-investigation-monthly-bar" style="height:' + Math.max(h, 4) + 'px;background:' + col + '"></div>' + stripHtml + '<span class="mnz-investigation-monthly-label" style="color:' + lc + '">' + escHtml(labels[i]) + '</span></div>';
		}
		html += '</div>';
		html += '<div class="mnz-drilldown-maint-legend">' + escHtml(d.legendMaintenanceBar || d.legendMaintenanceBorder || "Orange bar = maintenance period") + '</div>';
		el.innerHTML = html;
	}

	function renderDrilldown(containerId, data, labels, title, barColor, isDayDrilldown, monthKey) {
		var c = document.getElementById(containerId); if (!c) return;
		var max = Math.max.apply(Math, data); if (max === 0) max = 1;
		var hintDay = (isDayDrilldown && d.hintDay) ? '<div class="mnz-drilldown-subhint">' + escHtml(d.hintDay) + '</div>' : "";
		var html = '<div class="mnz-drilldown-content"><div class="mnz-drilldown-title">' + escHtml(title) + '</div>' + hintDay + '<div class="mnz-drilldown-chart"><div class="mnz-investigation-bars mnz-drilldown-daily-bars">';
		for (var i = 0; i < data.length; i++) {
			var h = (data[i] / max) * 80; var day = i + 1;
			var cls = (isDayDrilldown && data[i] > 0) ? " mnz-drilldown-day-bar mnz-bar-clickable" : ""; var dday = (isDayDrilldown && data[i] > 0) ? ' data-day="' + day + '"' : "";
			var valSpan = '<span class="mnz-drilldown-bar-value">' + data[i] + '</span>';
			html += '<div class="mnz-investigation-bar-item mnz-drilldown-bar-item' + cls + '"' + dday + ' title="' + escHtml(labels[i] + ': ' + data[i]) + '">' + valSpan + '<div class="mnz-investigation-bar" style="height:' + Math.max(h, 4) + 'px;background:' + (data[i] > 0 ? barColor : barBg) + '"></div><span class="mnz-investigation-bar-label">' + escHtml(labels[i]) + '</span></div>';
		}
		html += '</div>';
		if (monthKey && isDayDrilldown) {
			var maintDays = getMonthMaintenanceDays(monthKey);
			if (Object.keys(maintDays).length > 0) {
				var stripHtml = '<div class="mnz-drilldown-maint-strip">';
				var daysInMonth = 31;
				var parts = (monthKey || "").split("-");
				if (parts.length >= 2) { var y = parseInt(parts[0], 10), mo = parseInt(parts[1], 10) - 1; daysInMonth = new Date(y, mo + 1, 0).getDate(); }
				for (var dNum = 1; dNum <= daysInMonth; dNum++) {
					var dayMaints = maintDays[dNum] ? getDayMaintenances(monthKey, dNum) : [];
					var dayMaintData = dayMaints.length > 0 ? escHtml(JSON.stringify(dayMaints)) : "";
					var dayMaintCount = dayMaints.length;
					stripHtml += '<span class="mnz-drilldown-maint-day' + (maintDays[dNum] ? " mnz-maint-day-active mnz-maint-clickable" : "") + '" data-day="' + dNum + '"' + (dayMaintData ? ' data-maints="' + dayMaintData + '"' : '') + '>' + (dayMaintCount > 0 ? dayMaintCount : '') + '</span>';
				}
				stripHtml += '</div><div class="mnz-drilldown-maint-legend">' + escHtml(d.legendMaintenanceBar || d.legendMaintenanceBorder || "Orange bar = maintenance period") + '</div>';
				html += stripHtml;
			}
		}
		html += '</div><button type="button" class="btn btn-alt mnz-drilldown-close">' + escHtml(d.close || "Close") + '</button></div>';
		c.innerHTML = html; c.classList.add("mnz-drilldown-visible");
	}

	function setupCloseHandler(containerId, hint, hintClass) {
		hintClass = hintClass || "";
		jQuery("#" + containerId + " .mnz-drilldown-close").off("click").on("click", function() { var c = jQuery("#" + containerId); c.removeClass("mnz-drilldown-visible").empty(); c.append('<div class="mnz-drilldown-hint ' + escHtml(hintClass) + '">' + escHtml(hint) + '</div>'); });
	}

	function showMaintTooltip(target, maints) {
		if (!maints || maints.length === 0) return;
		var pop = document.getElementById("mnz-maint-tooltip-popover");
		if (!pop) {
			pop = document.createElement("div");
			pop.id = "mnz-maint-tooltip-popover";
			pop.className = "overlay-dialogue wordbreak";
			var appendTo = document.querySelector(".wrapper") || document.body;
			appendTo.appendChild(pop);
			jQuery(document).on("keydown.mnzMaintTooltip", function(e) { if (e.key === "Escape") closeMaintTooltip(); });
		}
		var items = maints.map(function(m) { return escHtml((m.name || "") + " (" + (m.range || "") + ")"); }).join("<br>");
		var closeClass = d.btnOverlayCloseClass || "btn-overlay-close";
		var closeTitle = d.close || "Close";
		pop.innerHTML = '<button type="button" class="' + escHtml(closeClass) + '" title="' + escHtml(closeTitle) + '"></button><div class="hintbox-wrap">' + items + '</div>';
		jQuery(pop).find("." + closeClass).off("click").on("click", closeMaintTooltip);
		pop.classList.add("mnz-maint-tooltip-visible");
		pop.style.display = "block";
		var rect = target.getBoundingClientRect();
		var top = rect.bottom + 6;
		var left = rect.left + (rect.width / 2) - 120;
		if (left < 8) left = 8;
		if (left > window.innerWidth - 256) left = Math.max(8, window.innerWidth - 256);
		if (top > window.innerHeight - 100) top = rect.top - 80;
		pop.style.position = "fixed";
		pop.style.top = top + "px";
		pop.style.left = left + "px";
		setTimeout(function() {
			jQuery(document).one("click.mnzMaintTooltip", function(e) {
				if (!pop.contains(e.target) && !jQuery(e.target).closest(".mnz-maint-clickable").length) closeMaintTooltip();
			});
		}, 0);
	}
	function closeMaintTooltip() {
		var pop = document.getElementById("mnz-maint-tooltip-popover");
		if (pop) { pop.classList.remove("mnz-maint-tooltip-visible"); pop.style.display = "none"; }
	}

	jQuery(document).off("click", ".mnz-maint-clickable").on("click", ".mnz-maint-clickable", function(e) {
		e.preventDefault();
		e.stopPropagation();
		var maints = [];
		try { maints = JSON.parse(jQuery(this).attr("data-maints") || "[]"); } catch (x) {}
		if (maints.length > 0) showMaintTooltip(this, maints);
	});

	function countSameSlotLastWeek(weekday, hour) {
		var now = Math.floor(Date.now() / 1000); var weekSec = 604800; var lastWeekEnd = now - weekSec; var lastWeekStart = lastWeekEnd - weekSec;
		var n = 0; for (var i = 0; i < clocks.length; i++) { var t = clocks[i]; if (t >= lastWeekStart && t < lastWeekEnd) { var dt = new Date(t * 1000); if (dt.getDay() === weekday && dt.getHours() === hour) n++; } } return n;
	}

	function countSameDayLastWeek(weekday) {
		var now = Math.floor(Date.now() / 1000); var weekSec = 604800; var lastWeekEnd = now - weekSec; var lastWeekStart = lastWeekEnd - weekSec;
		var n = 0; for (var i = 0; i < clocks.length; i++) { var t = clocks[i]; if (t >= lastWeekStart && t < lastWeekEnd) { var dt = new Date(t * 1000); if (dt.getDay() === weekday) n++; } } return n;
	}

	function updateFilterBar() {
		var fb = document.getElementById("mnz-investigation-filter-bar"); if (!fb) return;
		if (!currentFilter) { fb.innerHTML = ""; fb.classList.remove("mnz-filter-active"); return; }
		var lbl = ""; var compHtml = "";
		if (currentFilter.type === "hour") lbl = (d.hourLabels || [])[currentFilter.value] || currentFilter.value + "h";
		else if (currentFilter.type === "weekday") lbl = (d.weekLabels || [])[currentFilter.value] || "";
		else if (currentFilter.type === "month") lbl = (d.monthLabels || [])[currentFilter.value] || "";
		else if (currentFilter.type === "day") lbl = (currentFilter.dateStr || "") + " (" + ((d.weekLabels || [])[currentFilter.weekday] || "") + ")";
		else if (currentFilter.type === "heatmap") { lbl = (d.weekLabels || [])[currentFilter.weekday] + " " + ((d.hourLabels || [])[currentFilter.hour] || currentFilter.hour + "h"); var lastWeek = countSameSlotLastWeek(currentFilter.weekday, currentFilter.hour); compHtml = ' <span class="mnz-filter-comparison">' + escHtml(d.sameSlotLastWeek || "") + ': ' + lastWeek + ' ' + escHtml(d.incidents || "incidents") + '</span>'; }
		else if (currentFilter.type === "day" && currentFilter.weekday != null) { var lastWeek = countSameDayLastWeek(currentFilter.weekday); compHtml = ' <span class="mnz-filter-comparison">' + escHtml(d.sameDayLastWeek || "") + ': ' + lastWeek + ' ' + escHtml(d.incidents || "incidents") + '</span>'; }
		fb.classList.add("mnz-filter-active");
		fb.innerHTML = '<span class="mnz-filter-label">' + escHtml(d.filteredBy || "") + ': ' + escHtml(lbl) + '</span>' + compHtml + ' <button type="button" class="btn btn-alt mnz-filter-clear">' + escHtml(d.clearFilter || "") + '</button>';
		jQuery("#mnz-investigation-filter-bar .mnz-filter-clear").off("click").on("click", function() { currentFilter = null; currentAgg = computeAggregates(clocks); var m = jQuery("#mnz-monthly-drilldown"); m.removeClass("mnz-drilldown-visible").empty().append('<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + (d.hintMonth || "") + '</div>'); applyAndRender(); updateFilterBar(); });
	}

	function applyAndRender() {
		currentAgg = currentAgg || computeAggregates(clocks);
		renderHeatmap(currentAgg);
		renderMonthly("mnz-investigation-monthly-chart", currentAgg.monthly, d.monthLabels);
		var dayLabs = (d.dayLabels && d.dayLabels.length) ? d.dayLabels : []; while (dayLabs.length < 31) dayLabs.push(dayLabs.length + 1);
		jQuery("#mnz-investigation-monthly-chart").off("click", ".mnz-bar-clickable").on("click", ".mnz-bar-clickable", function(e) {
			if (jQuery(e.target).closest(".mnz-month-maint-indicator").length) return;
			var idx = parseInt(jQuery(this).data("index"), 10); var mk = monthKeys[idx]; if (!mk) return;
			if (currentFilter && currentFilter.type === "month" && currentFilter.value === idx) {
				currentFilter = null; currentAgg = computeAggregates(clocks); var m = jQuery("#mnz-monthly-drilldown"); m.removeClass("mnz-drilldown-visible").empty().append('<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + (d.hintMonth || "") + '</div>'); applyAndRender(); updateFilterBar(); return;
			}
			var filtered = clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getFullYear() + "-" + (String(dt.getMonth() + 1).padStart(2, "0")) === mk; });
			currentFilter = { type: "month", value: idx }; currentAgg = computeAggregates(filtered); jQuery("#mnz-weekly-drilldown").removeClass("mnz-drilldown-visible"); applyAndRender(); updateFilterBar();
			var dd = currentAgg.monthlyDailyDetails[idx]; if (dd) renderDrilldown("mnz-monthly-drilldown", dd, dayLabs, (d.dailyDistribution || "Daily distribution") + " - " + d.monthLabels[idx], barFill, true, mk); setupCloseHandler("mnz-monthly-drilldown", d.hintMonth || "", "mnz-drilldown-hint-monthly");
			jQuery("#mnz-monthly-drilldown").off("click", ".mnz-drilldown-day-bar").on("click", ".mnz-drilldown-day-bar", function() {
				var day = parseInt(jQuery(this).data("day"), 10); var monthIdx = (currentFilter.type === "day" ? currentFilter.monthIdx : currentFilter.value); var mk = monthKeys[monthIdx]; if (!mk || !day) return;
				if (currentFilter && currentFilter.type === "day" && currentFilter.monthIdx === monthIdx && currentFilter.day === day) {
					currentFilter = null; currentAgg = computeAggregates(clocks); var m = jQuery("#mnz-monthly-drilldown"); m.removeClass("mnz-drilldown-visible").empty().append('<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + (d.hintMonth || "") + '</div>'); applyAndRender(); updateFilterBar(); return;
				}
				var dateStr = mk + "-" + (String(day).padStart(2, "0"));
				var filtered = clocks.filter(function(t) { var dt = new Date(t * 1000); var ds = dt.getFullYear() + "-" + (String(dt.getMonth() + 1).padStart(2, "0")) + "-" + (String(dt.getDate()).padStart(2, "0")); return ds === dateStr; });
				var parts = dateStr.split("-"); var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10) - 1, dNum = parseInt(parts[2], 10); var sampleDate = new Date(y, m, dNum); var weekday = sampleDate.getDay();
				var hourly = []; for (var h = 0; h < 24; h++) hourly[h] = 0; for (var i = 0; i < filtered.length; i++) hourly[new Date(filtered[i] * 1000).getHours()]++;
				var wh = []; for (var w = 0; w < 7; w++) { wh[w] = []; for (var h = 0; h < 24; h++) wh[w][h] = 0; } for (var h = 0; h < 24; h++) wh[weekday][h] = hourly[h];
				currentFilter = { type: "day", monthIdx: monthIdx, day: day, weekday: weekday, dateStr: dateStr };
				currentAgg = { hourly: hourly, weekly: [], monthly: currentAgg.monthly, weeklyHourlyDetails: wh, monthlyDailyDetails: currentAgg.monthlyDailyDetails };
				renderHeatmap(currentAgg); updateFilterBar(); jQuery("html, body").animate({ scrollTop: jQuery("#mnz-investigation-heatmap").offset().top - 80 }, 300);
			});
		});
		updateFilterBar();
	}

	currentAgg = computeAggregates(clocks); applyAndRender();
	if (d.hostid) loadSLAbyService(d.hostid);

	function collectSLIValues(data) {
		var values = [];
		function fromService(s) { if (s && s.has_sla && s.sli != null) values.push(parseFloat(s.sli)); }
		for (var t = 0; t < data.service_trees.length; t++) {
			var tree = data.service_trees[t];
			if (tree.path_to_root) for (var p = 0; p < tree.path_to_root.length; p++) fromService(tree.path_to_root[p]);
			fromService(tree.impacted_service);
			if (tree.children_tree) for (var c = 0; c < tree.children_tree.length; c++) fromService(tree.children_tree[c]);
		}
		return values;
	}

	function collectSLO(data) {
		var slos = [];
		function fromService(s) { if (s && s.has_sla && s.slo != null) { var v = parseFloat(s.slo); if (!isNaN(v)) slos.push(v); } }
		for (var t = 0; t < data.service_trees.length; t++) {
			var tree = data.service_trees[t];
			if (tree.path_to_root) for (var p = 0; p < tree.path_to_root.length; p++) fromService(tree.path_to_root[p]);
			fromService(tree.impacted_service);
			if (tree.children_tree) for (var c = 0; c < tree.children_tree.length; c++) fromService(tree.children_tree[c]);
		}
		return slos.length > 0 ? Math.min.apply(Math, slos) : 99.9;
	}

	function collectServicesWithSLA(data) {
		var out = []; var seen = {};
		for (var t = 0; t < data.service_trees.length; t++) {
			var s = data.service_trees[t].impacted_service;
			if (s && s.has_sla && s.sli != null) {
				var key = (s.serviceid != null ? String(s.serviceid) : s.name || ("t" + t));
				if (!seen[key]) {
					seen[key] = 1;
					var displayName = s.name || "";
					if (s.inherited_from_name) displayName += " (" + (d.inheritedFrom || "inherited from") + " " + s.inherited_from_name + ")";
					out.push({ name: displayName, sli: parseFloat(s.sli), slo: s.slo != null ? parseFloat(s.slo) : 99.9, treeIndex: t });
				}
			}
		}
		return out;
	}

	function renderSLIGauge(value, slo) {
		var wrap = document.getElementById("mnz-sla-gauge-wrap"); if (!wrap) return;
		var val = (value != null && !isNaN(value)) ? Math.min(100, Math.max(0, value)) : null;
		var sloNum = (slo != null && !isNaN(slo)) ? Math.min(100, Math.max(1, parseFloat(slo))) : 99.9;
		var sloLow = Math.max(0, sloNum - 5);
		var fillColor = "#6c757d";
		if (val != null) { if (val < sloLow) fillColor = "#c0392b"; else if (val < sloNum) fillColor = "#f1c40f"; else fillColor = "#27ae60"; }
		wrap.innerHTML = "";
		var box = document.createElement("div"); box.className = "mnz-sla-gauge mnz-sla-gauge-donut"; wrap.appendChild(box);
		var pct = (val != null) ? val / 100 : 0; var R = 45, r = 27;
		function polar(cx, cy, rad, deg) { var a = (deg - 90) * Math.PI / 180; return cx + rad * Math.cos(a) + "," + (cy + rad * Math.sin(a)); }
		function arcPath(r1, r2, a1, a2) { var span = a2 - a1; if (span <= 0) span += 360; var p1 = polar(50, 50, r1, a1), p2 = polar(50, 50, r1, a2), p3 = polar(50, 50, r2, a2), p4 = polar(50, 50, r2, a1); var big = span >= 180 ? 1 : 0; return "M " + p1 + " A " + r1 + "," + r1 + " 0 " + big + ",1 " + p2 + " L " + p3 + " A " + r2 + "," + r2 + " 0 " + big + ",0 " + p4 + " Z"; }
		var aEnd = pct >= 0.999 ? 359.999 : 360 * pct; var aStart = pct <= 0.001 ? 0.001 : 360 * pct;
		var ns = "http://www.w3.org/2000/svg";
		var svg = document.createElementNS(ns, "svg"); svg.setAttribute("viewBox", "0 0 100 100"); svg.setAttribute("width", "160"); svg.setAttribute("height", "160"); svg.classList.add("mnz-sli-donut-svg");
		var aR = 360 * (sloLow / 100), aY = 360 * (sloNum / 100); if (aR < 0.5) aR = 0.5; if (aY - aR < 0.5) aY = aR + 0.5; if (360 - aY < 0.5) aY = 359.5;
		var pb1 = document.createElementNS(ns, "path"); pb1.setAttribute("d", arcPath(R, r, 0, aR)); pb1.style.setProperty("fill", "#c0392b", "important"); pb1.classList.add("mnz-sli-donut-bg", "mnz-sli-donut-bg-red"); svg.appendChild(pb1);
		var pb2 = document.createElementNS(ns, "path"); pb2.setAttribute("d", arcPath(R, r, aR, aY)); pb2.style.setProperty("fill", "#f1c40f", "important"); pb2.classList.add("mnz-sli-donut-bg", "mnz-sli-donut-bg-yellow"); svg.appendChild(pb2);
		var pb3 = document.createElementNS(ns, "path"); pb3.setAttribute("d", arcPath(R, r, aY, 359.999)); pb3.style.setProperty("fill", "#27ae60", "important"); pb3.classList.add("mnz-sli-donut-bg", "mnz-sli-donut-bg-green"); svg.appendChild(pb3);
		if (pct < 0.999) { var pe = document.createElementNS(ns, "path"); pe.setAttribute("d", arcPath(R, r, aStart, 359.999)); pe.style.setProperty("fill", "#5a6268", "important"); pe.classList.add("mnz-sli-donut-empty"); svg.appendChild(pe); }
		var t1 = document.createElementNS(ns, "text"); t1.setAttribute("x", 50); t1.setAttribute("y", 54); t1.setAttribute("text-anchor", "middle"); t1.classList.add("mnz-sli-donut-value-text"); t1.style.setProperty("fill", fillColor, "important"); t1.setAttribute("font-size", "13"); t1.setAttribute("font-weight", "bold"); t1.textContent = (val != null ? val.toFixed(1) : "-") + " %"; svg.appendChild(t1);
		box.appendChild(svg);
		var desc = document.createElement("div"); desc.className = "mnz-sli-donut-desc"; desc.style.cssText = "text-align:center;"; desc.textContent = (d.currentSLI || "Current SLI") + " (SLO " + sloNum.toFixed(1) + "%)"; box.appendChild(desc);
		if (mnzSlaData) { var treeBtn = document.createElement("button"); treeBtn.type = "button"; treeBtn.className = "btn btn-icon mnz-sla-tree-btn"; treeBtn.title = (d.viewServiceTree || "View service tree with SLI"); treeBtn.innerHTML = '<span class="' + escHtml(d.iconClass || "icon-pointer") + '"></span>'; treeBtn.onclick = function(e) { e.preventDefault(); openSLATreeModal(); }; box.appendChild(treeBtn); }
	}

	var mnzSlaData = null; var mnzSlaMode = "0";

	function loadSLAbyService(hostid) {
		var el = document.getElementById("mnz-sla-service-container"); var gw = document.getElementById("mnz-sla-gauge-wrap"); var sel = document.getElementById("mnz-sla-mode-selector"); if (!el) return;
		el.innerHTML = '<div class="mnz-sla-loading"><span>' + escHtml(d.loadingServices || "Loading services...") + '</span></div>'; if (gw) gw.innerHTML = ""; if (sel) sel.innerHTML = ""; mnzSlaData = null; closeSLATreeModal();
		var fd = new FormData(); fd.append("hostid", hostid);
		fetch("zabbix.php?action=incident.serviceimpact", { method: "POST", body: fd })
			.then(function(r) { return r.json(); })
			.then(function(res) {
				if (!res.success) { el.innerHTML = '<div class="mnz-sla-error">' + escHtml(res.error && res.error.message ? res.error.message : "Error") + '</div>'; if (gw) gw.innerHTML = ""; return; }
				var data = res.data;
				if (!data.service_trees || data.service_trees.length === 0) { el.innerHTML = '<div class="mnz-sla-empty">' + escHtml(d.noServicesAffected || "No services affected by this incident") + '</div>'; renderSLIGauge(null); return; }
				mnzSlaData = data; var services = collectServicesWithSLA(data); var slo = collectSLO(data);
				if (sel && services.length >= 2) {
					var html = '<select class="mnz-sla-mode-select" title="' + escHtml(d.view || "View") + '">';
					for (var i = 0; i < services.length; i++) html += '<option value="' + i + '">' + escHtml((services[i].name || ((d.service || "Service") + " " + (i + 1))) + " (" + services[i].sli.toFixed(1) + "%)") + '</option>';
					html += '</select>'; sel.innerHTML = html; sel.classList.add("mnz-sla-mode-selector-visible");
					jQuery(sel).off("change").on("change", function() { mnzSlaMode = jQuery(this).val(); updateSLIGaugeFromMode(); });
				} else if (sel) { sel.innerHTML = ""; sel.classList.remove("mnz-sla-mode-selector-visible"); }
				mnzSlaMode = services.length > 0 ? "0" : "min"; updateSLIGaugeFromMode(); el.innerHTML = "";
			})
			.catch(function() { el.innerHTML = '<div class="mnz-sla-error">' + escHtml(d.failedToLoadServiceImpact || "Failed to load service impact") + '</div>'; if (gw) gw.innerHTML = ""; });
	}

	function updateSLIGaugeFromMode() {
		if (!mnzSlaData) return; var services = collectServicesWithSLA(mnzSlaData); var slo = collectSLO(mnzSlaData);
		var sel = document.getElementById("mnz-sla-mode-selector"); if (sel && sel.querySelector("select")) mnzSlaMode = sel.querySelector("select").value;
		var val = null; var idx = parseInt(mnzSlaMode, 10); if (!isNaN(idx) && idx >= 0 && idx < services.length) { val = services[idx].sli; slo = services[idx].slo; }
		renderSLIGauge(val, slo);
	}

	function openSLATreeModal() {
		if (!mnzSlaData) return; var m = document.getElementById("mnz-sla-tree-modal"); if (!m) return;
		var services = collectServicesWithSLA(mnzSlaData); var slo = collectSLO(mnzSlaData);
		var sel = document.getElementById("mnz-sla-mode-selector"); if (sel && sel.querySelector("select")) mnzSlaMode = sel.querySelector("select").value;
		var body = renderSLATreeForModal(mnzSlaData, slo, mnzSlaMode, services);
		m.innerHTML = '<div class="mnz-sla-modal-backdrop"></div><div class="mnz-sla-modal-dialog"><div class="mnz-sla-modal-header"><h4>' + escHtml(d.serviceTreeAndSLI || "Service tree and SLI") + '</h4><button type="button" class="btn ' + escHtml(d.btnOverlayCloseClass || "overlay-close-btn") + ' mnz-sla-modal-close" aria-label="' + escHtml(d.close || "Close") + '"></button></div><div class="mnz-sla-modal-body">' + body + '</div></div>';
		m.classList.add("mnz-sla-modal-visible"); m.setAttribute("aria-hidden", "false"); document.body.style.overflow = "hidden";
		jQuery(m).off("click.mnzSlaTree").on("click.mnzSlaTree", ".mnz-sla-modal-backdrop, .mnz-sla-modal-close", closeSLATreeModal);
		jQuery(document).off("keydown.mnzSlaTree").on("keydown.mnzSlaTree", function(e) { if (e.key === "Escape") closeSLATreeModal(); });
	}

	function closeSLATreeModal() {
		var m = document.getElementById("mnz-sla-tree-modal"); if (!m) return;
		m.classList.remove("mnz-sla-modal-visible"); m.setAttribute("aria-hidden", "true"); m.innerHTML = ""; document.body.style.overflow = "";
		jQuery(m).off("click.mnzSlaTree"); jQuery(document).off("keydown.mnzSlaTree");
	}

	function renderSLATreeForModal(data, slo, mode, services) {
		mode = mode || "0"; services = services || collectServicesWithSLA(data);
		var sloThresh = (slo != null && !isNaN(slo)) ? parseFloat(slo) : 99.9;
		var sloLow = (slo != null && !isNaN(slo)) ? Math.max(0, parseFloat(slo) - 5) : 0;
		function extraBadge(val, type) { var s, cl = "mnz-sla-extra-na"; if (!val || val === "-") return '<span class="mnz-sla-extra-badge mnz-sla-extra-na">-</span>'; s = String(val); if (type === "uptime") cl = "mnz-sla-extra-uptime"; else if (type === "downtime") cl = (s === "0s") ? "mnz-sla-extra-downtime-ok" : "mnz-sla-extra-downtime-bad"; else if (type === "errbudget") cl = (s.indexOf("-") === 0) ? "mnz-sla-extra-errbudget-bad" : "mnz-sla-extra-errbudget-ok"; return '<span class="mnz-sla-extra-badge ' + cl + '">' + escHtml(s) + '</span>'; }
		var html = '<div class="mnz-sla-tree-compact"><table class="mnz-sla-tree-table mnz-sla-tree-table-modal"><thead><tr><th>' + escHtml(d.service || "Service") + '</th><th>' + escHtml(d.sli || "SLI") + '</th><th>' + escHtml(d.uptime || "Uptime") + '</th><th>' + escHtml(d.downtime || "Downtime") + '</th><th>' + escHtml(d.errorBudget || "Error budget") + '</th></tr></thead><tbody>';
		function addRow(service, level) {
			var lvl = level || 0; var pad = lvl * 16; var padStyle = ' style="padding-left:' + (8 + pad) + 'px"'; var prefix = lvl > 0 ? '<span class="mnz-sla-tree-prefix">↳ </span>' : '';
			var sliCell = ''; if (service.has_sla && service.sli != null) { var v = parseFloat(service.sli).toFixed(1) + "%"; var sliVal = parseFloat(service.sli); var cl = sliVal >= sloThresh ? "mnz-sli-ok" : (sliVal >= sloLow ? "mnz-sli-warn" : "mnz-sli-bad"); sliCell = '<span class="mnz-sli-badge mnz-sli-badge-sm ' + cl + '">' + escHtml(v) + '</span>'; }
			var uptime = (service.uptime != null && service.uptime !== "") ? String(service.uptime) : null;
			var downtime = (service.downtime != null && service.downtime !== "") ? String(service.downtime) : null;
			var errBudget = (service.error_budget != null && service.error_budget !== "") ? String(service.error_budget) : null;
			var rawName = String(service.name || "");
			var titleRaw = rawName;
			if (service.inherited_from_name) titleRaw += " (" + (d.inheritedFrom || "inherited from") + " " + String(service.inherited_from_name) + ")";
			html += '<tr><td class="mnz-sla-tree-cell-name"' + padStyle + ' title="' + escHtml(titleRaw) + '">' + prefix + escHtml(rawName) + '</td><td class="mnz-sla-tree-cell-sli">' + sliCell + '</td><td class="mnz-sla-tree-cell-extra">' + extraBadge(uptime, "uptime") + '</td><td class="mnz-sla-tree-cell-extra">' + extraBadge(downtime, "downtime") + '</td><td class="mnz-sla-tree-cell-extra">' + extraBadge(errBudget, "errbudget") + '</td></tr>';
			if (service.children && service.children.length) for (var i = 0; i < service.children.length; i++) addRow(service.children[i], (level || 0) + 1);
		}
		var treeIndices = [];
		var idx = parseInt(mode, 10); if (!isNaN(idx) && idx >= 0 && idx < services.length) treeIndices = [services[idx].treeIndex];
		for (var ti = 0; ti < treeIndices.length; ti++) {
			var tree = data.service_trees[treeIndices[ti]];
			if (tree.path_to_root && tree.path_to_root.length > 0) { for (var p = 0; p < tree.path_to_root.length; p++) addRow(tree.path_to_root[p], p); }
			else addRow(tree.impacted_service, 0);
			if (tree.children_tree && tree.children_tree.length) { var bl = tree.path_to_root ? tree.path_to_root.length : 1; for (var c = 0; c < tree.children_tree.length; c++) addRow(tree.children_tree[c], bl); }
		}
		html += '</tbody></table></div>'; return html;
	}

	// Toggle section expand/collapse
	jQuery(".mnz-section-toggle").on("click", function(e) {
		e.preventDefault(); var lnk = jQuery(this); var id = lnk.data("section"); var section = document.getElementById(id); if (!section) return;
		var collapsed = section.classList.toggle("mnz-section-collapsed"); lnk.text(collapsed ? (d.expand || "Expand") : (d.collapse || "Collapse"));
	});

	// Timeline/Actions view switcher
	var pagingContainer = d.pagingBtnContainerClass || "paging-btn-container";
	var pagingSelected = d.pagingSelectedClass || "selected";
	jQuery("." + pagingContainer + " button[data-view]").on("click", function() {
		var btn = jQuery(this); var view = btn.data("view");
		btn.siblings().removeClass(pagingSelected); btn.addClass(pagingSelected);
		jQuery("#mnz-timeline-view").toggle(view === "timeline"); jQuery("#mnz-actions-view").toggle(view === "actions");
	});
});
