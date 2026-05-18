<?php declare(strict_types = 0);

namespace Modules\IncidentInvestigation\Actions;

use API;
use CArrayHelper;
use CController;
use CControllerResponseData;
use CProfile;
use CRoleHelper;
use CWebUser;

class CControllerIncidentInvestigationView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventid' => 'id',
			'triggerid' => 'id',
			'hostid' => 'id'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		$eventid = $this->getInput('eventid', CProfile::get('mnz.incident.investigation.eventid', ''));
		$triggerid = (int) $this->getInput('triggerid', CProfile::get('mnz.incident.investigation.triggerid', 0));
		$hostid = (int) $this->getInput('hostid', CProfile::get('mnz.incident.investigation.hostid', 0));

		if ($this->hasInput('eventid')) {
			CProfile::update('mnz.incident.investigation.eventid', $eventid, PROFILE_TYPE_STR);
			CProfile::update('mnz.incident.investigation.triggerid', (string) $triggerid, PROFILE_TYPE_STR);
			CProfile::update('mnz.incident.investigation.hostid', (string) $hostid, PROFILE_TYPE_STR);
		}

		$data = [
			'eventid' => $eventid,
			'triggerid' => $triggerid,
			'hostid' => $hostid,
			'has_event' => false,
			'event' => [],
			'trigger' => null,
			'host' => null,
			'related_events' => [],
			'six_months_events' => [],
			'items' => [],
			'monthly_comparison' => [],
			'user' => ['debug_mode' => CWebUser::$data['debug_mode'] ?? 0]
		];

		if (empty($eventid)) {
			$response = new CControllerResponseData($data);
			$response->setTitle(_('Incident Investigation'));
			$this->setResponse($response);
			return;
		}

		$events = API::Event()->get([
			'output' => ['eventid', 'source', 'object', 'objectid', 'clock', 'ns', 'value', 'acknowledged', 'name', 'severity', 'r_eventid'],
			'eventids' => $eventid,
			'selectTags' => ['tag', 'value'],
			'selectAcknowledges' => ['clock', 'message', 'action', 'userid', 'old_severity', 'new_severity', 'suppress_until']
		]);
		$event = $events ? $events[0] : [];

		if (!$event) {
			$event = [
				'eventid' => $eventid,
				'name' => _('Event not found'),
				'severity' => 0,
				'clock' => time(),
				'acknowledged' => 0,
				'objectid' => 0,
				'value' => 1,
				'tags' => [],
				'r_eventid' => 0,
				'acknowledges' => []
			];
		}
		elseif (!isset($event['acknowledges'])) {
			$event['acknowledges'] = [];
		}
		if (!isset($event['r_eventid'])) {
			$event['r_eventid'] = 0;
		}

		$actual_triggerid = $triggerid ?: ($event['objectid'] ?? 0);
		$trigger = null;

		if ($actual_triggerid > 0) {
			$triggers = API::Trigger()->get([
				'output' => ['triggerid', 'description', 'expression', 'comments', 'priority', 'manual_close'],
				'triggerids' => [$actual_triggerid],
				'selectHosts' => ['hostid', 'host', 'name'],
				'selectItems' => ['itemid', 'hostid', 'name', 'key_'],
				'expandExpression' => true
			]);
			$trigger = $triggers ? $triggers[0] : null;
		}

		$actual_hostid = $hostid ?: ($trigger['hosts'][0]['hostid'] ?? 0);
		$host = null;

		if ($actual_hostid > 0) {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name', 'status', 'description'],
				'hostids' => $actual_hostid,
				'selectHostGroups' => ['groupid', 'name'],
				'selectInterfaces' => ['type', 'main', 'ip', 'port'],
				'selectTags' => ['tag', 'value']
			]);
			$host = $hosts ? $hosts[0] : null;
		}

		$has_host_dashboard = false;
		if ($actual_hostid > 0) {
			$hosts_with_dashboards = API::Host()->get([
				'output' => ['hostid'],
				'hostids' => [$actual_hostid],
				'selectDashboards' => ['hostid', 'dashboardid', 'name'],
				'limit' => 1
			]);
			$has_host_dashboard = !empty($hosts_with_dashboards[0]['dashboards'] ?? []);
		}

		$related_events = [];
		if ($actual_triggerid > 0) {
			$related_events = API::Event()->get([
				'output' => ['eventid', 'clock', 'value', 'acknowledged', 'name', 'severity'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => [$actual_triggerid],
				'sortfield' => 'clock',
				'sortorder' => 'DESC',
				'limit' => 20
			]);
			$trigger_severity = $trigger['priority'] ?? 0;
			$last_sev = 0;
			$related_events = array_reverse($related_events);
			foreach ($related_events as &$ev) {
				if ($ev['value'] == 1) {
					$last_sev = (int) $ev['severity'];
				} else {
					$ev['severity'] = $last_sev ?: $trigger_severity;
				}
			}
			unset($ev);
			$related_events = array_reverse($related_events);
		}

		$six_months_events = [];
		if ($actual_triggerid > 0) {
			$all_events = API::Event()->get([
				'output' => ['eventid', 'clock', 'value', 'severity', 'name'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => [$actual_triggerid],
				'time_from' => strtotime('-12 months'),
				'sortfield' => ['clock', 'eventid'],
				'sortorder' => 'ASC'
			]);
			$pending = [];
			foreach ($all_events as $evt) {
				if ($evt['value'] == 1) {
					$pending[] = ['eventid' => $evt['eventid'], 'clock' => $evt['clock'], 'r_clock' => 0,
						'severity' => $evt['severity'], 'name' => $evt['name'], 'value' => 1];
				} else {
					if (!empty($pending)) {
						$p = array_shift($pending);
						$p['r_clock'] = $evt['clock'];
						$six_months_events[] = $p;
					}
				}
			}
			foreach ($pending as $p) {
				$six_months_events[] = $p;
			}
			usort($six_months_events, function($a, $b) { return $b['clock'] - $a['clock']; });
		}

		$items = [];
		if ($trigger && !empty($trigger['items'])) {
			$itemids = array_unique(array_column($trigger['items'], 'itemid'));
			$raw = API::Item()->get([
				'output' => ['itemid', 'name', 'key_', 'hostid', 'value_type'],
				'itemids' => $itemids,
				'monitored' => true,
				'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]]
			]);
			$items = array_values($raw);
		}

		$monthly_comparison = [];
		if ($actual_triggerid > 0 && isset($event['clock'])) {
			$ts = $event['clock'];
			$cur_start = mktime(0, 0, 0, (int)date('n', $ts), 1, (int)date('Y', $ts));
			$cur_end = mktime(23, 59, 59, (int)date('n', $ts), (int)date('t', $ts), (int)date('Y', $ts));
			$prev_start = mktime(0, 0, 0, (int)date('n', $ts) - 1, 1, (int)date('Y', $ts));
			$prev_end = mktime(23, 59, 59, (int)date('n', $ts) - 1, (int)date('t', $prev_start), (int)date('Y', $ts));
			if (date('n', $ts) == 1) {
				$prev_start = mktime(0, 0, 0, 12, 1, (int)date('Y', $ts) - 1);
				$prev_end = mktime(23, 59, 59, 12, 31, (int)date('Y', $ts) - 1);
			}
			$cur_ev = API::Event()->get([
				'output' => ['eventid'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => [$actual_triggerid],
				'time_from' => $cur_start,
				'time_till' => $cur_end,
				'value' => 1
			]);
			$prev_ev = API::Event()->get([
				'output' => ['eventid'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => [$actual_triggerid],
				'time_from' => $prev_start,
				'time_till' => $prev_end,
				'value' => 1
			]);
			$cur_cnt = count($cur_ev);
			$prev_cnt = count($prev_ev);
			$monthly_comparison = [
				'current_month' => ['name' => $this->formatMonth($ts), 'count' => $cur_cnt],
				'previous_month' => ['name' => $this->formatMonth($prev_start), 'count' => $prev_cnt],
				'change_percentage' => $prev_cnt > 0 ? round((($cur_cnt - $prev_cnt) / $prev_cnt) * 100, 1) : ($cur_cnt > 0 ? 100 : 0)
			];
		}

		$actions_data = ['result' => null, 'users' => [], 'mediatypes' => []];
		if ($event && !empty($event['eventid']) && isset($event['clock'])) {
			require_once dirname(__FILE__).'/../../../include/actions.inc.php';
			$actions_raw = getEventDetailsActions($event);
			$actions_data['result'] = $actions_raw;
			if (!empty($actions_raw['userids'])) {
				$actions_data['users'] = API::User()->get([
					'output' => ['username', 'name', 'surname'],
					'userids' => array_keys($actions_raw['userids']),
					'preservekeys' => true
				]);
			}
			if (!empty($actions_raw['mediatypeids'])) {
				$actions_data['mediatypes'] = API::Mediatype()->get([
					'output' => ['maxattempts'],
					'mediatypeids' => array_keys($actions_raw['mediatypeids']),
					'preservekeys' => true
				]);
			}
		}

		$data['actions_data'] = $actions_data;
		$data['has_event'] = true;
		$data['event'] = $event;
		$data['trigger'] = $trigger;
		$data['host'] = $host;
		$data['has_host_dashboard'] = $has_host_dashboard;
		$data['related_events'] = $related_events;
		$data['six_months_events'] = $six_months_events;
		$data['items'] = $items;
		$data['monthly_comparison'] = $monthly_comparison;
		$data['trigger_correlations'] = $this->computeTriggerCorrelations(
			$actual_triggerid,
			$actual_hostid,
			$six_months_events
		);

		$data['maintenances'] = $this->getMaintenancesInPeriod(
			$actual_hostid,
			$host,
			strtotime('-12 months'),
			time()
		);

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Incident Investigation'));
		$this->setResponse($response);
	}

	/**
	 * Find triggers that tend to fire before (precursor) or after (follower) this trigger on the same host.
	 * Uses ±30min window. Returns top correlation per type with ≥25% and ≥2 occurrences.
	 */
	private function computeTriggerCorrelations(int $triggerid, int $hostid, array $six_months_events): array {
		$result = ['precedes' => null, 'follows' => null];
		if ($triggerid <= 0 || $hostid <= 0 || empty($six_months_events)) {
			return $result;
		}
		$window = 1800; // 30 min
		$our_clocks = array_filter(array_column($six_months_events, 'clock'));
		if (empty($our_clocks)) {
			return $result;
		}
		$total_ours = count($our_clocks);
		$time_from = strtotime('-12 months');
		$time_to = time();
		$host_triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description'],
			'hostids' => $hostid
		]);
		$other_triggerids = array_values(array_filter(array_column($host_triggers, 'triggerid'), function ($tid) use ($triggerid) {
			return (int) $tid !== $triggerid;
		}));
		if (empty($other_triggerids)) {
			return $result;
		}
		$all_events = API::Event()->get([
			'output' => ['objectid', 'clock'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => $other_triggerids,
			'value' => 1,
			'time_from' => max($time_from, min($our_clocks) - $window),
			'time_till' => min($time_to, max($our_clocks) + $window),
			'sortfield' => 'clock',
			'sortorder' => 'ASC'
		]);
		$by_trigger = [];
		foreach ($all_events as $ev) {
			$tid = $ev['objectid'];
			if (!isset($by_trigger[$tid])) {
				$by_trigger[$tid] = [];
			}
			$by_trigger[$tid][] = (int) $ev['clock'];
		}
		$triggers_by_id = array_column($host_triggers, 'description', 'triggerid');
		$precursor_scores = [];
		$follower_scores = [];
		foreach ($our_clocks as $our_clock) {
			$our_clock = (int) $our_clock;
			$prec_start = $our_clock - $window;
			$prec_end = $our_clock;
			$fol_start = $our_clock;
			$fol_end = $our_clock + $window;
			foreach ($by_trigger as $tid => $clocks) {
				foreach ($clocks as $c) {
					if ($c >= $prec_start && $c <= $prec_end) {
						$precursor_scores[$tid] = ($precursor_scores[$tid] ?? 0) + 1;
						break;
					}
				}
				foreach ($clocks as $c) {
					if ($c >= $fol_start && $c <= $fol_end) {
						$follower_scores[$tid] = ($follower_scores[$tid] ?? 0) + 1;
						break;
					}
				}
			}
		}
		$min_pct = 25;
		$min_count = 2;
		foreach ($precursor_scores as $tid => $cnt) {
			if ($cnt >= $min_count) {
				$pct = round(100 * $cnt / $total_ours, 0);
				if ($pct >= $min_pct && ($result['precedes'] === null || $pct > $result['precedes']['pct'])) {
					$result['precedes'] = [
						'triggerid' => $tid,
						'name' => $triggers_by_id[$tid] ?? _('Unknown'),
						'pct' => $pct,
						'count' => $cnt
					];
				}
			}
		}
		foreach ($follower_scores as $tid => $cnt) {
			if ($cnt >= $min_count) {
				$pct = round(100 * $cnt / $total_ours, 0);
				if ($pct >= $min_pct && ($result['follows'] === null || $pct > $result['follows']['pct'])) {
					$result['follows'] = [
						'triggerid' => $tid,
						'name' => $triggers_by_id[$tid] ?? _('Unknown'),
						'pct' => $pct,
						'count' => $cnt
					];
				}
			}
		}
		return $result;
	}

	/**
	 * Get maintenance windows that overlap the period and apply to the host or its groups.
	 * Expands timeperiods so the full schedule is considered, not only active_since/active_till.
	 */
	private function getMaintenancesInPeriod(int $hostid, ?array $host, int $time_from, int $time_to): array {
		if ($hostid <= 0 || $host === null || !CWebUser::checkAccess(\CRoleHelper::UI_CONFIGURATION_MAINTENANCE)) {
			return [];
		}
		$groupids = array_column($host['hostgroups'] ?? [], 'groupid');
		$base = [
			'output' => ['maintenanceid', 'name', 'active_since', 'active_till'],
			'selectTimeperiods' => ['timeperiod_type', 'period', 'start_date', 'start_time', 'every', 'day', 'dayofweek', 'month'],
			'preservekeys' => true
		];
		$all = [];
		try {
			$by_host = API::Maintenance()->get(array_merge($base, ['hostids' => [$hostid]]));
			$all = $by_host;
			if (!empty($groupids)) {
				$by_group = API::Maintenance()->get(array_merge($base, ['groupids' => $groupids]));
				$all = $all + $by_group;
			}
		} catch (\Throwable $e) {
			return [];
		}
		$result = [];
		foreach ($all as $m) {
			$since = (int) ($m['active_since'] ?? 0);
			$till = (int) ($m['active_till'] ?? 0);
			if ($till < $time_from || $since > $time_to) {
				continue;
			}
			$name = $m['name'] ?? _('Maintenance');
			$segments = $this->expandMaintenanceTimeperiods($m, $since, $till, max($since, $time_from), min($till, $time_to));
			foreach ($segments as [$seg_since, $seg_till]) {
				if ($seg_till >= $time_from && $seg_since <= $time_to) {
					$result[] = [
						'name' => $name,
						'active_since' => (int) $seg_since,
						'active_till' => (int) $seg_till
					];
				}
			}
		}
		usort($result, function ($a, $b) {
			return $b['active_since'] - $a['active_since'];
		});
		return $result;
	}

	/**
	 * Expand maintenance timeperiods into concrete [since, till] segments.
	 *
	 * @param array $m              Maintenance with timeperiods
	 * @param int   $m_since         Maintenance active_since (for alignment)
	 * @param int   $m_till          Maintenance active_till
	 * @param int   $clip_since      Clamp segment start (e.g. time_from)
	 * @param int   $clip_till       Clamp segment end (e.g. time_to)
	 */
	private function expandMaintenanceTimeperiods(array $m, int $m_since, int $m_till, int $clip_since, int $clip_till): array {
		$timeperiods = $m['timeperiods'] ?? [];
		if (empty($timeperiods)) {
			return [[$clip_since, $clip_till]];
		}
		$segments = [];
		foreach ($timeperiods as $tp) {
			$type = (int) ($tp['timeperiod_type'] ?? 0);
			$period = max(60, (int) ($tp['period'] ?? 3600));
			switch ($type) {
				case TIMEPERIOD_TYPE_ONETIME:
					$start = (int) ($tp['start_date'] ?? $m_since);
					$end = $start + $period;
					if ($end >= $clip_since && $start <= $clip_till) {
						$segments[] = [max($start, $clip_since), min($end, $clip_till)];
					}
					break;
				case TIMEPERIOD_TYPE_DAILY:
					$start_time = (int) ($tp['start_time'] ?? 0);
					$every = max(1, (int) ($tp['every'] ?? 1));
					$day0 = (int) floor($m_since / 86400) * 86400;
					for ($t = $day0; $t <= $clip_till; $t += 86400 * $every) {
						$occ_start = $t + $start_time;
						$occ_end = $occ_start + $period;
						if ($occ_end >= $clip_since && $occ_start <= $clip_till) {
							$segments[] = [max($occ_start, $clip_since), min($occ_end, $clip_till)];
						}
					}
					break;
				case TIMEPERIOD_TYPE_WEEKLY:
					$start_time = (int) ($tp['start_time'] ?? 0);
					$dayofweek = (int) ($tp['dayofweek'] ?? 1);
					$max_days = (int) ceil(($clip_till - $clip_since) / 86400) + 7;
					for ($d = 0; $d < $max_days; $d++) {
						$t = $clip_since + $d * 86400;
						$w = (int) date('w', $t);
						if (!($dayofweek & (1 << $w))) {
							continue;
						}
						$day_start = (int) floor($t / 86400) * 86400;
						$occ_start = $day_start + $start_time;
						$occ_end = $occ_start + $period;
						if ($occ_end >= $clip_since && $occ_start <= $clip_till) {
							$segments[] = [max($occ_start, $clip_since), min($occ_end, $clip_till)];
						}
					}
					break;
				case TIMEPERIOD_TYPE_MONTHLY:
					$start_time = (int) ($tp['start_time'] ?? 0);
					$day = (int) ($tp['day'] ?? 1);
					$month_mask = (int) ($tp['month'] ?? 4095);
					$y = (int) date('Y', $clip_since);
					$mo = (int) date('n', $clip_since);
					$end_y = (int) date('Y', $clip_till);
					$end_mo = (int) date('n', $clip_till);
					while ($y < $end_y || ($y === $end_y && $mo <= $end_mo)) {
						if ($month_mask & (1 << ($mo - 1))) {
							$dim = (int) date('t', mktime(0, 0, 0, $mo, 1, $y));
							$d = $day > 0 ? min($day, $dim) : 1;
							$occ_start = mktime(0, 0, 0, $mo, $d, $y) + $start_time;
							$occ_end = $occ_start + $period;
							if ($occ_end >= $clip_since && $occ_start <= $clip_till) {
								$segments[] = [max($occ_start, $clip_since), min($occ_end, $clip_till)];
							}
						}
						$mo++;
						if ($mo > 12) {
							$mo = 1;
							$y++;
						}
					}
					break;
				default:
					$segments[] = [$clip_since, $clip_till];
			}
		}
		if (empty($segments)) {
			$segments[] = [$clip_since, $clip_till];
		}
		return $segments;
	}

	private function formatMonth(int $ts): string {
		$months = [
			1 => _('January'), 2 => _('February'), 3 => _('March'), 4 => _('April'),
			5 => _('May'), 6 => _('June'), 7 => _('July'), 8 => _('August'),
			9 => _('September'), 10 => _('October'), 11 => _('November'), 12 => _('December')
		];
		return ($months[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
	}
}
