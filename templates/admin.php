<?php
style('webhooks', 'style');
$activeCount = is_array($_['activeEvents'] ?? null) ? count($_['activeEvents']) : 0;
$inactiveCount = is_array($_['inactiveEvents'] ?? null) ? count($_['inactiveEvents']) : 0;
?>

<div id="webhooks" class="section webhooks-admin-page">
	<div class="webhooks-admin-hero">
		<h2>Webhooks 与文件审批</h2>
		<p>当前插件保留原始 Webhooks 能力，并扩展文件分享 / 下载审批工作流。这里可以快速查看插件状态、Webhook 事件地址和未启用事件配置项。</p>
		<a target="_blank" rel="noreferrer" class="webhooks-admin-doc-link" title="Open documentation" href="https://github.com/kffl/nextcloud-webhooks">
			<span>文档</span>
		</a>
	</div>

	<div class="webhooks-admin-grid">
		<div class="webhooks-status-card">
			<div class="webhooks-status-card-header">
				<p class="webhooks-status-card-title">插件状态</p>
				<span class="webhooks-status-pill success">正常</span>
			</div>
			<p class="webhooks-status-card-value">已启用</p>
		</div>

		<div class="webhooks-status-card">
			<div class="webhooks-status-card-header">
				<p class="webhooks-status-card-title">签名密钥</p>
				<?php if (!empty($_['secret'])) : ?>
					<span class="webhooks-status-pill success">已配置</span>
				<?php else : ?>
					<span class="webhooks-status-pill warning">未配置</span>
				<?php endif ?>
			</div>
			<p class="webhooks-status-card-value"><?php p(!empty($_['secret']) ? '可签名请求' : '建议配置'); ?></p>
		</div>

		<div class="webhooks-status-card">
			<div class="webhooks-status-card-header">
				<p class="webhooks-status-card-title">curl 命令</p>
				<?php if ($_['canCurl']) : ?>
					<span class="webhooks-status-pill success">可用</span>
				<?php else : ?>
					<span class="webhooks-status-pill error">异常</span>
				<?php endif ?>
			</div>
			<p class="webhooks-status-card-value"><?php p($_['canCurl'] ? '工作正常' : '需要检查'); ?></p>
		</div>

		<div class="webhooks-status-card">
			<div class="webhooks-status-card-header">
				<p class="webhooks-status-card-title">事件配置</p>
				<span class="webhooks-status-pill success"><?php p((string)$activeCount); ?> 个启用</span>
			</div>
			<p class="webhooks-status-card-value"><?php p((string)$inactiveCount); ?> 个待配置</p>
		</div>
	</div>

	<div class="webhooks-panel">
		<div class="webhooks-panel-header">
			<div>
				<h3 class="webhooks-panel-title">已启用事件</h3>
				<p class="webhooks-panel-subtitle">这些事件已经配置了对应的 Webhook URL。</p>
			</div>
		</div>
		<?php if (!empty($_['activeEvents'])): ?>
			<ol class="webhooks-event-list">
				<?php foreach ($_['activeEvents'] as $eventName => $eventUrl) : ?>
					<li class="webhooks-event-item">
						<span class="webhooks-event-name"><?php p($eventName); ?></span>
						<code class="webhooks-event-code"><?php p($eventUrl); ?></code>
					</li>
				<?php endforeach ?>
			</ol>
		<?php else: ?>
			<div class="webhooks-empty-state">暂无已启用事件。</div>
		<?php endif; ?>
	</div>

	<?php if(!empty($_['inactiveEvents'])): ?>
	<div class="webhooks-panel">
		<div class="webhooks-panel-header">
			<div>
				<h3 class="webhooks-panel-title">未启用事件</h3>
				<p class="webhooks-panel-subtitle">将下面的配置项写入 config.php 后即可启用对应事件。</p>
			</div>
		</div>
		<ol class="webhooks-event-list">
			<?php foreach ($_['inactiveEvents'] as $eventName => $configName) : ?>
				<li class="webhooks-event-item">
					<span class="webhooks-event-name"><?php p($eventName); ?></span>
					<code class="webhooks-event-code"><?php p($configName); ?></code>
				</li>
			<?php endforeach ?>
		</ol>
		<p id="webhooks-hint" class="settings-hint">你可以在 config.php 中提供这些事件的 Webhook URL 来启用它们。</p>
	</div>
	<?php endif; ?>
</div>
