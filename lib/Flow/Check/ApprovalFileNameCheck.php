<?php

declare(strict_types=1);

namespace OCA\Webhooks\Flow\Check;

use OCA\Webhooks\Flow\ShareApprovalEntity;
use OCA\Webhooks\Service\ShareApprovalService;
use OCP\WorkflowEngine\ICheck;
use OCP\WorkflowEngine\IManager as FlowManager;
use UnexpectedValueException;

class ApprovalFileNameCheck implements ICheck {
	public function executeCheck($operator, $value) {
		$actual = mb_strtolower(ShareApprovalService::getCurrentFileName());
		$expected = mb_strtolower(trim((string)$value));

		if ($expected === '') {
			return false;
		}

		switch ($operator) {
			case 'is':
				return $actual === $expected;
			case '!is':
				return $actual !== $expected;
			case 'contains':
				return strpos($actual, $expected) !== false;
			case '!contains':
				return strpos($actual, $expected) === false;
			case 'matches':
				return @preg_match($expected, $actual) === 1;
			case '!matches':
				return @preg_match($expected, $actual) !== 1;
		}

		return false;
	}

	public function validateCheck($operator, $value) {
		if (!in_array($operator, ['is', '!is', 'contains', '!contains', 'matches', '!matches'], true)) {
			throw new UnexpectedValueException('文件名比较方式无效');
		}
		if (trim((string)$value) === '') {
			throw new UnexpectedValueException('文件名筛选值不能为空');
		}
	}

	public function supportedEntities(): array {
		return [ShareApprovalEntity::class];
	}

	public function isAvailableForScope(int $scope): bool {
		return $scope === FlowManager::SCOPE_ADMIN;
	}
}
