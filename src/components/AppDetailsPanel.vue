<!--
	Панель с подробной информацией о приложении (по аналогии с панелью
	«Активные приложения» в настройках Nextcloud).

	Открывается кликом по названию приложения в матрице. Данные берутся из
	матрицы — отдельного запроса не делается.
-->
<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

import type { AccessState, MatrixApp, MatrixGroup } from '../types/matrix'

const props = defineProps<{
	app: MatrixApp | null
	groups: MatrixGroup[]
	/** Текущее состояние доступа с учётом подготовленных, но не применённых изменений */
	state: AccessState | null
}>()

const emit = defineEmits<{
	'update:app': [MatrixApp | null]
}>()

type Tab = 'description' | 'properties' | 'access'

const tab = ref<Tab>('description')

const open = computed({
	get: () => props.app !== null,
	set: (value: boolean) => {
		if (!value) {
			emit('update:app', null)
		}
	},
})

const groupNames = computed(() => {
	if (props.state === null) {
		return []
	}

	return props.state.groups.map((gid) => {
		return props.groups.find((group) => group.id === gid)?.name ?? gid
	})
})

/**
 * Панель закрывается по Escape — как и остальные всплывающие элементы Nextcloud.
 */
function onKeydown(event: KeyboardEvent): void {
	if (event.key === 'Escape' && open.value) {
		open.value = false
	}
}

