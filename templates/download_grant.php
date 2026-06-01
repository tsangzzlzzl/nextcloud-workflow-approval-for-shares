<?php
/** @var array $_ */
style('webhooks', 'style');
$l = \OC::$server->getL10N('webhooks');
$id = (int)($_['id'] ?? 0);
$file = (string)($_['file'] ?? '');
$requester = (string)($_['requester'] ?? '');
$defaultLimit = (int)($_['defaultLimit'] ?? 1);
$defaultMinutes = (int)($_['defaultMinutes'] ?? 1440);
$actionUrl = (string)($_['actionUrl'] ?? '');
$rejectUrl = (string)($_['rejectUrl'] ?? '');
?>
<div class="webhooks-page-shell webhooks-download-grant-page">
	<div class="webhooks-approval-card">
		<div class="webhooks-approval-card-header">
			<div class="webhooks-approval-icon success">↓</div>
			<div>
				<h2 class="webhooks-approval-title"><?php p($l->t('设置下载授权')); ?></h2>
				<p class="webhooks-approval-message">
					<?php p($l->t('审批通过后，申请人将在限定次数和有效期内下载该文件。')); ?>
				</p>
			</div>
		</div>

		<div class="webhooks-meta-box">
			<div class="webhooks-meta-item">
				<span class="webhooks-meta-label"><?php p($l->t('申请人')); ?></span>
				<span class="webhooks-meta-value"><?php p($requester); ?></span>
			</div>
			<div class="webhooks-meta-item">
				<span class="webhooks-meta-label"><?php p($l->t('申请文件')); ?></span>
				<span class="webhooks-meta-value"><?php p($file); ?></span>
			</div>
		</div>

		<form method="get" action="<?php p($actionUrl); ?>" class="webhooks-download-grant-form">
			<input type="hidden" name="grant" value="1">
			<div class="webhooks-form-grid">
				<label class="webhooks-field">
					<span><?php p($l->t('允许下载次数')); ?></span>
					<input type="number" name="downloadLimit" min="1" max="999" value="<?php p((string)$defaultLimit); ?>" required>
				</label>
				<label class="webhooks-field">
					<span><?php p($l->t('下载有效期')); ?></span>
					<select name="validMinutes">
						<option value="30" <?php if ($defaultMinutes === 30) { print_unescaped('selected'); } ?>><?php p($l->t('30 分钟')); ?></option>
						<option value="60" <?php if ($defaultMinutes === 60) { print_unescaped('selected'); } ?>><?php p($l->t('1 小时')); ?></option>
						<option value="360" <?php if ($defaultMinutes === 360) { print_unescaped('selected'); } ?>><?php p($l->t('6 小时')); ?></option>
						<option value="720" <?php if ($defaultMinutes === 720) { print_unescaped('selected'); } ?>><?php p($l->t('12 小时')); ?></option>
						<option value="1440" <?php if ($defaultMinutes === 1440) { print_unescaped('selected'); } ?>><?php p($l->t('1 天')); ?></option>
						<option value="4320" <?php if ($defaultMinutes === 4320) { print_unescaped('selected'); } ?>><?php p($l->t('3 天')); ?></option>
						<option value="10080" <?php if ($defaultMinutes === 10080) { print_unescaped('selected'); } ?>><?php p($l->t('7 天')); ?></option>
					</select>
				</label>
			</div>
			<p class="webhooks-approval-hint">
				<?php p($l->t('超过有效期或下载次数用完后，用户需要重新提交下载审批。')); ?>
			</p>
			<div class="webhooks-approval-actions">
				<button type="submit" class="primary"><?php p($l->t('同意并授权下载')); ?></button>
				<a class="secondary" href="<?php p($rejectUrl); ?>"><?php p($l->t('拒绝')); ?></a>
			</div>
		</form>
	</div>
</div>
