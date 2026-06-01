<?php

declare(strict_types=1);

namespace OCA\Webhooks\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0500Date20260522000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('webhooks_sa')) {
			$table = $schema->createTable('webhooks_sa');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('approval_key', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('flow_id', 'string', [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('status', 'string', [
				'notnull' => true,
				'length' => 32,
				'default' => 'pending',
			]);
			$table->addColumn('strategy', 'string', [
				'notnull' => true,
				'length' => 16,
				'default' => 'any',
			]);
			$table->addColumn('approvers', 'text', ['notnull' => true]);
			$table->addColumn('decisions', 'text', ['notnull' => false]);
			$table->addColumn('share_payload', 'text', ['notnull' => true]);
			$table->addColumn('file_id', 'integer', [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('file_path', 'string', [
				'notnull' => false,
				'length' => 1024,
			]);
			$table->addColumn('shared_by', 'string', [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('share_owner', 'string', [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('share_type', 'integer', [
				'notnull' => false,
			]);
			$table->addColumn('shared_with', 'string', [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('restored_share_id', 'string', [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->addColumn('decided_at', 'datetime', ['notnull' => false]);

			$table->setPrimaryKey(['id'], 'whsa_pk');
			$table->addUniqueIndex(['approval_key'], 'whsa_key');
			$table->addIndex(['status'], 'whsa_status');
			$table->addIndex(['shared_by'], 'whsa_user');
		}

		return $schema;
	}
}
