<!--
	Матрица «приложение × группа».

	Логика перенесена из reference/frontend/ant-design-portal/src/pages/Admin/AccessControl/MatrixPage.tsx
	по плану docs/porting-plan.md: построение колонок по субъектам доступа,
	локальный учёт изменений и подсветка изменённых ячеек.

	Изменения готовятся локально и применяются кнопкой «Применить» — как в
	инструментах разметки дисков: сначала расставляем галочки, потом фиксируем.
-->
<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import AppDetailsPanel from './AppDetailsPanel.vue'
import { fetchMatrix, saveAppAccess, saveGroupOrder } from '../services/matrixService'
import {
	applyColumnOperation,
	capitalizeCategory,
	effectiveState,
	isAllChecked as isAllCheckedState,
	isGroupChecked,
	sameState,
	serverState,
	toggleGroupState,
	toggleMasterState,
} from '../lib/accessState'
import type { AccessState, MatrixApp, MatrixGroup } from '../types/matrix'

interface CategoryBlock {
	key: string
	label: string
	apps: MatrixApp[]
}

/** Порядок категорий приложений в матрице */
const CATEGORY_ORDER = [
	'tools', 'security', 'customization', 'files', 'integration', 'monitoring',
	'multimedia', 'office', 'organization', 'social', 'games', 'ai', 'workflow', 'search', 'dashboard',
]

const loading = ref(true)
const saving = ref(false)
const applying = ref(false)
const error = ref<string | null>(null)
const apps = ref<MatrixApp[]>([])
const groups = ref<MatrixGroup[]>([])
const currentUserGroups = ref<string[]>([])
const filter = ref('')
const hoveredColumn = ref<number | null>(null)
const detailsApp = ref<MatrixApp | null>(null)
const draggedGroup = ref<string | null>(null)
const dragTargetGroup = ref<string | null>(null)
const scrollElement = ref<HTMLElement | null>(null)
const scrollHeight = ref<number>(0)

/** Несохранённые изменения: идентификатор приложения → состояние доступа */
const drafts = ref<Record<string, AccessState>>({})
/** Скрытые группы (столбцы скрыты из вида) */
const hiddenGroupIds = ref<string[]>([])
/** Выбранные столбцы для массовых операций */
const selectedColumnIds = ref<string[]>([])
/** Опорная точка выделения — от неё считаются диапазоны по Shift */
const selectionAnchor = ref<string | null>(null)
/** Клик после перетаскивания не должен менять выделение */
const suppressClick = ref(false)
/** Свёрнутые категории приложений */
const collapsedCategories = ref<string[]>([])
/** Показывать ли панель управления видимыми группами */
const groupsPanelOpen = ref(false)
/** Элемент панели групп — для закрытия по клику вне меню */
const groupsControl = ref<HTMLElement | null>(null)
/** Предупреждение: изменения оставят администратора без доступа */
const riskyChanges = ref<string[]>([])
/** Прогресс применения изменений */
const applyProgress = ref<{ done: number, total: number } | null>(null)

const allGroupIds = computed(() => groups.value.map((group) => group.id))
const visibleGroups = computed(() => groups.value.filter((group) => !hiddenGroupIds.value.includes(group.id)))
const changedCount = computed(() => Object.keys(drafts.value).length)

const filteredApps = computed(() => {
	const query = filter.value.trim().toLowerCase()
	if (query === '') {
		return apps.value
	}

	return apps.value.filter((app) => {
		return app.name.toLowerCase().includes(query) || app.id.toLowerCase().includes(query)
	})
})

const restrictedCount = computed(() => {
	return apps.value.filter((app) => !app.unrestricted).length
})

/**
 * Приложения, сгруппированные по категориям.
 */
const categoryBlocks = computed<CategoryBlock[]>(() => {
	const blocks = new Map<string, MatrixApp[]>()
	for (const app of filteredApps.value) {
		const key = app.categories.length > 0 ? app.categories[0] : ''
		if (!blocks.has(key)) {
			blocks.set(key, [])
		}
		blocks.get(key)?.push(app)
	}

	return Array.from(blocks.entries())
		.map(([key, blockApps]) => ({
			key,
			label: key === '' ? t('access_matrix', 'Без категории') : capitalizeCategory(t('access_matrix', key)),
			apps: blockApps,
		}))
		.sort((a, b) => {
			const indexA = CATEGORY_ORDER.indexOf(a.key)
			const indexB = CATEGORY_ORDER.indexOf(b.key)
			if (indexA === -1 && indexB === -1) {
				return a.label.localeCompare(b.label)
			}
			if (indexA === -1) {
				return 1
			}
			if (indexB === -1) {
				return -1
			}
			return indexA - indexB
		})
})

