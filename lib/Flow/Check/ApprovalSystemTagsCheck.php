<?php

declare(strict_types=1);

namespace OCA\Webhooks\Flow\Check;

use OCA\Webhooks\Flow\ShareApprovalEntity;
use OCA\Webhooks\Service\ShareApprovalService;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\WorkflowEngine\ICheck;
use OCP\WorkflowEngine\IManager as FlowManager;
use UnexpectedValueException;

class ApprovalSystemTagsCheck implements ICheck {
	/** @var ISystemTagManager */
	private $systemTagManager;
	/** @var ISystemTagObjectMapper */
	private $systemTagObjectMapper;

	public function __construct(ISystemTagManager $systemTagManager, ISystemTagObjectMapper $systemTagObjectMapper) {
		$this->systemTagManager = $systemTagManager;
		$this->systemTagObjectMapper = $systemTagObjectMapper;
	}

	public function executeCheck($operator, $value) {
		$node = ShareApprovalService::getCurrentNode();
		if ($node === null) {
			return $operator === '!is';
		}

		$tagId = trim((string)$value);
		if ($tagId === '') {
			return false;
		}

		try {
			$mapped = $this->systemTagObjectMapper->getTagIdsForObjects([(string)$node->getId()], 'files');
			$tagIds = $mapped[(string)$node->getId()] ?? $mapped[$node->getId()] ?? [];
			$tagIds = array_map('strval', is_array($tagIds) ? $tagIds : []);
			$hasTag = in_array($tagId, $tagIds, true);
			return $operator === 'is' ? $hasTag : !$hasTag;
		} catch (\Throwable $e) {
			return $operator === '!is';
		}
	}

	public function validateCheck($operator, $value) {
		if (!in_array($operator, ['is', '!is'], true)) {
			throw new UnexpectedValueException('文件系统标签比较方式无效');
		}
		try {
			$this->systemTagManager->getTagsByIds((string)$value);
		} catch (\Throwable $e) {
			throw new UnexpectedValueException('文件系统标签无效');
		}
	}

	public function supportedEntities(): array {
		return [ShareApprovalEntity::class];
	}

	public function isAvailableForScope(int $scope): bool {
		return $scope === FlowManager::SCOPE_ADMIN;
	}
}
