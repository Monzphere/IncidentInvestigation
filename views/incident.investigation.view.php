<?php declare(strict_types = 0);

require_once dirname(__FILE__).'/../../../include/defines.inc.php';
require_once dirname(__FILE__).'/../../../include/events.inc.php';
require_once dirname(__FILE__).'/../../../include/actions.inc.php';
require_once dirname(__FILE__).'/../../../include/users.inc.php';
require_once dirname(__FILE__).'/../../../include/html.inc.php';

$this->addJsFile('gtlc.js');

$has_event = $data['has_event'] ?? false;
$event = $data['event'] ?? [];
$trigger = $data['trigger'] ?? null;
$host = $data['host'] ?? null;
$has_host_dashboard = $data['has_host_dashboard'] ?? false;
$trigger_correlations = $data['trigger_correlations'] ?? ['precedes' => null, 'follows' => null];
$related_events = $data['related_events'] ?? [];
$six_months_events = $data['six_months_events'] ?? [];
$items = $data['items'] ?? [];
$monthly_comparison = $data['monthly_comparison'] ?? [];
$actions_data = $data['actions_data'] ?? ['result' => null, 'users' => [], 'mediatypes' => []];
$maintenances = $data['maintenances'] ?? [];

$event_time = isset($event['clock']) ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['clock']) : '';
$event_date = isset($event['clock']) ? zbx_date2str('Y-m-d', $event['clock']) : '';
$time_ago = isset($event['clock']) ? zbx_date2age($event['clock']) : '';
$mttr_seconds = 0;
$mttr_count = 0;
foreach ($data['six_months_events'] ?? [] as $ev) {
	if (!empty($ev['r_clock']) && (int)$ev['r_clock'] > 0) {
		$mttr_seconds += ((int)$ev['r_clock'] - (int)$ev['clock']);
		$mttr_count++;
	}
}
$mttr_formatted = $mttr_count > 0 ? zbx_date2age(0, (int)round($mttr_seconds / $mttr_count)) : null;
$severity = (int)($event['severity'] ?? 0);
$severity_name = CSeverityHelper::getName($severity);
$severity_color = CSeverityHelper::getColor($severity);

$html_page = (new CHtmlPage())
	->setTitle(_('Incident Investigation'))
	->setDocUrl('')
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(
					(new CButton('back_problems', _('Back to Problems')))
						->setAttribute('onclick', "location.href='zabbix.php?action=problem.view'")
				)
		))->setAttribute('aria-label', _('Content controls'))
	);