/**
 * Счётчик доступа для группы: скольким приложениям из выборки группа доступна.
 */
function groupAccessCount(groupId: string): number {
	return filteredApps.value.filter((app) => isChecked(app, groupId)).length
}

/**
 * Состояние доступа к приложению: сохранённые изменения имеют приоритет.
 */
function stateOf(app: MatrixApp): AccessState {
	return effectiveState(app, allGroupIds.value, drafts.value)
}

/**
 * Отмечена ли группа у приложения.
 *
 * У приложений с несовместимым поведением (restrictionWarning) ограничение по
 * группам не работает: доступ либо есть у всех, либо нет ни у кого. Поэтому
 * все группы отражают состояние главной галочки «Все».
 */
function isChecked(app: MatrixApp, groupId: string): boolean {
	if (app.restrictionWarning) {
		return isAllChecked(app)
	}

	return isGroupChecked(stateOf(app), groupId)
}

/**
 * Мастер-галочка строки: включена, когда доступ выдан всем группам.
 */
function isAllChecked(app: MatrixApp): boolean {
	const state = stateOf(app)
	if (app.restrictionWarning) {
		return state.unrestricted
	}

	return isAllCheckedState(state, allGroupIds.value)
}

function isChanged(app: MatrixApp): boolean {
	return drafts.value[app.id] !== undefined
}

/**
 * Запомнить подготовленное изменение.
 *
 * Если состояние вернулось к исходному, черновик снимается — тогда счётчик
 * изменений обнуляется сам, без кнопки «Сбросить».
 */
function setDraft(app: MatrixApp, state: AccessState): void {
	if (sameState(state, serverState(app, allGroupIds.value))) {
		if (drafts.value[app.id] !== undefined) {
			const next = { ...drafts.value }
			delete next[app.id]
			drafts.value = next
		}
		return
	}

	drafts.value = { ...drafts.value, [app.id]: state }
}

function toggleMaster(app: MatrixApp, checked: boolean): void {
	setDraft(app, toggleMasterState(stateOf(app), allGroupIds.value, checked))
}

/**
 * Переключение группы.
 *
 * У приложений с несовместимым поведением любая галочка группы работает как
 * главная галочка «Все»: ограничение по группам для них не работает.
 */
function toggleGroup(app: MatrixApp, groupId: string, checked: boolean): void {
	if (app.restrictionWarning) {
		toggleMaster(app, !isAllChecked(app))
		return
	}

	setDraft(app, toggleGroupState(stateOf(app), allGroupIds.value, groupId, checked))
}

/**
 * Доступ администратора после применения состояния.
 */
function keepsAdminAccess(state: AccessState): boolean {
	if (state.unrestricted) {
		return true
	}

	return state.groups.some((gid) => currentUserGroups.value.includes(gid))
}

function adminHasAccessNow(app: MatrixApp): boolean {
	return app.unrestricted || app.groups.some((gid) => currentUserGroups.value.includes(gid))
}

/**
 * Высота области матрицы подстраивается под окно браузера: таблица занимает
 * всё пространство до нижнего края окна и прокручивается внутри себя.
 */
function updateScrollHeight(): void {
	const element = scrollElement.value
	if (element === null) {
		return
	}

	const top = element.getBoundingClientRect().top
	scrollHeight.value = Math.max(280, Math.round(window.innerHeight - top - 16))
}

async function load(): Promise<void> {
	loading.value = true
	error.value = null

	try {
		const matrix = await fetchMatrix()
		apps.value = matrix.apps
		groups.value = matrix.groups
		currentUserGroups.value = matrix.currentUserGroups ?? []
	} catch (exception) {
		error.value = t('access_matrix', 'Failed to load the access matrix.')
	} finally {
		loading.value = false
	}
}

