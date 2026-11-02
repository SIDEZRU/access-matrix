/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import {
	applyColumnOperation,
	effectiveState,
	isAllChecked,
	isGroupChecked,
	sameState,
	serverState,
	toggleGroupState,
	toggleMasterState,
} from '../../src/lib/accessState'
import type { AccessState, MatrixApp } from '../../src/types/matrix'

const ALL_GROUP_IDS = ['admin', 'sales', 'support']

function app(overrides: Partial<MatrixApp> = {}): MatrixApp {
	return {
		id: 'notes',
		name: 'Notes',
		version: '1.0.0',
		icon: '',
		summary: '',
		description: '',
		licence: 'AGPL-3.0-or-later',
		author: 'SIDEZ LLC',
		categories: [],
		types: [],
		website: '',
		repository: '',
		unrestricted: false,
		groups: ['admin'],
		restrictionWarning: false,
		...overrides,
	}
}

describe('serverState', () => {
	it('разворачивает ограничение «без ограничений» в полный набор групп', () => {
		const state = serverState(app({ unrestricted: true }), ALL_GROUP_IDS)

		expect(state.groups).toEqual(ALL_GROUP_IDS)
		expect(state.unrestricted).toBe(true)
	})

	it('оставляет явный список групп как есть', () => {
		const state = serverState(app({ groups: ['admin', 'support'] }), ALL_GROUP_IDS)

		expect(state.groups).toEqual(['admin', 'support'])
	})
})

describe('effectiveState', () => {
	it('предпочитает подготовленный черновик серверному состоянию', () => {
		const draft: AccessState = { unrestricted: false, groups: ['sales'] }

		expect(effectiveState(app(), ALL_GROUP_IDS, { notes: draft })).toBe(draft)
	})

	it('без черновика берёт серверное состояние', () => {
		expect(effectiveState(app({ groups: ['admin'] }), ALL_GROUP_IDS, {})).toEqual({
			unrestricted: false,
			groups: ['admin'],
		})
	})
})

describe('главная галочка строки', () => {
	it('включена, когда отмечены все группы', () => {
		expect(isAllChecked({ unrestricted: false, groups: ALL_GROUP_IDS }, ALL_GROUP_IDS)).toBe(true)
	})

	it('выключена, если отмечена не вся группа', () => {
		expect(isAllChecked({ unrestricted: false, groups: ['admin'] }, ALL_GROUP_IDS)).toBe(false)
	})

	it('включение отмечает все группы', () => {
		const state = toggleMasterState({ unrestricted: false, groups: [] }, ALL_GROUP_IDS, true)

		expect(state.groups).toEqual(ALL_GROUP_IDS)
		expect(state.unrestricted).toBe(true)
	})

	it('выключение снимает все группы, приложение остаётся включённым', () => {
		const state = toggleMasterState({ unrestricted: true, groups: ALL_GROUP_IDS }, ALL_GROUP_IDS, false)

		expect(state.groups).toEqual([])
		expect(state.unrestricted).toBe(false)
	})
})

describe('переключение отдельной группы', () => {
	it('добавляет группу', () => {
		const state = toggleGroupState({ unrestricted: false, groups: ['admin'] }, ALL_GROUP_IDS, 'sales', true)

		expect(state.groups).toEqual(['admin', 'sales'])
	})

	it('убирает группу', () => {
		const state = toggleGroupState({ unrestricted: false, groups: ['admin', 'sales'] }, ALL_GROUP_IDS, 'admin', false)

		expect(state.groups).toEqual(['sales'])
	})

	it('снятие группы у приложения «всем» выключает главную галочку', () => {
		const state = toggleGroupState({ unrestricted: true, groups: ALL_GROUP_IDS }, ALL_GROUP_IDS, 'sales', false)

		expect(state.unrestricted).toBe(false)
		expect(state.groups).toEqual(['admin', 'support'])
	})

	it('возврат всех групп снова включает главную галочку', () => {
		const state = toggleGroupState({ unrestricted: false, groups: ['admin', 'support'] }, ALL_GROUP_IDS, 'sales', true)

		expect(state.unrestricted).toBe(true)
	})
})

describe('массовая операция по столбцам', () => {
	it('выдаёт доступ выделенным группам', () => {
		const state = applyColumnOperation({ unrestricted: false, groups: ['admin'] }, ALL_GROUP_IDS, ['sales', 'support'], true)

		expect(state.groups).toEqual(['admin', 'sales', 'support'])
	})

	it('снимает доступ у выделенных групп', () => {
		const state = applyColumnOperation({ unrestricted: false, groups: ['admin', 'sales'] }, ALL_GROUP_IDS, ['sales'], false)

		expect(state.groups).toEqual(['admin'])
	})

	it('применяется к приложению без ограничений: исключение становится явным списком', () => {
		const state = applyColumnOperation({ unrestricted: true, groups: ALL_GROUP_IDS }, ALL_GROUP_IDS, ['sales'], false)

		expect(state.unrestricted).toBe(false)
		expect(state.groups).toEqual(['admin', 'support'])
	})
})

describe('sameState', () => {
	it('считает состояния равными независимо от порядка групп', () => {
		expect(sameState(
			{ unrestricted: false, groups: ['admin', 'sales'] },
			{ unrestricted: false, groups: ['sales', 'admin'] },
		)).toBe(true)
	})

	it('различает разные наборы групп', () => {
		expect(sameState(
			{ unrestricted: false, groups: ['admin'] },
			{ unrestricted: false, groups: ['admin', 'sales'] },
		)).toBe(false)
	})

	it('различает разный признак «без ограничений»', () => {
		expect(sameState(
			{ unrestricted: true, groups: ALL_GROUP_IDS },
			{ unrestricted: false, groups: ALL_GROUP_IDS },
		)).toBe(false)
	})
})