onMounted(() => {
	document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
	document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
	<Transition name="fade">
		<div v-if="open" class="app-details__backdrop" @click="open = false" />
	</Transition>

	<Transition name="slide">
		<aside v-if="open" class="app-details" role="dialog" :aria-label="t('access_matrix', 'Application details')">
			<header class="app-details__header">
				<img v-if="app?.icon" class="app-details__icon" :src="app.icon" alt="">
				<div class="app-details__title">
					<h2 class="app-details__name">{{ app?.name }}</h2>
					<p v-if="app?.version" class="app-details__version">
						{{ t('access_matrix', 'Version {version}', { version: app.version }) }}
					</p>
				</div>
				<NcButton variant="tertiary" :aria-label="t('access_matrix', 'Close')" @click="open = false">
					<template #icon>
						<span class="icon-close" />
					</template>
				</NcButton>
			</header>

			<nav class="app-details__tabs">
				<button
					class="app-details__tab"
					:class="{ 'app-details__tab--active': tab === 'description' }"
					type="button"
					@click="tab = 'description'">
					{{ t('access_matrix', 'Description') }}
				</button>
				<button
					class="app-details__tab"
					:class="{ 'app-details__tab--active': tab === 'properties' }"
					type="button"
					@click="tab = 'properties'">
					{{ t('access_matrix', 'Properties') }}
				</button>
				<button
					class="app-details__tab"
					:class="{ 'app-details__tab--active': tab === 'access' }"
					type="button"
					@click="tab = 'access'">
					{{ t('access_matrix', 'Access') }}
				</button>
			</nav>

			<div class="app-details__body">
				<template v-if="tab === 'description'">
					<p v-if="app?.summary" class="app-details__summary">
						{{ app.summary }}
					</p>
					<p v-if="app?.description" class="app-details__description">
						{{ app.description }}
					</p>
					<p v-else-if="!app?.summary" class="app-details__state">
						{{ t('access_matrix', 'No description.') }}
					</p>
				</template>

				<template v-else-if="tab === 'properties'">
					<dl class="app-details__properties">
						<dt>{{ t('access_matrix', 'Identifier') }}</dt>
						<dd>{{ app?.id }}</dd>
						<dt>{{ t('access_matrix', 'Version') }}</dt>
						<dd>{{ app?.version || '—' }}</dd>
						<dt>{{ t('access_matrix', 'Licence') }}</dt>
						<dd>{{ app?.licence || '—' }}</dd>
						<dt>{{ t('access_matrix', 'Author') }}</dt>
						<dd>{{ app?.author || '—' }}</dd>
						<dt>{{ t('access_matrix', 'Categories') }}</dt>
						<dd>{{ app?.categories.length ? app.categories.join(', ') : '—' }}</dd>
						<template v-if="app && app.types.length > 0">
							<dt>{{ t('access_matrix', 'Types') }}</dt>
							<dd>{{ app.types.join(', ') }}</dd>
						</template>
					</dl>
					<p v-if="app?.website" class="app-details__link">
						<a :href="app.website" target="_blank" rel="noopener noreferrer">
							{{ app.website }}
						</a>
					</p>
					<p v-if="app?.repository" class="app-details__link">
						<a :href="app.repository" target="_blank" rel="noopener noreferrer">
							{{ app.repository }}
						</a>
					</p>
				</template>

				<template v-else>
					<p class="app-details__state">
						{{ app && state && state.unrestricted
							? t('access_matrix', 'Available to all users, no group restriction.')
							: t('access_matrix', 'Available only to the selected groups.') }}
					</p>
					<ul v-if="app && state && !state.unrestricted && groupNames.length > 0" class="app-details__groups">
						<li v-for="name in groupNames" :key="name" class="app-details__group">
							{{ name }}
						</li>
					</ul>
					<p v-else-if="app && state && !state.unrestricted" class="app-details__state">
						{{ t('access_matrix', 'The application is enabled, but no group has access.') }}
					</p>
				</template>
			</div>
		</aside>
	</Transition>
</template>

<style scoped>
.app-details__backdrop {
	position: fixed;
	/* Панель не должна перекрывать верхнюю панель Nextcloud. */
	inset: var(--header-height, 50px) 0 0 0;
	background-color: rgba(0, 0, 0, 0.3);
	z-index: 10000;
}

.app-details {
	position: fixed;
	/* Панель открывается под верхней панелью Nextcloud, а не поверх неё. */
	top: var(--header-height, 50px);
	right: 0;
	bottom: 0;
	width: min(480px, 100vw);
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border-inline-start: 1px solid var(--color-border);
	box-shadow: -4px 0 16px rgba(0, 0, 0, 0.2);
	z-index: 10001;
	overflow-y: auto;
}

.app-details__header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.app-details__icon {
	width: 40px;
	height: 40px;
	object-fit: contain;
}

.app-details__title {
	flex: 1 1 auto;
	min-width: 0;
}

.app-details__name {
	font-weight: bold;
	margin: 0;
}

.app-details__version {
	color: var(--color-text-maxcontrast);
	margin: 2px 0 0;
}

.app-details__tabs {
	display: flex;
	gap: 4px;
	padding: 8px 16px 0;
	border-bottom: 1px solid var(--color-border);
}

.app-details__tab {
	background: none;
	border: none;
	border-bottom: 2px solid transparent;
	padding: 8px 12px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.app-details__tab--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
	font-weight: bold;
}

.app-details__body {
	padding: 16px;
}

.app-details__state {
	color: var(--color-text-maxcontrast);
}

.app-details__summary {
	font-weight: bold;
}

.app-details__description {
	white-space: pre-line;
}

.app-details__properties {
	display: grid;
	grid-template-columns: minmax(120px, auto) 1fr;
	gap: 6px 16px;
	margin: 0;
}

.app-details__properties dt {
	color: var(--color-text-maxcontrast);
}

.app-details__properties dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.app-details__link {
	margin: 12px 0 0;
}

.app-details__groups {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 0;
	margin: 12px 0 0;
	list-style: none;
}

.app-details__group {
	background-color: var(--color-background-dark);
	border-radius: 12px;
	padding: 2px 10px;
}

.slide-enter-active,
.slide-leave-active {
	transition: transform 0.2s ease;
}

.slide-enter-from,
.slide-leave-to {
	transform: translateX(100%);
}

.fade-enter-active,
.fade-leave-active {
	transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
	opacity: 0;
}
</style>