/**
 * Применение подготовленных изменений.
 *
 * Запросы идут последовательно: параллельная запись в appconfig одних и тех же
 * приложений приводит к конкуренции за сессию Nextcloud и к невоспроизводимым
 * ошибкам. Показываем прогресс и имя приложения, на котором произошла ошибка.
 */
async function applyChanges(): Promise<void> {
	const entries = Object.entries(drafts.value)
	if (entries.length === 0 || applying.value) {
		return
	}

	riskyChanges.value = entries
		.filter(([id, state]) => {
			const app = apps.value.find((item) => item.id === id)
			return app !== undefined && adminHasAccessNow(app) && !keepsAdminAccess(state)
		})
		.map(([id]) => apps.value.find((item) => item.id === id)?.name ?? id)

	if (riskyChanges.value.length > 0) {
		return
	}

	await doApply(entries)
}

/**
 * Текст ошибки ответа OCS: сервер возвращает {ocs: {data: {message}}}.
 */
function errorMessage(exception: unknown): string {
	const response = (exception as { response?: { data?: { ocs?: { data?: { message?: string } } } } })?.response
	return response?.data?.ocs?.data?.message ?? ''
}

async function doApply(entries: [string, AccessState][]): Promise<void> {
	applying.value = true
	error.value = null
	applyProgress.value = { done: 0, total: entries.length }

	const failed: { id: string, message: string }[] = []
	for (const [id, state] of entries) {
		try {
			await saveAppAccess(id, state.unrestricted, state.groups)
		} catch (exception) {
			failed.push({ id, message: errorMessage(exception) })
		}
		applyProgress.value = { done: (applyProgress.value?.done ?? 0) + 1, total: entries.length }
	}

	if (failed.length === 0) {
		drafts.value = {}
		riskyChanges.value = []
		await load()
	} else {
		// Несохранённые черновики оставляем, чтобы можно было повторить.
		const remaining = { ...drafts.value }
		for (const item of failed) {
			remaining[item.id] = drafts.value[item.id]
		}
		drafts.value = remaining
		error.value = t('access_matrix', 'Failed to save access for: {apps}. {reason}', {
			apps: failed.map((item) => apps.value.find((app) => app.id === item.id)?.name ?? item.id).join(', '),
			reason: failed[0].message,
		})
	}

	applying.value = false
	applyProgress.value = null
}

function discardChanges(): void {
	drafts.value = {}
	riskyChanges.value = []
}

/**
 * Массовая операция по выбранным столбцам.
 */
function applyToSelectedColumns(grant: boolean): void {
	const selected = selectedColumnIds.value
	if (selected.length === 0) {
		return
	}

	const next = { ...drafts.value }
	for (const app of filteredApps.value) {
		const state = drafts.value[app.id] ?? serverState(app, allGroupIds.value)
		next[app.id] = applyColumnOperation(state, allGroupIds.value, selected, grant)
	}
	drafts.value = next
}

/**
 * Снять выделение столбцов вместе с опорной точкой.
 */
function clearColumnSelection(): void {
	selectedColumnIds.value = []
	selectionAnchor.value = null
}

/**
 * Выбор столбца.
 *
 * Обычный клик — выделяет один столбец и запоминает его как опорную точку.
 * Shift+клик — выделяет диапазон от опорной точки до кликнутого; опорная точка
 * при этом не меняется, поэтому последовательные Shift+клики всегда считаются
 * от неё.
 * Ctrl+клик — добавляет или убирает отдельный столбец, не трогая остальные.
 */
function selectColumn(group: MatrixGroup, event: MouseEvent): void {
	if (suppressClick.value) {
		return
	}

	const visible = visibleGroups.value
	const index = visible.findIndex((item) => item.id === group.id)
	if (index === -1) {
		return
	}

	if (event.ctrlKey || event.metaKey) {
		const current = new Set(selectedColumnIds.value)
		if (current.has(group.id)) {
			current.delete(group.id)
		} else {
			current.add(group.id)
		}
		selectedColumnIds.value = visible.filter((item) => current.has(item.id)).map((item) => item.id)
		return
	}

	if (event.shiftKey && selectionAnchor.value !== null) {
		const anchorIndex = visible.findIndex((item) => item.id === selectionAnchor.value)
		if (anchorIndex !== -1) {
			const from = Math.min(anchorIndex, index)
			const to = Math.max(anchorIndex, index)
			selectedColumnIds.value = visible.slice(from, to + 1).map((item) => item.id)
			return
		}
	}

	if (selectedColumnIds.value.includes(group.id)) {
		// Повторный клик по уже выделенной группе снимает выделение.
		clearColumnSelection()
		return
	}

	selectedColumnIds.value = [group.id]
	selectionAnchor.value = group.id
}