if (!$has_event) {
	$form = (new CForm('get'))
		->setName('mnz-incident-investigation-form')
		->setAttribute('aria-label', _('Event lookup'));
	$form->addItem((new CInput('hidden', 'action', 'incident.investigation.view'))->removeId());
	$form->addItem(
		(new CFormGrid())
			->addItem([
				new CLabel(_('Event ID'), 'eventid'),
				new CFormField(
					(new CTextBox('eventid', $data['eventid'] ?? ''))
						->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
						->setAttribute('placeholder', _('Enter event ID'))
						->setAttribute('autofocus', 'autofocus')
				)
			])
			->addItem([
				new CLabel(_('Trigger ID'), 'triggerid'),
				new CFormField(
					(new CTextBox('triggerid', $data['triggerid'] ?? ''))
						->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
						->setAttribute('placeholder', _('Optional'))
				)
			])
			->addItem([
				new CLabel(_('Host ID'), 'hostid'),
				new CFormField(
					(new CTextBox('hostid', $data['hostid'] ?? ''))
						->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
						->setAttribute('placeholder', _('Optional'))
				)
			])
	);
	$form->addItem(
		(new CDiv([
			(new CSubmit('investigate', _('Investigate')))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mnz-incident-investigation-form-actions')
	);
	$help = (new CDiv(_('Enter the event ID from Monitoring → Problems. Trigger ID and Host ID are optional and can be extracted from the event.')))
		->addClass('mnz-incident-investigation-help');
	$html_page->addItem(
		(new CDiv([$form, $help]))->addClass('mnz-incident-investigation-empty')
	);
	$html_page->show();
	return;
}

$content = new CDiv();
$content->addClass('mnz-incident-investigation-wrapper');

$meta_parts = [
	(new CSpan($severity_name))->addClass(CSeverityHelper::getStyle($severity))->addClass('mnz-incident-severity'),
	new CSpan(' • '),
	new CSpan($host ? ($host['name'] ?? $host['host'] ?? '') : 'N/A'),
	new CSpan(' • '),
	new CSpan($event_time),
	new CSpan(' • '),
	new CSpan($time_ago)
];
if ($mttr_formatted !== null) {
	$meta_parts[] = new CSpan(' • ');
	$meta_parts[] = (new CSpan(_s('MTTR: %1$s', $mttr_formatted)))->addClass('mnz-incident-mttr');
}
$event_header = (new CDiv([
	(new CTag('h2', true, $event['name'] ?? _('Unknown event')))->addClass('mnz-incident-investigation-title'),
	(new CDiv($meta_parts))->addClass('mnz-incident-investigation-meta')
]))->addClass('mnz-incident-investigation-header');
$content->addItem($event_header);

$sections = new CDiv();
$sections->addClass('mnz-incident-investigation-sections');

$host_groups = $host ? implode(', ', array_column($host['hostgroups'] ?? [], 'name')) : '';

$pattern_events = !empty($six_months_events) ? $six_months_events : $related_events;
$hourly_data = array_fill(0, 24, 0);
$weekly_data = array_fill(0, 7, 0);
$monthly_data = [];
$weekdays = [_('Sun'), _('Mon'), _('Tue'), _('Wed'), _('Thu'), _('Fri'), _('Sat')];
$user_lang = (CWebUser::$data && isset(CWebUser::$data['lang'])) ? CWebUser::$data['lang'] : null;
$force_24h = in_array($user_lang, ['pt_BR', 'pt_PT']);
$use_12h = !$force_24h && (strpos(TIME_FORMAT, 'A') !== false || strpos(TIME_FORMAT, 'a') !== false);
$hour_labels = [];
for ($h = 0; $h < 24; $h++) {
	$ts_utc = gmmktime($h, 0, 0, 1, 1, 2024);
	$hour_labels[] = $use_12h ? zbx_date2str('g a', $ts_utc, 'UTC') : (zbx_date2str('H', $ts_utc, 'UTC') . _x('h', 'hour short'));
}

$weekly_hourly_details = array_fill(0, 7, array_fill(0, 24, 0));
$monthly_daily_details = [];
$month_keys = [];

$current_time = time();
for ($i = 11; $i >= 0; $i--) {
	$ts = strtotime("-$i months", $current_time);
	$mk = date('Y-m', $ts);
	$month_keys[] = $mk;
	$days_in_month = (int)date('t', $ts);
	$monthly_daily_details[$mk] = array_fill(0, 31, 0);
}

foreach ($pattern_events as $e) {
	$h = (int)date('G', $e['clock']);
	$w = (int)date('w', $e['clock']);
	$mk = date('Y-m', $e['clock']);
	$day = (int)date('j', $e['clock']);

	$hourly_data[$h]++;
	$weekly_data[$w]++;
	$monthly_data[$mk] = ($monthly_data[$mk] ?? 0) + 1;

	$weekly_hourly_details[$w][$h]++;
	if (isset($monthly_daily_details[$mk])) {
		$monthly_daily_details[$mk][$day - 1]++;
	}
}

$monthly_labels = [];
$monthly_values = [];
$monthly_drilldown = [];
foreach ($month_keys as $mk) {
	$ts = strtotime($mk . '-01');
	$monthly_labels[] = zbx_date2str('M/y', $ts);
	$monthly_values[] = $monthly_data[$mk] ?? 0;
	$monthly_drilldown[] = array_values($monthly_daily_details[$mk] ?? array_fill(0, 31, 0));
}

$total_events = count($pattern_events);
$weekday_names_short = [_('Sun'), _('Mon'), _('Tue'), _('Wed'), _('Thu'), _('Fri'), _('Sat')];
$peak_day = 0;
$peak_hour = 0;
$peak_count = 0;
for ($w = 0; $w < 7; $w++) {
	for ($h = 0; $h < 24; $h++) {
		$c = $weekly_hourly_details[$w][$h] ?? 0;
		if ($c > $peak_count) {
			$peak_count = $c;
			$peak_day = $w;
			$peak_hour = $h;
		}
	}
}
$current_month_count = $monthly_comparison['current_month']['count'] ?? 0;
$month_change = $monthly_comparison['change_percentage'] ?? 0;
$critical_hours = [];
$running = 0;
$hourly_sorted = $hourly_data;
arsort($hourly_sorted);
$target = $total_events > 0 ? $total_events * 0.4 : 0;
foreach ($hourly_sorted as $h => $cnt) {
	if ($running >= $target) break;
	$running += $cnt;
	$ts_utc = gmmktime($h, 0, 0, 1, 1, 2024);
	$critical_hours[] = $use_12h ? zbx_date2str('g a', $ts_utc, 'UTC') : zbx_date2str('H:i', $ts_utc, 'UTC');
}
sort($critical_hours);
$critical_slot_label = count($critical_hours) === 0 ? '-'
	: (count($critical_hours) <= 3 ? implode(', ', $critical_hours) : (($critical_hours[0] ?? '') . ' - ' . end($critical_hours)));
$slot_count = 7 * 24;
$avg_per_slot = $total_events > 0 ? $total_events / $slot_count : 0;
$avg_per_hour = $total_events > 0 ? $total_events / 24 : 0;
$avg_per_weekday = $total_events > 0 ? $total_events / 7 : 0;
$avg_per_month = $total_events > 0 && count($month_keys) > 0 ? $total_events / count($month_keys) : 0;

$recommendations = [];
if ($peak_count > 0 && $peak_day >= 1 && $peak_day <= 5) {
	$recommendations[] = _('Incidents on weekdays — possible correlation with business load.');
}
if ($peak_count > 0 && ($peak_day === 0 || $peak_day === 6)) {
	$recommendations[] = _('Weekend pattern — check automated or batch processes.');
}
if ($total_events > 0 && count($critical_hours) > 0) {
	$recommendations[] = _('Schedule maintenance outside critical hours.');
}
if ($month_change > 10) {
	$recommendations[] = _('Increasing trend — proactive review recommended.');
}
if (empty($recommendations)) {
	$recommendations[] = _('Use the heatmap to find optimal maintenance windows.');
}

$peak_val = $peak_count > 0 ? ($weekday_names_short[$peak_day] . ' ' . ($use_12h ? zbx_date2str('g a', gmmktime($peak_hour, 0, 0, 1, 1, 2024), 'UTC') : zbx_date2str('H:i', gmmktime($peak_hour, 0, 0, 1, 1, 2024), 'UTC'))) : '-';
$critical_val = $total_events > 0 ? $critical_slot_label : '-';
$key_insight_action = !empty($recommendations) ? $recommendations[0] : null;

$problem_history_url_params = function ($triggerid) {
	$url = (new CUrl('zabbix.php'))
		->setArgument('action', 'problem.view')
		->setArgument('show', TRIGGERS_OPTION_ALL)
		->setArgument('triggerids', [$triggerid])
		->setArgument('filter_set', '1')
		->setArgument('filter_custom_time', '1')
		->setArgument('from', 'now-1y')
		->setArgument('to', 'now');
	return $url->getUrl();
};

$insight_form = new CFormList('mnz-investigation-insight');
if ($host_groups !== '') {
	$insight_form->addRow(
		(new CLabel([_('Groups'), makeHelpIcon(_('Host groups this trigger belongs to.'))], null)),
		(new CDiv($host_groups))->addClass(ZBX_STYLE_WORDBREAK)
	);
}
$insight_form->addRow(
	(new CLabel([_('Peak'), makeHelpIcon(_('Day and hour with the most incidents in the last 12 months.'))], null)),
	$peak_val
);
$insight_form->addRow(
	(new CLabel([_('12 months'), makeHelpIcon(_('Total number of problem occurrences in the last 12 months.'))], null)),
	(new CDiv((string)$total_events))->setAttribute('title', _s('%1$s occurrences in the last 12 months', (string)$total_events))
);
if ($host && !empty($host['hostid']) && $has_host_dashboard) {
	$host_dashboard_url = (new CUrl('zabbix.php'))->setArgument('action', 'host.dashboard.view')->setArgument('hostid', $host['hostid']);
	$insight_form->addRow(
		(new CLabel([_('Dashboard'), makeHelpIcon(_('Open the host dashboard with widgets and graphs.'))], null)),
		(new CLink(_('Open host dashboard'), $host_dashboard_url->getUrl()))->addClass(ZBX_STYLE_LINK_ALT)
	);
}
if (!empty($trigger_correlations['precedes'])) {
	$prec = $trigger_correlations['precedes'];
	$prec_name_short = strlen($prec['name']) > 40 ? substr($prec['name'], 0, 37) . '...' : $prec['name'];
	$prec_link = (new CLink($prec_name_short . ' (' . $prec['pct'] . '%)', $problem_history_url_params($prec['triggerid'])))
		->addClass(ZBX_STYLE_LINK_ALT)
		->setAttribute('title', _s('Trigger "%1$s" usually fires 0-30 min before this one', $prec['name']));
	$insight_form->addRow(
		(new CLabel([_('Precedes'), makeHelpIcon(_('Trigger that often fires 0-30 minutes before this one. Possible root cause. Opens Problems in History view.'))], null)),
		$prec_link
	);
}
if (!empty($trigger_correlations['follows'])) {
	$fol = $trigger_correlations['follows'];
	$fol_name_short = strlen($fol['name']) > 40 ? substr($fol['name'], 0, 37) . '...' : $fol['name'];
	$fol_link = (new CLink($fol_name_short . ' (' . $fol['pct'] . '%)', $problem_history_url_params($fol['triggerid'])))
		->addClass(ZBX_STYLE_LINK_ALT)
		->setAttribute('title', _s('Trigger "%1$s" usually fires 0-30 min after this one', $fol['name']));
	$insight_form->addRow(
		(new CLabel([_('Follows'), makeHelpIcon(_('Trigger that often fires 0-30 minutes after this one. Possible cascading effect. Opens Problems in History view.'))], null)),
		$fol_link
	);
}
$this_month_items = [(string)$current_month_count];
if ($month_change != 0) {
	$this_month_items[] = NBSP();
	$this_month_items[] = (new CSpan(($month_change > 0 ? '↑' : '↓') . abs($month_change) . '%'))
		->addClass($month_change > 0 ? ZBX_STYLE_RED : ZBX_STYLE_GREEN);
}
$insight_form->addRow(
	(new CLabel([_('This month'), makeHelpIcon(_('Number of incidents in the current month and variation vs last month.'))], null)),
	$this_month_items
);
$insight_form->addRow(
	(new CLabel([_('Critical 40%'), makeHelpIcon(_('Hourly window where 40% of incidents occur. Useful for preventive actions.'))], null)),
	$critical_val
);
if ($trigger && !empty($trigger['expression'])) {
	$expr = $trigger['expression'];
	$expr_short = strlen($expr) > 60 ? substr($expr, 0, 57) . '...' : $expr;
	$insight_form->addRow(
	(new CLabel([_('Trigger'), makeHelpIcon(_('Expression that defines when this trigger fires.'))], null)),
	(new CDiv($expr_short))->addClass(ZBX_STYLE_WORDBREAK)->setAttribute('title', $expr)
);
}
if ($key_insight_action) {
	$insight_form->addRow(
	(new CLabel([_('Action'), makeHelpIcon(_('Recommended action based on the incident analysis.'))], null)),
	(new CDiv($key_insight_action))->addClass(ZBX_STYLE_WORDBREAK)
);
}

$key_insight_section = (new CDiv([
	(new CDiv(_('Key insights')))->addClass('mnz-incident-section-title'),
	$insight_form
]))->addClass('mnz-incident-section mnz-incident-section-key-insight');

$monthly_chart = (new CDiv())->setId('mnz-investigation-monthly-chart')->addClass('mnz-incident-chart mnz-incident-chart-monthly');
$monthly_drilldown = (new CDiv())
	->setId('mnz-monthly-drilldown')
	->addClass('mnz-investigation-drilldown')
	->addItem((new CDiv(_('Click a month to see which days had the most incidents')))->addClass('mnz-drilldown-hint')->addClass('mnz-drilldown-hint-monthly'));
$monthly_container = (new CDiv([
	(new CTag('h4', true, _('Last 12 months')))->addClass('mnz-incident-chart-title'),
	$monthly_chart,
	$monthly_drilldown
]))->addClass('mnz-incident-chart-container');

$filter_bar = (new CDiv())->setId('mnz-investigation-filter-bar')->addClass('mnz-investigation-filter-bar');
$heatmap_container = (new CDiv())
	->setId('mnz-investigation-heatmap')
	->addClass('mnz-heatmap-container');
$heatmap_legend = (new CDiv([
	(new CTag('span', true, _('Low')))->addClass('mnz-heatmap-legend-label'),
	(new CDiv())->addClass('mnz-heatmap-legend-bar'),
	(new CTag('span', true, _('High')))->addClass('mnz-heatmap-legend-label')
]))->addClass('mnz-heatmap-legend');
$sla_service_container = (new CDiv())->setId('mnz-sla-service-container')->addClass('mnz-sla-service-container');
$heatmap_section = (new CDiv([
	(new CTag('h3', true, _('Incident heatmap (day × hour)')))->addClass('mnz-incident-section-title'),
	(new CDiv(_('Click a cell to filter all charts')))->addClass('mnz-drilldown-hint'),
	$heatmap_container,
	$heatmap_legend
]))->addClass('mnz-incident-section mnz-incident-section-heatmap');

$sla_mode_selector = (new CDiv())->setId('mnz-sla-mode-selector')->addClass('mnz-sla-mode-selector');
$sla_panel = (new CDiv([
	$sla_mode_selector,
	(new CDiv())->setId('mnz-sla-gauge-wrap')->addClass('mnz-sla-gauge-wrap'),
	$sla_service_container
]))->addClass('mnz-sla-panel');

$sla_tree_modal = (new CDiv())
	->setId('mnz-sla-tree-modal')
	->addClass('mnz-sla-tree-modal-overlay')
	->setAttribute('aria-hidden', 'true');

$sla_section = (new CDiv([
	(new CTag('h3', true, _('SLA by Service')))->addClass('mnz-incident-section-title'),
	(new CDiv(_('Current SLI and services affected')))->addClass('mnz-drilldown-hint'),
	$sla_panel,
	$sla_tree_modal
]))->addClass('mnz-incident-section mnz-incident-section-sla');

$heatmap_sla_row = (new CDiv([
	$heatmap_section,
	$sla_section
]))->addClass('mnz-heatmap-sla-row');

$time_patterns_section = (new CDiv([
	(new CTag('h3', true, _('Time patterns')))->addClass('mnz-incident-section-title'),
	$filter_bar,
	$heatmap_sla_row
]))->addClass('mnz-incident-section mnz-incident-section-patterns');

$sections->addItem($time_patterns_section);
$sections->addItem((new CDiv($monthly_container))->addClass('mnz-incident-section mnz-incident-section-monthly'));

if ($items && isset($event['clock'])) {
	$event_ts = $event['clock'];
	$from_ts = $event_ts - 21600;
	$from_time = date('Y-m-d H:i:s', $from_ts);
	$to_time = 'now';
	$itemids = array_column($items, 'itemid');
	$base_params = [
		'from' => $from_time,
		'to' => $to_time,
		'type' => 0,
		'resolve_macros' => 1,
		'width' => 800,
		'height' => 250,
		'_' => time()
	];
	$chart_url = 'chart.php?' . http_build_query($base_params);
	foreach ($itemids as $itemid) {
		$chart_url .= '&itemids[]=' . urlencode($itemid);
	}
	$graphs_section = (new CDiv([
		(new CTag('h3', true, _('Graphs (6h before incident)')))->addClass('mnz-incident-section-title'),
		(new CDiv(
			(new CTag('img', true))
				->setAttribute('src', $chart_url)
				->setAttribute('alt', _('Problem graph'))
				->addClass('mnz-incident-chart-image')
		))->addClass('mnz-incident-graph-wrapper')
	]))->addClass('mnz-incident-section mnz-incident-section-graphs');
	$insight_graph_row = (new CDiv([
		$key_insight_section,
		$graphs_section
	]))->addClass('mnz-insight-graph-row');
	$sections->addItem($insight_graph_row);
} else {
	$sections->addItem($key_insight_section);
}

if (($related_events && $trigger) || ($actions_data['result'] && !empty($actions_data['result']['actions']))) {
	$allowed = [
		'add_comments' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS),
		'change_severity' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY),
		'acknowledge' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS),
		'suppress_problems' => CWebUser::checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS),
		'close' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS) && ($trigger && ($trigger['manual_close'] ?? 0) == ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED),
		'rank_change' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
	];
	$timeline_table = ($related_events && $trigger)
		? make_small_eventlist($event, $allowed)
		: (new CTableInfo())->addRow([(new CCol(_('No event list available')))->setColSpan(7)->addClass('mnz-incident-empty-hint')]);
	$actions_table = ($actions_data['result'] && !empty($actions_data['result']['actions']))
		? makeEventDetailsActionsTable($actions_data['result'], $actions_data['users'], $actions_data['mediatypes'])
		: (new CTableInfo())->setHeader([_('Step'), _('Time'), _('User/Recipient'), _('Action'), _('Message/Command'), _('Status'), _('Info')])
			->addRow([(new CCol(_('No actions for this event')))->setColSpan(7)->addClass('mnz-incident-empty-hint')]);
	$default_view = ($related_events && $trigger) ? 'timeline' : 'actions';
	$btn_timeline = (new CButton('mnz-view-timeline', _('Timeline')))
		->addClass('radio-switch')
		->setAttribute('data-view', 'timeline');
	$btn_actions = (new CButton('mnz-view-actions', _('Actions')))
		->addClass('radio-switch')
		->setAttribute('data-view', 'actions');
	if ($default_view === 'timeline') {
		$btn_timeline->addClass(ZBX_STYLE_PAGING_SELECTED);
	} else {
		$btn_actions->addClass(ZBX_STYLE_PAGING_SELECTED);
	}
	$toggle_container = (new CDiv([$btn_timeline, $btn_actions]))
		->addClass(ZBX_STYLE_PAGING_BTN_CONTAINER);
	$header_row = (new CDiv([
		(new CTag('h3', true, _('Timeline')))->addClass('mnz-incident-section-title'),
		$toggle_container
	]))->addClass('mnz-incident-section-header-row');
	$timeline_wrap = (new CDiv($timeline_table))
		->setId('mnz-timeline-view')
		->addClass('mnz-timeline-actions-content')
		->addStyle($default_view === 'timeline' ? '' : 'display: none;');
	$actions_wrap = (new CDiv($actions_table))
		->setId('mnz-actions-view')
		->addClass('mnz-timeline-actions-content')
		->addStyle($default_view === 'actions' ? '' : 'display: none;');
	$timeline_section = (new CDiv([
		$header_row,
		$timeline_wrap,
		$actions_wrap
	]))->addClass('mnz-incident-section mnz-incident-section-timeline');
	$sections->addItem($timeline_section);
}

