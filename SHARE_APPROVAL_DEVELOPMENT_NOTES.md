# Workflow Approval for Shares - 二次开发说明

本次改动采用“新增功能”的方式实现，保留原 `webhooks` 插件的所有既有功能：

- 原 `OCA\Webhooks\Flow\Operation` 仍然是 `Outgoing webhook`。
- 原 `ShareCreatedListener` 仍然用于 `config.php` 中配置的 `webhooks_share_created_url` 外发 webhook。
- 新增 `OCA\Webhooks\Flow\ShareApprovalOperation`，专门用于分享前审批。

## 新增能力

1. 新增 Workflow Engine 实体：`OCA\Webhooks\Flow\ShareApprovalEntity`
   - 事件展示名：`File shared`
   - 底层事件：`OCP\Share\Events\BeforeShareCreatedEvent`

2. 新增 Flow 操作：`Share approval before publishing`
   - 审批人：支持多个用户 ID。
   - 审批策略：`any` 或签、`all` 会签。
   - 文件夹范围：全部、仅指定文件夹内、排除指定文件夹。

3. 新增通知审批
   - 审批人收到 Nextcloud 原生通知，包含 Approve / Reject 动作。
   - 发起人收到“待审批 / 通过 / 拒绝”状态通知。

4. 新增数据库表
   - `oc_webhooks_sa`
   - 保存审批状态、审批人、审批记录、原分享参数和恢复后的分享 ID。

## 工作流逻辑

- 用户创建分享。
- 命中审批 Flow 后，插件通过 `BeforeShareCreatedEvent::setError()` 阻止原分享直接创建。
- 插件保存原分享参数，并通知审批人。
- 审批通过后，插件使用原参数重新创建分享。
- 审批拒绝后，不创建分享。

## 注意事项

- 本版本面向 Nextcloud 32，`appinfo/info.xml` 中限制为 `min-version="32" max-version="32"`。
- 公开链接分享的密码明文通常无法从已构造的分享对象中可靠读取，本实现不会保存或恢复明文密码；如业务强制要求“公开链接密码也必须原样恢复”，建议在分享表单层或 OCS API 层额外拦截 password 参数。
- 文件夹搜索接口基于当前登录用户的文件权限返回文件夹路径，因此不会暴露当前用户不可访问的目录。

## 0.5.2 修复说明

本版本继续保留原 Webhooks 功能，仅修复分享审批新增功能的配置体验：

1. 审批人选择器
   - 将 Flow 配置组件从 Vue `template` 字符串改为 `render(h)` 函数，避免 Nextcloud Workflow 设置页 runtime-only Vue 无法编译模板导致配置项不显示。
   - 新增 `/apps/webhooks/share-approval/users` 用户搜索接口。
   - 支持按用户名称/用户 ID 搜索审批人、添加/删除审批人，也保留手动输入用户 ID 的方式。

2. 文件夹过滤
   - 审批操作自身保留“全部文件夹 / 仅指定文件夹内 / 排除指定文件夹”配置。
   - 新增 `SharePathCheck` 工作流过滤器，用于在 Flow 的过滤条件区域按共享文件所在文件夹路径做判断。
   - 新增前端 check 注册，提供“Shared folder path”过滤项，并支持搜索当前用户可访问文件夹。

3. 兼容性
   - 原 `Outgoing webhook` Flow 操作不改动。
   - 原分享创建 webhook 监听不改动。
   - 数据库表结构不变，无需新增迁移。

## 0.5.5

- 修复分享未真正拦截的问题：在 `BeforeShareCreatedEvent` 命中审批规则后同时调用 `setError()` 和 `stopPropagation()`，确保 Nextcloud 32 在原分享入库前终止创建，审批通过后再由插件恢复创建分享。
- 将分享审批相关 Flow 配置、审批操作、文件夹过滤器、校验提示、通知标题和通知按钮改为中文描述。
- 保留原有 Outgoing webhook 功能不变。
