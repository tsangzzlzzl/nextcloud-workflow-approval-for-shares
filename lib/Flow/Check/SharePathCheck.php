<?php

declare(strict_types=1);

namespace OCA\Webhooks\Flow\Check;

use OCA\Webhooks\Flow\ShareApprovalEntity;
use OCA\Webhooks\Service\ShareApprovalService;
use OCP\WorkflowEngine\ICheck;
use OCP\WorkflowEngine\IManager as FlowManager;
use UnexpectedValueException;

class SharePathCheck implements ICheck {
	public function executeCheck($operator, $value) {
		$paths = ShareApprovalService::normalizePathList($value);
		if ($paths === []) {
			return false;
		}

		$sharePath = ShareApprovalService::getCurrentShareRelativePath();
		if ($sharePath === '') {
			return $operator === '!in';
		}

		$inside = ShareApprovalService::isPathWithinAny($sharePath, $paths);
		if ($operator === 'in') {
			return $inside;
		}
		if ($operator === '!in') {
			return !$inside;
		}

		return false;
	}

	public function validateCheck($operator, $value) {
		if (!in_array($operator, ['in', '!in'], true)) {
			throw new UnexpectedValueException('文件夹路径比较方式无效');
		}

		if (ShareApprovalService::normalizePathList($value) === []) {
			throw new UnexpectedValueException('至少需要选择一个文件夹路径');
		}
	}

	public function supportedEntities(): array {
		return [ShareApprovalEntity::class];
	}

	public function isAvailableForScope(int $scope): bool {
		return $scope === FlowManager::SCOPE_ADMIN;
	}
}