function toggleGroupVisibility(groupId: string): void {
	hiddenGroupIds.value = hiddenGroupIds.value.includes(groupId)
		? hiddenGroupIds.value.filter((id) => id !== groupId)
		: [...hiddenGroupIds.value, groupId]
}

/**
 * Меню видимых групп закрывается кликом вне него.
 */
function onDocumentClick(event: MouseEvent): void {
	const target = event.target as Node | null
	if (target !== null && groupsControl.value?.contains(target) === true) {
		return
	}
	groupsPanelOpen.value = false
}

watch(groupsPanelOpen, (open) => {
	if (open) {
		// Слушатель добавляется после текущего клика, иначе меню закрылось бы сразу.
		setTimeout(() => document.addEventListener('click', onDocumentClick), 0)
	} else {
		document.removeEventListener('click', onDocumentClick)
	}
})

function toggleCategory(key: string): void {
	collapsedCategories.value = collapsedCategories.value.includes(key)
		? collapsedCategories.value.filter((item) => item !== key)
		: [...collapsedCategories.value, key]
}

/**
 * Перетаскивание заголовка группы: меняет порядок колонок и сохраняет его.
 */
async function onDragStart(group: MatrixGroup): Promise<void> {
	draggedGroup.value = group.id
}

async function onDragOver(group: MatrixGroup): Promise<void> {
	if (draggedGroup.value === null || draggedGroup.value === group.id) {
		return
	}
	dragTargetGroup.value = group.id
}

/**
 * После перетаскивания браузер присылает click по заголовку — его нужно
 * подавить, иначе выделение столбцов сбросится.
 */
function onDragEnd(): void {
	draggedGroup.value = null
	dragTargetGroup.value = null
	suppressClick.value = true
	setTimeout(() => {
		suppressClick.value = false
	}, 150)
}

async function onDrop(group: MatrixGroup): Promise<void> {
	const draggedId = draggedGroup.value
	draggedGroup.value = null
	dragTargetGroup.value = null

	if (draggedId === null || draggedId === group.id) {
		return
	}

	const from = groups.value.findIndex((item) => item.id === draggedId)
	const to = groups.value.findIndex((item) => item.id === group.id)
	if (from === -1 || to === -1) {
		return
	}

	const reordered = [...groups.value]
	reordered.splice(from, 1)
	reordered.splice(to, 0, groups.value[from])
	groups.value = reordered

	try {
		await saveGroupOrder(reordered.map((item) => item.id))
	} catch (exception) {
		error.value = t('access_matrix', 'Failed to save the group order.')
	}
}

onMounted(async () => {
	await load()
	await nextTick()
	updateScrollHeight()
	window.addEventListener('resize', updateScrollHeight)
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', updateScrollHeight)
})
</script>

