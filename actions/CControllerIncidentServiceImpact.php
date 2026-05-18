<?php declare(strict_types = 0);

namespace Modules\IncidentInvestigation\Actions;

use API;
use CController;
use CRoleHelper;
use Exception;

class CControllerIncidentServiceImpact extends CController {

	private static $sliCache = [];
	private static $cacheExpiry = 300;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function checkInput(): bool {
		$fields = [
			'hostid' => 'required|db hosts.hostid'
		];
		return $this->validateInput($fields);
	}

	protected function doAction(): void {
		header('Content-Type: application/json');
		$response = ['success' => false];

		try {
			$hostid = $this->getInput('hostid');
			$host_tags = $this->getHostTags($hostid);

			if (empty($host_tags)) {
				$response = [
					'success' => true,
					'data' => [
						'host_tags' => [],
						'impacted_services' => [],
						'service_trees' => [],
						'summary' => ['total_tags' => 0, 'total_services' => 0, 'matching_tags' => 0]
					]
				];
				echo json_encode($response);
				exit;
			}

			$impacted_services = $this->findImpactedServices($host_tags);
			$service_trees = $this->buildServiceTrees($impacted_services);
			$matching_tags = [];
			foreach ($impacted_services as $service) {
				foreach ($service['matching_tags'] as $tag) {
					$tag_key = $tag['tag'] . ':' . $tag['value'];
					if (!in_array($tag_key, $matching_tags)) {
						$matching_tags[] = $tag_key;
					}
				}
			}

			$response = [
				'success' => true,
				'data' => [
					'host_tags' => $host_tags,
					'impacted_services' => $impacted_services,
					'service_trees' => $service_trees,
					'summary' => [
						'total_tags' => count($host_tags),
						'total_services' => count($impacted_services),
						'matching_tags' => count($matching_tags)
					]
				]
			];
		} catch (Exception $e) {
			$response = [
				'success' => false,
				'error' => ['title' => _('Error'), 'message' => $e->getMessage()]
			];
		}

		echo json_encode($response);
		exit;
	}

	private function getHostTags(string $hostid): array {
		$all_tags = [];
		$unique_tags = [];

		try {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => [$hostid],
				'selectTags' => ['tag', 'value']
			]);
			if (!empty($hosts) && !empty($hosts[0]['tags'])) {
				foreach ($hosts[0]['tags'] as $tag) {
					$key = $tag['tag'] . '::' . ($tag['value'] ?? '');
					if (!isset($unique_tags[$key])) {
						$unique_tags[$key] = true;
						$all_tags[] = ['tag' => $tag['tag'], 'value' => $tag['value'] ?? '', 'source' => 'host'];
					}
				}
			}

			$items = API::Item()->get([
				'output' => ['itemid'],
				'hostids' => [$hostid],
				'selectTags' => ['tag', 'value']
			]);
			foreach ($items as $item) {
				if (!empty($item['tags'])) {
					foreach ($item['tags'] as $tag) {
						$key = $tag['tag'] . '::' . ($tag['value'] ?? '');
						if (!isset($unique_tags[$key])) {
							$unique_tags[$key] = true;
							$all_tags[] = ['tag' => $tag['tag'], 'value' => $tag['value'] ?? '', 'source' => 'item'];
						}
					}
				}
			}

			$triggers = API::Trigger()->get([
				'output' => ['triggerid'],
				'hostids' => [$hostid],
				'selectTags' => ['tag', 'value']
			]);
			foreach ($triggers as $trigger) {
				if (!empty($trigger['tags'])) {
					foreach ($trigger['tags'] as $tag) {
						$key = $tag['tag'] . '::' . ($tag['value'] ?? '');
						if (!isset($unique_tags[$key])) {
							$unique_tags[$key] = true;
							$all_tags[] = ['tag' => $tag['tag'], 'value' => $tag['value'] ?? '', 'source' => 'trigger'];
						}
					}
				}
			}

