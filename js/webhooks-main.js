(function() {
	'use strict'

	if (!window.OCA || !window.OCA.WorkflowEngine) {
		return
	}

	const parseJson = function(value, fallback) {
		if (!value) {
			return fallback
		}
		try {
			return JSON.parse(value)
		} catch (e) {
			return fallback
		}
	}

	const normalizeList = function(value) {
		let raw = value
		if (typeof raw === 'string') {
			const json = parseJson(raw, null)
			if (Array.isArray(json)) {
				raw = json
			} else {
				raw = raw.split(/[\n,;]+/)
			}
		}
		if (!Array.isArray(raw)) {
			return []
		}
		return raw
			.map(function(item) {
				if (item && typeof item === 'object') {
					return String(item.id || item.uid || item.path || item.value || '').trim()
				}
				return String(item || '').trim()
			})
			.filter(function(item, index, list) {
				return item !== '' && list.indexOf(item) === index
			})
	}

	const defaultShareApproval = {
		triggerActions: ['share', 'download'],
		approvers: [],
		strategy: 'any',
		pathMode: 'all',
		paths: [],
	}

	const mergeShareApproval = function(value) {
		const current = Object.assign({}, defaultShareApproval, parseJson(value, defaultShareApproval))
		current.triggerActions = normalizeList(current.triggerActions || ['share']).filter(function(action) { return ['share', 'download'].indexOf(action) !== -1 })
		if (current.triggerActions.length === 0) { current.triggerActions = ['share'] }
		current.approvers = normalizeList(current.approvers)
		current.paths = normalizeList(current.paths)
		if (['any', 'all'].indexOf(current.strategy) === -1) {
			current.strategy = 'any'
		}
		if (['all', 'include', 'exclude'].indexOf(current.pathMode) === -1) {
			current.pathMode = 'all'
		}
		return current
	}


	const csrfHeaders = function() {
		const headers = {
			'Accept': 'application/json',
		}
		const token = (window.OC && (OC.requestToken || OC.requesttoken)) || window.oc_requesttoken || ''
		if (token !== '') {
			headers.requesttoken = token
			headers['X-Requested-With'] = 'XMLHttpRequest'
		}
		return headers
	}

	const style = function(extra) {
		return Object.assign({}, extra || {})
	}

	const WebhookOptions = {
		name: 'Webhooks',
		props: {
			value: {
				default: JSON.stringify({ url: '' }),
				type: String,
			},
		},
		computed: {
			currentUrl() {
				return parseJson(this.value, { url: '' }).url || ''
			},
		},
		methods: {
			emitInput(event) {
				this.$emit('input', JSON.stringify({ url: event.target.value }))
			},
		},
		render(h) {
			return h('div', { class: 'webhooks-flow-option' }, [
				h('input', {
					attrs: {
						type: 'url',
						maxlength: '160',
						placeholder: '请输入 Webhook 地址',
					},
					domProps: { value: this.currentUrl },
					on: { input: this.emitInput },
					style: style({ width: '100% !important' }),
				}),
			])
		},
	}


	const commonButtonStyle = {
		border: '1px solid var(--color-border, #ddd)',
		borderRadius: '999px',
		padding: '0 14px',
		minHeight: '38px',
		background: 'var(--color-main-background, #fff)',
		cursor: 'pointer',
		fontWeight: '700',
	}

	const tagStyle = {
		display: 'inline-flex',
		alignItems: 'center',
		gap: '6px',
		borderRadius: '999px',
		padding: '6px 10px 6px 12px',
		margin: '4px 6px 4px 0',
		background: 'var(--color-primary-light, #e8f4ff)',
		color: 'var(--color-main-text, #222)',
		fontWeight: '700',
	}

	const sectionStyle = { marginBottom: '14px' }
	const labelStyle = { display: 'block', fontWeight: '700', marginBottom: '8px' }
	const hintStyle = { fontSize: '12px', color: 'var(--color-text-maxcontrast, #6b7280)', marginTop: '6px' }
	const resultListStyle = {
		marginTop: '10px',
		maxHeight: '190px',
		overflow: 'auto',
		border: '1px solid var(--color-border, #ddd)',
		borderRadius: '16px',
		padding: '6px',
		background: 'var(--color-main-background, #fff)',
	}

	const ShareApprovalOptions = {
		name: 'ShareApprovalOptions',
		props: {
			value: {
				default: JSON.stringify(defaultShareApproval),
				type: String,
			},
		},
		data() {
			const current = mergeShareApproval(this.value)
			return {
				triggerActions: current.triggerActions,
				approvers: current.approvers,
				strategy: current.strategy,
				pathMode: current.pathMode,
				paths: current.paths,
				userTerm: '',
				userResults: [],
				userLoading: false,
				folderTerm: '',
				folderResults: [],
				folderLoading: false,
				manualApprover: '',
			}
		},
		methods: {
			emitChange() {
				this.$emit('input', JSON.stringify({
					triggerActions: normalizeList(this.triggerActions).filter(function(action) { return ['share', 'download'].indexOf(action) !== -1 }),
					approvers: normalizeList(this.approvers),
					strategy: this.strategy,
					pathMode: this.pathMode,
					paths: normalizeList(this.paths),
				}))
			},
			toggleAction(action) {
				const index = this.triggerActions.indexOf(action)
				if (index === -1) {
					this.triggerActions.push(action)
				} else if (this.triggerActions.length > 1) {
					this.triggerActions.splice(index, 1)
				}
				this.emitChange()
			},
			addApprover(id) {
				id = String(id || '').trim()
				if (id !== '' && this.approvers.indexOf(id) === -1) {
					this.approvers.push(id)
					this.emitChange()
				}
			},
			removeApprover(id) {
				this.approvers = this.approvers.filter(function(item) { return item !== id })
				this.emitChange()
			},
			addManualApprover() {
				this.addApprover(this.manualApprover)
				this.manualApprover = ''
			},
			searchUsers() {
				const term = this.userTerm.trim()
				if (term.length === 0) {
					this.userResults = []
					return
				}
				this.userLoading = true
				const url = OC.generateUrl('/apps/webhooks/share-approval/users') + '?term=' + encodeURIComponent(term)
				fetch(url, { credentials: 'same-origin', headers: csrfHeaders() })
					.then(function(response) { return response.json() })
					.then((data) => { this.userResults = data.users || [] })
					.catch(() => { this.userResults = [] })
					.finally(() => { this.userLoading = false })
			},
			addFolder(path) {
				path = String(path || '').trim()
				if (path !== '' && this.paths.indexOf(path) === -1) {
					this.paths.push(path)
					this.emitChange()
				}
			},
			removeFolder(path) {
				this.paths = this.paths.filter(function(item) { return item !== path })
				this.emitChange()
			},
			searchFolders() {
				this.folderLoading = true
				const url = OC.generateUrl('/apps/webhooks/share-approval/folders') + '?term=' + encodeURIComponent(this.folderTerm)
				fetch(url, { credentials: 'same-origin', headers: csrfHeaders() })
					.then(function(response) { return response.json() })
					.then((data) => { this.folderResults = data.folders || [] })
					.catch(() => { this.folderResults = [] })
					.finally(() => { this.folderLoading = false })
			},
			renderChipList(h, items, removeFn, emptyText) {
				if (items.length === 0) {
					return h('div', { class: 'webhooks-empty-inline' }, emptyText)
				}
				return h('div', { class: 'webhooks-chip-list' }, items.map((item) => h('span', { class: 'webhooks-chip' }, [
					h('span', item),
					h('button', { attrs: { type: 'button', title: '移除' }, on: { click: () => removeFn(item) } }, '×'),
				])))
			},
			renderSearchResults(h, list, addFn, labelGetter, subGetter) {
				if (!list.length) return null
				return h('ul', { class: 'webhooks-result-list' }, list.map((item) => h('li', { class: 'webhooks-result-row' }, [
					h('span', [
						h('span', { class: 'webhooks-result-main' }, labelGetter(item)),
						subGetter ? h('span', { class: 'webhooks-result-sub' }, subGetter(item)) : null,
					]),
					h('button', { attrs: { type: 'button' }, class: 'webhooks-small-button primary', on: { click: () => addFn(item) } }, '添加'),
				])))
			},
			renderChoice(h, active, title, desc, onClick) {
				return h('button', {
					attrs: { type: 'button' },
					class: ['webhooks-choice-card', active ? 'active' : ''],
					on: { click: onClick },
				}, [
					h('span', { class: 'webhooks-choice-check' }, active ? '✓' : ''),
					h('span', { class: 'webhooks-choice-title' }, title),
					h('span', { class: 'webhooks-choice-desc' }, desc),
				])
			},
		},
		render(h) {
			return h('div', { class: 'webhooks-share-approval-option' }, [
				h('div', { class: 'webhooks-config-header' }, [
					h('h3', { class: 'webhooks-config-title' }, [
						h('span', { class: 'webhooks-config-title-icon' }, '✓'),
						h('span', '文件审批配置'),
					]),
					h('p', { class: 'webhooks-config-desc' }, '配置分享 / 下载审批人、审批方式和文件夹范围。支持同一规则同时管控多个触发动作。'),
				]),
				h('div', { class: 'webhooks-config-body' }, [
					h('div', { class: 'webhooks-config-section' }, [
						h('div', { class: 'webhooks-section-heading' }, [h('h4', { class: 'webhooks-section-title' }, '审批触发动作')]),
						h('div', { class: 'webhooks-action-grid' }, [
							this.renderChoice(h, this.triggerActions.indexOf('share') !== -1, '文件被分享', '用户对外分享文件或创建公开链接时触发审批。', () => this.toggleAction('share')),
							this.renderChoice(h, this.triggerActions.indexOf('download') !== -1, '文件被下载', '用户下载受控文件前先提交审批，通过后按授权次数下载。', () => this.toggleAction('download')),
						]),
						h('p', { class: 'webhooks-hint' }, '至少保留一个触发动作，可同时选择分享和下载。'),
					]),

					h('div', { class: 'webhooks-config-section' }, [
						h('div', { class: 'webhooks-section-heading' }, [h('h4', { class: 'webhooks-section-title' }, '审批人')]),
						this.renderChipList(h, this.approvers, this.removeApprover, '尚未选择审批人，请搜索并添加至少一名用户。'),
						h('div', { class: 'webhooks-inline-form' }, [
							h('input', {
								attrs: { type: 'text', placeholder: '按姓名或用户 ID 搜索用户' },
								domProps: { value: this.userTerm },
								on: {
									input: (event) => { this.userTerm = event.target.value },
									keyup: (event) => { if (event.key === 'Enter') this.searchUsers() },
								},
							}),
							h('button', { attrs: { type: 'button' }, class: 'webhooks-small-button primary', on: { click: this.searchUsers } }, this.userLoading ? '搜索中…' : '搜索'),
						]),
						this.renderSearchResults(h, this.userResults, (user) => this.addApprover(user.id), (user) => user.label || user.id, (user) => user.id),
						h('div', { class: 'webhooks-inline-form' }, [
							h('input', {
								attrs: { type: 'text', placeholder: '也可以手动输入准确的用户 ID' },
								domProps: { value: this.manualApprover },
								on: {
									input: (event) => { this.manualApprover = event.target.value },
									keyup: (event) => { if (event.key === 'Enter') this.addManualApprover() },
								},
							}),
							h('button', { attrs: { type: 'button' }, class: 'webhooks-small-button', on: { click: this.addManualApprover } }, '添加用户 ID'),
						]),
					]),

					h('div', { class: 'webhooks-config-section' }, [
						h('div', { class: 'webhooks-section-heading' }, [h('h4', { class: 'webhooks-section-title' }, '审批方式')]),
						h('div', { class: 'webhooks-strategy-grid' }, [
							this.renderChoice(h, this.strategy === 'any', '或签', '任意一名审批人同意后即通过。', () => { this.strategy = 'any'; this.emitChange() }),
							this.renderChoice(h, this.strategy === 'all', '会签', '所有审批人都同意后才通过。', () => { this.strategy = 'all'; this.emitChange() }),
						]),
					]),

					h('div', { class: 'webhooks-config-section' }, [
						h('div', { class: 'webhooks-section-heading' }, [h('h4', { class: 'webhooks-section-title' }, '文件夹审批范围')]),
						h('select', {
							class: 'webhooks-select-wide',
							domProps: { value: this.pathMode },
							on: { change: (event) => { this.pathMode = event.target.value; this.emitChange() } },
						}, [
							h('option', { attrs: { value: 'all' } }, '全部文件夹'),
							h('option', { attrs: { value: 'include' } }, '仅指定文件夹内触发审批'),
							h('option', { attrs: { value: 'exclude' } }, '排除指定文件夹，其余触发审批'),
						]),
						this.pathMode === 'all' ? h('p', { class: 'webhooks-hint' }, '该审批规则会应用到所有文件夹。') : h('div', { style: { marginTop: '12px' } }, [
							this.renderChipList(h, this.paths, this.removeFolder, '尚未选择文件夹。'),
							h('div', { class: 'webhooks-inline-form' }, [
								h('input', {
									attrs: { type: 'text', placeholder: '搜索当前账号可访问的文件夹' },
									domProps: { value: this.folderTerm },
									on: {
										input: (event) => { this.folderTerm = event.target.value },
										keyup: (event) => { if (event.key === 'Enter') this.searchFolders() },
									},
								}),
								h('button', { attrs: { type: 'button' }, class: 'webhooks-small-button primary', on: { click: this.searchFolders } }, this.folderLoading ? '搜索中…' : '搜索'),
							]),
							this.renderSearchResults(h, this.folderResults, (folder) => this.addFolder(folder.path), (folder) => folder.path),
						]),
					]),
				]),
			])
		},
	}


	const SharePathCheckOptions = {
		name: 'SharePathCheckOptions',
		props: {
			value: {
				default: '[]',
				type: String,
			},
		},
		data() {
			return {
				paths: normalizeList(this.value),
				folderTerm: '',
				folderResults: [],
				folderLoading: false,
			}
		},
		methods: {
			emitChange() {
				this.$emit('input', JSON.stringify(normalizeList(this.paths)))
			},
			addFolder(path) {
				path = String(path || '').trim()
				if (path !== '' && this.paths.indexOf(path) === -1) {
					this.paths.push(path)
					this.emitChange()
				}
			},
			removeFolder(path) {
				this.paths = this.paths.filter(function(item) { return item !== path })
				this.emitChange()
			},
			searchFolders() {
				this.folderLoading = true
				const url = OC.generateUrl('/apps/webhooks/share-approval/folders') + '?term=' + encodeURIComponent(this.folderTerm)
				fetch(url, { credentials: 'same-origin', headers: csrfHeaders() })
					.then(function(response) { return response.json() })
					.then((data) => {
						this.folderResults = data.folders || []
					})
					.catch(() => {
						this.folderResults = []
					})
					.finally(() => {
						this.folderLoading = false
					})
			},
			renderFolderTags(h) {
				if (this.paths.length === 0) {
					return h('p', { style: hintStyle }, '请至少选择一个文件夹路径。')
				}
				return h('div', this.paths.map((path) => h('span', { style: tagStyle }, [
					h('span', path),
					h('button', {
						attrs: { type: 'button', title: '移除' },
						on: { click: () => this.removeFolder(path) },
						style: style({ border: '0', background: 'transparent', cursor: 'pointer', fontWeight: '700' }),
					}, '×'),
				])))
			},
		},
		render(h) {
			return h('div', { class: 'webhooks-share-path-check', style: { minWidth: '320px' } }, [
				this.renderFolderTags(h),
				h('div', { style: { display: 'flex', gap: '8px', marginTop: '8px' } }, [
					h('input', {
						attrs: { type: 'text', placeholder: '搜索当前账号可访问的文件夹' },
						domProps: { value: this.folderTerm },
						on: {
							input: (event) => { this.folderTerm = event.target.value },
							keyup: (event) => { if (event.key === 'Enter') this.searchFolders() },
						},
						style: style({ flex: '1', minWidth: '0' }),
					}),
					h('button', {
						attrs: { type: 'button' },
						on: { click: this.searchFolders },
						style: commonButtonStyle,
					}, this.folderLoading ? '搜索中…' : '搜索'),
				]),
				this.folderResults.length ? h('ul', { style: resultListStyle }, this.folderResults.map((folder) => h('li', {
					style: { display: 'flex', justifyContent: 'space-between', gap: '8px', alignItems: 'center', padding: '4px 2px' },
				}, [
					h('span', folder.path),
					h('button', {
						attrs: { type: 'button' },
						on: { click: () => this.addFolder(folder.path) },
						style: commonButtonStyle,
					}, '添加'),
				]))) : null,
			])
		},
	}


	const TagCheckOptions = {
		name: 'ApprovalSystemTagsCheckOptions',
		props: { value: { default: '', type: String } },
		data() { return { tagTerm: '', tagResults: [], tagLoading: false } },
		methods: {
			selectTag(tag) { this.$emit('input', String(tag.id || '')) },
			searchTags() {
				this.tagLoading = true
				const url = OC.generateUrl('/apps/webhooks/share-approval/tags') + '?term=' + encodeURIComponent(this.tagTerm)
				fetch(url, { credentials: 'same-origin', headers: csrfHeaders() })
					.then(function(response) { return response.json() })
					.then((data) => { this.tagResults = data.tags || [] })
					.catch(() => { this.tagResults = [] })
					.finally(() => { this.tagLoading = false })
			},
		},
		render(h) {
			return h('div', { style: { minWidth: '320px' } }, [
				h('input', {
					attrs: { type: 'text', placeholder: '搜索系统标签，或直接输入标签 ID' },
					domProps: { value: this.tagTerm || this.value },
					on: {
						input: (event) => { this.tagTerm = event.target.value; this.$emit('input', event.target.value) },
						keyup: (event) => { if (event.key === 'Enter') this.searchTags() },
					},
					style: style({ width: '100%' }),
				}),
				h('button', { attrs: { type: 'button' }, on: { click: this.searchTags }, style: Object.assign({}, commonButtonStyle, { marginTop: '6px' }) }, this.tagLoading ? '搜索中…' : '搜索标签'),
				this.tagResults.length ? h('ul', { style: resultListStyle }, this.tagResults.map((tag) => h('li', { style: { display: 'flex', justifyContent: 'space-between', gap: '8px', alignItems: 'center', padding: '4px 2px' } }, [
					h('span', tag.label || tag.name || tag.id),
					h('button', { attrs: { type: 'button' }, on: { click: () => this.selectTag(tag) }, style: commonButtonStyle }, '选择'),
				]))) : null,
			])
		},
	}

	window.OCA.WorkflowEngine.registerOperator({
		id: 'OCA\\Webhooks\\Flow\\Operation',
		color: '#0082c9',
		operation: JSON.stringify({ url: '' }),
		options: WebhookOptions,
	})

	window.OCA.WorkflowEngine.registerOperator({
		id: 'OCA\\Webhooks\\Flow\\ShareApprovalOperation',
		color: '#46ba61',
		operation: JSON.stringify(defaultShareApproval),
		options: ShareApprovalOptions,
	})

	if (typeof window.OCA.WorkflowEngine.registerCheck === 'function') {
		try {
			window.OCA.WorkflowEngine.registerCheck({
				id: 'OCA\\Webhooks\\Flow\\Check\\SharePathCheck',
				class: 'OCA\\Webhooks\\Flow\\Check\\SharePathCheck',
				name: '分享文件夹路径',
				description: '根据被分享文件所在文件夹路径进行匹配',
				operators: {
					'in': '在指定文件夹内',
					'!in': '不在指定文件夹内',
				},
				placeholder: '选择文件夹',
				options: SharePathCheckOptions,
				component: SharePathCheckOptions,
			})
		} catch (e) {
			// Some older Workflow Engine builds do not expose custom check UI registration.
		}
	}

	if (typeof window.OCA.WorkflowEngine.registerCheck === 'function') {
		try {
			window.OCA.WorkflowEngine.registerCheck({
				id: 'OCA\\Webhooks\\Flow\\Check\\ApprovalFileNameCheck',
				class: 'OCA\\Webhooks\\Flow\\Check\\ApprovalFileNameCheck',
				name: '文件名',
				description: '根据文件名筛选文件审批规则',
				operators: { 'is': '等于', '!is': '不等于', 'contains': '包含', '!contains': '不包含', 'matches': '匹配正则', '!matches': '不匹配正则' },
				placeholder: '请输入文件名或匹配条件',
			})
			window.OCA.WorkflowEngine.registerCheck({
				id: 'OCA\\Webhooks\\Flow\\Check\\ApprovalFileMimeTypeCheck',
				class: 'OCA\\Webhooks\\Flow\\Check\\ApprovalFileMimeTypeCheck',
				name: '文件类型',
				description: '根据 MIME Type 筛选文件审批规则',
				operators: { 'is': '等于', '!is': '不等于', 'contains': '包含', '!contains': '不包含' },
				placeholder: '例如 application/pdf 或 image/',
			})
			window.OCA.WorkflowEngine.registerCheck({
				id: 'OCA\\Webhooks\\Flow\\Check\\ApprovalSystemTagsCheck',
				class: 'OCA\\Webhooks\\Flow\\Check\\ApprovalSystemTagsCheck',
				name: '文件系统标签',
				description: '根据 Nextcloud 文件系统标签筛选文件审批规则',
				operators: { 'is': '包含标签', '!is': '不包含标签' },
				placeholder: '选择系统标签',
				options: TagCheckOptions,
				component: TagCheckOptions,
			})
		} catch (e) {}
	}
})()