<template>
	<div class="access-matrix">
		<NcNoteCard v-if="error" type="error" class="access-matrix__error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="access-matrix__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcNoteCard v-else-if="apps.length === 0" type="info">
			{{ t('access_matrix', 'No applications whose access can be restricted to groups.') }}
		</NcNoteCard>

		<template v-else>
			<div class="access-matrix__toolbar">
				<input
					v-model="filter"
					class="access-matrix__search"
					type="search"
					:placeholder="t('access_matrix', 'Filter applications')"
					:aria-label="t('access_matrix', 'Filter applications')">
				<div ref="groupsControl" class="access-matrix__groups-control">
					<NcButton variant="tertiary" @click="groupsPanelOpen = !groupsPanelOpen">
						{{ t('access_matrix', 'Groups') }}
					</NcButton>
					<div v-if="groupsPanelOpen" class="access-matrix__groups-panel">
						<div class="access-matrix__groups-panel-header">
							<p class="access-matrix__groups-panel-title">
								{{ t('access_matrix', 'Visible groups') }}
							</p>
							<NcButton variant="tertiary" size="small" @click="groupsPanelOpen = false">
								{{ t('access_matrix', 'Close') }}
							</NcButton>
						</div>
						<div
							v-for="group in groups"
							:key="group.id"
							class="access-matrix__groups-panel-item">
							<NcCheckboxRadioSwitch
								:model-value="!hiddenGroupIds.includes(group.id)"
								@update:model-value="toggleGroupVisibility(group.id)" />
							<button
								type="button"
								class="access-matrix__groups-panel-name"
								@click="toggleGroupVisibility(group.id)">
								{{ group.name }}
							</button>
						</div>
					</div>
				</div>

				<div v-if="selectedColumnIds.length > 0" class="access-matrix__bulk">
					<span class="access-matrix__bulk-label">
						{{ t('access_matrix', 'Selected columns: {count}', { count: selectedColumnIds.length }) }}
					</span>
					<NcButton variant="secondary" size="small" @click="applyToSelectedColumns(true)">
						{{ t('access_matrix', 'Grant to all') }}
					</NcButton>
					<NcButton variant="secondary" size="small" @click="applyToSelectedColumns(false)">
						{{ t('access_matrix', 'Revoke from all') }}
					</NcButton>
					<NcButton variant="tertiary" size="small" @click="clearColumnSelection">
						{{ t('access_matrix', 'Clear selection') }}
					</NcButton>
				</div>

				<div class="access-matrix__actions">
					<NcButton
						v-if="changedCount > 0"
						variant="tertiary"
						size="small"
						:disabled="applying"
						@click="discardChanges">
						{{ t('access_matrix', 'Discard') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="changedCount === 0 || applying"
						@click="applyChanges">
						{{ applying && applyProgress !== null
							? t('access_matrix', 'Applying… ({done} of {total})', { done: applyProgress.done, total: applyProgress.total })
							: t('access_matrix', 'Apply ({count})', { count: changedCount }) }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard v-if="riskyChanges.length > 0" type="warning" class="access-matrix__warning">
				<p>
					{{ t('access_matrix', 'These changes will remove your own access to the applications: {apps}.', {
						apps: riskyChanges.join(', '),
					}) }}
				</p>
				<div class="access-matrix__warning-actions">
					<NcButton variant="tertiary" size="small" @click="riskyChanges = []">
						{{ t('access_matrix', 'Cancel') }}
					</NcButton>
					<NcButton variant="warning" size="small" @click="doApply(Object.entries(drafts))">
						{{ t('access_matrix', 'Apply anyway') }}
					</NcButton>
				</div>
			</NcNoteCard>

			<div ref="scrollElement" class="access-matrix__scroll" :style="{ height: `${scrollHeight}px` }">
				<div class="access-matrix__inner">
					<table class="access-matrix__table">
						<thead>
							<tr>
								<th scope="col" class="access-matrix__app-column">
									{{ t('access_matrix', 'Application') }} ({{ filteredApps.length }})
								</th>
								<th scope="col" class="access-matrix__all-column">
									<span :title="t('access_matrix', 'Access for all groups')">
										{{ t('access_matrix', 'All') }}
									</span>
								</th>
								<th
									v-for="(group, index) in visibleGroups"
									:key="group.id"
									scope="col"
									class="access-matrix__group-column"
									:class="{
										'access-matrix__head--column': hoveredColumn === index,
										'access-matrix__head--dragging': draggedGroup === group.id,
										'access-matrix__head--target': dragTargetGroup === group.id,
										'access-matrix__head--selected': selectedColumnIds.includes(group.id),
									}"
									draggable="true"
									:title="t('access_matrix', 'Drag to reorder, click to select the column')"
									@click="selectColumn(group, $event)"
									@dragstart="onDragStart(group)"
									@dragover.prevent="onDragOver(group)"
									@drop.prevent="onDrop(group)"
									@dragend="onDragEnd">
									<span class="access-matrix__group-name">{{ group.name }}</span>
									<span class="access-matrix__group-count">
										{{ t('access_matrix', '{count} of {total}', {
											count: groupAccessCount(group.id),
											total: filteredApps.length,
										}) }}
									</span>
								</th>
							</tr>
						</thead>
						<tbody v-for="block in categoryBlocks" :key="block.key">
							<tr class="access-matrix__category-row">
								<th scope="rowgroup" class="access-matrix__app-column access-matrix__category-label">
									<button
										type="button"
										class="access-matrix__category-button"
										@click="toggleCategory(block.key)">
										{{ block.label }} ({{ block.apps.length }})
									</button>
								</th>
								<th class="access-matrix__category-rest" :colspan="visibleGroups.length + 1" />
							</tr>
							<tr
								v-for="app in block.apps"
								v-show="!collapsedCategories.includes(block.key)"
								:key="app.id"
								:class="{ 'access-matrix__row--changed': isChanged(app) }">
								<th scope="row" class="access-matrix__app-column">
									<button
										type="button"
										class="access-matrix__app-name"
										:title="app.id"
										@click="detailsApp = app">
										{{ app.name }}
									</button>
									<span
										v-if="app.restrictionWarning"
										class="access-matrix__app-warning"
										:title="t('access_matrix', 'This application treats a group restriction as a complete shutdown and hides its interface from everyone, including administrators. Any checkbox in a group works like the “All” switch for it.')">
										!
									</span>
									<span
										class="access-matrix__app-state"
										:class="{ 'access-matrix__app-state--restricted': !isAllChecked(app) }">
										{{ isAllChecked(app)
											? t('access_matrix', 'all')
											: t('access_matrix', '{count} of {total}', { count: stateOf(app).groups.length, total: allGroupIds.length }) }}
									</span>
								</th>
								<td class="access-matrix__all-column">
									<span class="access-matrix__check">
										<NcCheckboxRadioSwitch
											:model-value="isAllChecked(app)"
											:disabled="applying"
											:aria-label="t('access_matrix', 'Access for all groups: {app}', { app: app.name })"
											@update:model-value="toggleMaster(app, $event)" />
									</span>
								</td>
								<td
									v-for="(group, index) in visibleGroups"
									:key="group.id"
									class="access-matrix__cell"
									:class="{ 'access-matrix__cell--column': hoveredColumn === index }"
									@mouseenter="hoveredColumn = index"
									@mouseleave="hoveredColumn = null">
									<span class="access-matrix__check">
										<NcCheckboxRadioSwitch
											:model-value="isChecked(app, group.id)"
											:disabled="applying"
											:aria-label="t('access_matrix', 'Access for group {group}: {app}', { group: group.name, app: app.name })"
											@update:model-value="toggleGroup(app, group.id, $event)" />
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</template>

		<AppDetailsPanel
			v-model:app="detailsApp"
			:groups="groups"
			:state="detailsApp ? stateOf(detailsApp) : null" />
	</div>
</template>

<style scoped>
.access-matrix__error {
	margin-bottom: 12px;
}

.access-matrix__loading {
	display: flex;
	justify-content: center;
	padding: 24px;
}

.access-matrix__toolbar {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 12px;
	/* Панель фильтра выровнена по левому краю таблицы, справа оставлен отступ
	   от края окна, чтобы кнопки не прилегали вплотную. */
	padding-inline: 24px 16px;
}

@media (max-width: 900px) {
	.access-matrix__toolbar {
		padding-inline: 16px;
	}
}

.access-matrix__search {
	min-width: 240px;
	padding: 6px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.access-matrix__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.access-matrix__groups-control {
	position: relative;
}

.access-matrix__groups-panel {
	position: absolute;
	top: 100%;
	left: 0;
	z-index: 20;
	min-width: 320px;
	max-width: 420px;
	max-height: 320px;
	overflow-y: auto;
	padding: 12px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.access-matrix__groups-panel-title {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.access-matrix__groups-panel-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 8px;
}

.access-matrix__groups-panel-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 2px 0;
}

.access-matrix__groups-panel-name {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	color: var(--color-main-text);
	cursor: pointer;
	text-align: left;
}

.access-matrix__groups-panel-name:hover {
	text-decoration: underline;
}

.access-matrix__bulk {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 12px;
	border-radius: var(--border-radius-element);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.access-matrix__bulk-label {
	font-weight: bold;
}

.access-matrix__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-inline-start: auto;
}

.access-matrix__warning {
	margin-bottom: 12px;
}

.access-matrix__warning-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

/* Отступ слева: матрица не прилипает к краю панели настроек. */
.access-matrix__inner {
	padding-inline-start: 24px;
}

@media (max-width: 900px) {
	.access-matrix__inner {
		padding-inline-start: 16px;
	}
}

.access-matrix__scroll {
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.access-matrix__table {
	border-collapse: separate;
	border-spacing: 0;
	width: 100%;
}

.access-matrix__table th,
.access-matrix__table td {
	padding: 6px 14px;
	white-space: nowrap;
}

/* Шапка прилипает к верху при вертикальной прокрутке. */
.access-matrix__table thead th {
	position: sticky;
	top: 0;
	z-index: 2;
	background-color: var(--color-main-background);
	border-bottom: 1px solid var(--color-border-dark);
	text-align: center;
	font-weight: bold;
}

.access-matrix__table tbody td,
.access-matrix__table tbody th {
	border-bottom: 1px solid var(--color-border);
}

.access-matrix__category-row th {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	text-align: left;
	font-weight: bold;
	padding: 6px 14px;
}

/* Название категории закреплено слева вместе с названием приложения. */
.access-matrix__category-label {
	position: sticky;
	left: 0;
	z-index: 2;
}

.access-matrix__category-button {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	font-weight: bold;
	color: inherit;
	cursor: pointer;
}

/* Название приложения — первая колонка, прилипает слева при горизонтальной прокрутке. */
.access-matrix__app-column {
	position: sticky;
	left: 0;
	z-index: 1;
	background-color: var(--color-main-background);
	text-align: left;
	width: 280px;
	min-width: 280px;
	max-width: 280px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.access-matrix__table thead .access-matrix__app-column {
	z-index: 3;
}

/* Колонка «Все» закреплена сразу за названием приложения. */
.access-matrix__all-column {
	position: sticky;
	left: 280px;
	z-index: 1;
	background-color: var(--color-main-background);
	text-align: center;
	width: 104px;
	min-width: 104px;
}

.access-matrix__table thead .access-matrix__all-column {
	z-index: 3;
}

.access-matrix__app-name {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	font-weight: bold;
	/* Стандартный цвет текста темы — название не должно выглядеть как ссылка. */
	color: var(--color-main-text);
	cursor: pointer;
	text-align: left;
}

.access-matrix__app-name:hover,
.access-matrix__app-name:focus-visible {
	text-decoration: underline;
}

.access-matrix__app-warning {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 16px;
	height: 16px;
	margin-inline-start: 6px;
	border-radius: 50%;
	background-color: var(--color-warning);
	color: var(--color-primary-text);
	font-size: 0.75em;
	font-weight: bold;
	cursor: help;
}

.access-matrix__app-state {
	display: inline-block;
	margin-inline-start: 8px;
	padding: 0 8px;
	border-radius: 10px;
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
.access-matrix__app-state--restricted {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.access-matrix__cell {
	text-align: center;
	min-width: 104px;
}

/* Галочка центрируется внутри ячейки обёрткой: сам <td> должен оставаться
   table-cell, иначе ломается раскладка таблицы. */
.access-matrix__check {
	display: flex;
	align-items: center;
	justify-content: center;
}

.access-matrix__group-column {
	min-width: 104px;
	cursor: grab;
	user-select: none;
}

.access-matrix__group-name {
	display: block;
}

.access-matrix__group-count {
	display: block;
	font-size: 0.8em;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.access-matrix__table thead th.access-matrix__head--dragging {
	opacity: 0.5;
}

.access-matrix__table thead th.access-matrix__head--target {
	box-shadow: inset 2px 0 0 var(--color-primary-element);
}

.access-matrix__table thead th.access-matrix__head--selected {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

/* Подсветка строки и столбца при наведении. */
.access-matrix__table tbody tr:hover > td {
	background-color: var(--color-background-hover);
}

.access-matrix__table tbody tr > td.access-matrix__cell--column,
.access-matrix__table tbody tr:hover > td.access-matrix__cell--column {
	background-color: var(--color-primary-element-light);
}

/* Строка с подготовленными изменениями. */
.access-matrix__table tbody tr.access-matrix__row--changed > th:first-child {
	box-shadow: inset 3px 0 0 var(--color-warning);
}
</style>