			return $all_tags;
		} catch (Exception $e) {
			return [];
		}
	}

	private function findImpactedServices(array $host_tags): array {
		$impacted_services = [];

		try {
			$all_services = API::Service()->get([
				'output' => ['serviceid', 'name', 'status', 'algorithm'],
				'selectProblemTags' => ['tag', 'value'],
				'selectParents' => ['serviceid', 'name'],
				'selectChildren' => ['serviceid', 'name']
			]);

			foreach ($all_services as $service) {
				$problem_tags = $service['problem_tags'] ?? [];
				if (empty($problem_tags)) {
					continue;
				}

				$matching_tags = [];
				foreach ($host_tags as $host_tag) {
					foreach ($problem_tags as $problem_tag) {
						if ($problem_tag['tag'] === $host_tag['tag'] && ($problem_tag['value'] ?? '') === ($host_tag['value'] ?? '')) {
							$matching_tags[] = $host_tag;
							break;
						}
					}
				}

				if (!empty($matching_tags)) {
					$sli_data = $this->getSLIDataWithParentFallback($service['serviceid']);
					$impacted_services[] = [
						'serviceid' => $service['serviceid'],
						'name' => $service['name'],
						'status' => $service['status'],
						'algorithm' => $service['algorithm'],
						'matching_tags' => $matching_tags,
						'problem_tags' => $problem_tags,
						'parents' => $service['parents'] ?? [],
						'children' => $service['children'] ?? [],
						'sli' => $sli_data['sli'] ?? null,
						'uptime' => $sli_data['uptime'] ?? null,
						'downtime' => $sli_data['downtime'] ?? null,
						'error_budget' => $sli_data['error_budget'] ?? null,
						'has_sla' => $sli_data['has_sla'] ?? false,
						'sla_name' => $sli_data['sla_name'] ?? null,
						'slo' => $sli_data['slo'] ?? null,
						'inherited_from' => $sli_data !== null ? ($sli_data['inherited_from'] ?? null) : null,
						'inherited_from_name' => $sli_data !== null ? ($sli_data['inherited_from_name'] ?? null) : null
					];
				}
			}
		} catch (Exception $e) {
		}

		return $impacted_services;
	}

	private function buildServiceTrees(array $impacted_services): array {
		$trees = [];
		foreach ($impacted_services as $service) {
			$path_to_root = $this->getPathToRoot($service['serviceid']);
			$children_tree = $this->getChildrenTree($service['serviceid']);
			$trees[] = [
				'impacted_service' => [
					'serviceid' => $service['serviceid'],
					'name' => $service['name'],
					'status' => $service['status'],
					'matching_tags' => $service['matching_tags'],
					'sli' => $service['sli'],
					'has_sla' => $service['has_sla'],
					'sla_name' => $service['sla_name'],
					'slo' => $service['slo'] ?? null,
					'uptime' => $service['uptime'],
					'downtime' => $service['downtime'],
					'error_budget' => $service['error_budget'],
					'inherited_from' => $service['inherited_from'] ?? null,
					'inherited_from_name' => $service['inherited_from_name'] ?? null
				],
				'path_to_root' => $path_to_root,
				'children_tree' => $children_tree
			];
		}
		return $trees;
	}

	private function getPathToRoot(string $serviceid): array {
		$path = [];
		$visited = [];
		$current_serviceid = $serviceid;

		while ($current_serviceid && !in_array($current_serviceid, $visited)) {
			$visited[] = $current_serviceid;
			$services = API::Service()->get([
				'output' => ['serviceid', 'name', 'status', 'algorithm'],
				'serviceids' => [$current_serviceid],
				'selectParents' => ['serviceid', 'name'],
				'selectProblemTags' => ['tag', 'value']
			]);

			if (empty($services)) {
				break;
			}

			$service = $services[0];
			$sli_data = $this->getSLIDataOptimized($service['serviceid']);
			array_unshift($path, [
				'serviceid' => $service['serviceid'],
				'name' => $service['name'],
				'status' => $service['status'],
				'sli' => $sli_data['sli'] ?? null,
				'has_sla' => $sli_data['has_sla'] ?? false,
				'slo' => $sli_data['slo'] ?? null,
				'uptime' => $sli_data['uptime'] ?? null,
				'downtime' => $sli_data['downtime'] ?? null,
				'error_budget' => $sli_data['error_budget'] ?? null
			]);

			if (!empty($service['parents'])) {
				$current_serviceid = $service['parents'][0]['serviceid'];
			} else {
				break;
			}
		}
		return $path;
	}

	private function getChildrenTree(string $serviceid, int $depth = 0, array $visited = []): array {
		if ($depth > 10 || in_array($serviceid, $visited)) {
			return [];
		}
		$visited[] = $serviceid;

		$services = API::Service()->get([
			'output' => ['serviceid', 'name', 'status', 'algorithm'],
			'serviceids' => [$serviceid],
			'selectChildren' => ['serviceid', 'name', 'status'],
			'selectProblemTags' => ['tag', 'value']
		]);

		if (empty($services) || empty($services[0]['children'])) {
			return [];
		}

		$children = [];
		foreach ($services[0]['children'] as $child) {
			$child_services = API::Service()->get([
				'output' => ['serviceid', 'name', 'status', 'algorithm'],
				'serviceids' => [$child['serviceid']],
				'selectProblemTags' => ['tag', 'value']
			]);
			if (!empty($child_services)) {
				$child_service = $child_services[0];
				$sli_data = $this->getSLIDataOptimized($child_service['serviceid']);
				$children[] = [
					'serviceid' => $child_service['serviceid'],
					'name' => $child_service['name'],
					'status' => $child_service['status'],
					'sli' => $sli_data['sli'] ?? null,
					'has_sla' => $sli_data['has_sla'] ?? false,
					'slo' => $sli_data['slo'] ?? null,
					'uptime' => $sli_data['uptime'] ?? null,
					'downtime' => $sli_data['downtime'] ?? null,
					'error_budget' => $sli_data['error_budget'] ?? null,
					'children' => $this->getChildrenTree($child_service['serviceid'], $depth + 1, $visited)
				];
			}
		}
		return $children;
	}

	/**
	 * Get SLA/SLI data for a service. If the service has no SLA, walk up the parent
	 * chain and use the first ancestor that has an SLA (common when SLA is at parent level).
	 * Does not affect native Zabbix SLA logic; only used for display in this module.
	 */
	private function getSLIDataWithParentFallback(string $serviceid): ?array {
		$sli_data = $this->getSLIDataOptimized($serviceid);
		if ($sli_data !== null) {
			return $sli_data;
		}

		$visited = [];
		$current = $serviceid;
		$max_depth = 10;

		for ($i = 0; $i < $max_depth; $i++) {
			if (in_array($current, $visited)) {
				break;
			}
			$visited[] = $current;

			$services = API::Service()->get([
				'output' => ['serviceid'],
				'serviceids' => [$current],
				'selectParents' => ['serviceid', 'name']
			]);

			if (empty($services) || empty($services[0]['parents'])) {
				break;
			}

			$parent = $services[0]['parents'][0];
			$parent_sli = $this->getSLIDataOptimized($parent['serviceid']);

			if ($parent_sli !== null) {
				return [
					'sli' => $parent_sli['sli'] ?? null,
					'uptime' => $parent_sli['uptime'] ?? null,
					'downtime' => $parent_sli['downtime'] ?? null,
					'error_budget' => $parent_sli['error_budget'] ?? null,
					'has_sla' => true,
					'sla_name' => $parent_sli['sla_name'] ?? null,
					'slo' => $parent_sli['slo'] ?? null,
					'inherited_from' => $parent['serviceid'],
					'inherited_from_name' => $parent['name'] ?? null
				];
			}

			$current = $parent['serviceid'];
		}

		return null;
	}

	private function getSLIDataOptimized(string $serviceid): ?array {
		$cacheKey = 'sli_' . $serviceid;
		if (isset(self::$sliCache[$cacheKey]) && self::$sliCache[$cacheKey]['expiry'] > time()) {
			return self::$sliCache[$cacheKey]['data'];
		}
		unset(self::$sliCache[$cacheKey]);

		try {
			$sli_data = $this->fastSlaLookup($serviceid);
			self::$sliCache[$cacheKey] = [
				'data' => $sli_data,
				'expiry' => time() + ($sli_data ? self::$cacheExpiry : 30)
			];
			return $sli_data;
		} catch (Exception $e) {
			self::$sliCache[$cacheKey] = ['data' => null, 'expiry' => time() + 15];
			return null;
		}
	}

	private function fastSlaLookup(string $serviceid): ?array {
		try {
			if (!class_exists('API')) {
				return null;
			}
			$slas = API::SLA()->get([
				'output' => ['slaid', 'name', 'slo'],
				'serviceids' => [$serviceid],
				'limit' => 1
			]);
			if (empty($slas)) {
				return null;
			}

			$sla = $slas[0];
			$sli_response = API::SLA()->getSli([
				'slaid' => $sla['slaid'],
				'serviceids' => [(int) $serviceid],
				'periods' => 1,
				'period_from' => time()
			]);

			if (empty($sli_response) || !isset($sli_response['serviceids'], $sli_response['sli'])) {
				return null;
			}

			$idx = array_search((int) $serviceid, $sli_response['serviceids']);
			if ($idx === false || empty($sli_response['sli'][$idx])) {
				return null;
			}

			$period_data = end($sli_response['sli'][$idx]);
			return [
				'sli' => $period_data['sli'] ?? null,
				'uptime' => $this->formatDuration($period_data['uptime'] ?? 0),
				'downtime' => $this->formatDuration($period_data['downtime'] ?? 0),
				'error_budget' => $this->formatDuration($period_data['error_budget'] ?? 0),
				'has_sla' => true,
				'sla_name' => $sla['name'],
				'slo' => $sla['slo'] ?? null
			];
		} catch (Exception $e) {
			return null;
		}
	}

	private function formatDuration(int $seconds): string {
		$is_negative = $seconds < 0;
		$abs_seconds = abs($seconds);
		if ($abs_seconds <= 0) {
			return '0s';
		}
		$days = (int) floor($abs_seconds / 86400);
		$hours = (int) floor(($abs_seconds % 86400) / 3600);
		$minutes = (int) floor(($abs_seconds % 3600) / 60);
		$secs = $abs_seconds % 60;
		$parts = [];
		if ($days > 0) $parts[] = $days . 'd';
		if ($hours > 0) $parts[] = $hours . 'h';
		if ($minutes > 0) $parts[] = $minutes . 'm';
		if ($secs > 0 && empty($parts)) $parts[] = $secs . 's';
		$formatted = implode(' ', array_slice($parts, 0, 2));
		return $is_negative ? '-' . $formatted : $formatted;
	}
}