$content->addItem($sections);

$event_clocks = array_column($pattern_events, 'clock');
$hostid_for_sla = ($host && isset($host['hostid'])) ? $host['hostid'] : null;
$chart_data = [
	'hostid' => $hostid_for_sla,
	'maintenances' => array_map(function ($m) {
		return ['name' => $m['name'], 'active_since' => (int)$m['active_since'], 'active_till' => (int)$m['active_till']];
	}, $maintenances),
	'hourly' => array_values($hourly_data),
	'weekly' => array_values($weekly_data),
	'monthly' => $monthly_values,
	'hourLabels' => $hour_labels,
	'weekLabels' => $weekdays,
	'monthLabels' => $monthly_labels,
	'weeklyHourlyDetails' => array_map(function($arr) { return array_values($arr); }, $weekly_hourly_details),
	'monthlyDailyDetails' => $monthly_drilldown,
	'monthKeys' => $month_keys,
	'dayLabels' => range(1, 31),
	'eventClocks' => $event_clocks,
	'avgPerSlot' => $avg_per_slot,
	'avgPerHour' => $avg_per_hour,
	'avgPerWeekday' => $avg_per_weekday,
	'avgPerMonth' => $avg_per_month,
	'hintWeek' => _('Click a day to see which hours had the most incidents'),
	'hintMonth' => _('Click a month to see which days had the most incidents'),
	'hintDay' => _('Click a day to see hourly distribution in heatmap'),
	'hintHour' => _('Click an hour to filter all charts'),
	'filteredBy' => _('Filtered by'),
	'clearFilter' => _('Clear filter'),
	'aboveAvg' => _('above avg'),
	'times' => _('×'),
	'sameSlotLastWeek' => _('Same slot last week'),
	'sameDayLastWeek' => _('Same day last week'),
	'incidents' => _('incidents'),
	'close' => _('Close'),
	'dailyDistribution' => _('Daily distribution'),
	'loadingServices' => _('Loading services...'),
	'noServicesAffected' => _('No services affected by this incident'),
	'minimum' => _('Minimum'),
	'average' => _('Average'),
	'view' => _('View'),
	'service' => _('Service'),
	'currentSLI' => _('Current SLI'),
	'viewServiceTree' => _('View service tree with SLI'),
	'failedToLoadServiceImpact' => _('Failed to load service impact'),
	'serviceTreeAndSLI' => _('Service tree and SLI'),
	'inheritedFrom' => _('inherited from'),
	'uptime' => _('Uptime'),
	'downtime' => _('Downtime'),
	'errorBudget' => _('Error budget'),
	'expand' => _('Expand'),
	'collapse' => _('Collapse'),
	'sli' => _('SLI'),
	'iconClass' => ZBX_STYLE_ICON.' '.ZBX_ICON_MORE,
	'btnOverlayCloseClass' => ZBX_STYLE_BTN_OVERLAY_CLOSE,
	'pagingSelectedClass' => ZBX_STYLE_PAGING_SELECTED,
	'pagingBtnContainerClass' => ZBX_STYLE_PAGING_BTN_CONTAINER,
	'legendMaintenanceBorder' => _('Icon = incident during maintenance'),
	'legendMaintenanceBar' => _('Orange bar = maintenance period'),
	'maintIconClass' => 'zi zi-wrench-alt-small',
	'maintenancesCount' => _('maintenances'),
	'tooltipMaintenances' => _('Maintenances'),
	'tooltipIncidents' => _('incidents')
];

ob_start();
include dirname(__FILE__).'/js/incident.investigation.js.php';
$content->addItem(new CScriptTag(ob_get_clean()));

$content->addItem(
	(new CDiv('Developed by MonZphere'))
		->addClass('mnz-module-footer')
);

$html_page->addItem($content);
$html_page->show();
