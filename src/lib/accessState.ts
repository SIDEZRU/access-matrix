/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { AccessState, MatrixApp } from '../types/matrix'

/**
 * Состояние доступа к приложению, сохранённое на сервере.
 *
 * Приложение без ограничений (unrestricted) доступно всем, поэтому в матрице
 * его группы отмечаются целиком.
 */
export function serverState(app: MatrixApp, allGroupIds: string[]): AccessState {
	return {
		unrestricted: app.unrestricted,
		groups: app.unrestricted ? [...allGroupIds] : [...app.groups],
	}
}

/**
 * Состояние с учётом подготовленных, но ещё не применённых изменений.
 */
export function effectiveState(
	app: MatrixApp,
	allGroupIds: string[],
	drafts: Record<string, AccessState>,
): AccessState {
	return drafts[app.id] ?? serverState(app, allGroupIds)
}

/**
 * Отмечена ли группа в состоянии.
 */
export function isGroupChecked(state: AccessState, groupId: string): boolean {
	return state.groups.includes(groupId)
}

/**
 * Включена ли главная галочка строки: доступ выдан всем группам.
 */
export function isAllChecked(state: AccessState, allGroupIds: string[]): boolean {
	if (allGroupIds.length === 0) {
		return false
	}

	return allGroupIds.every((groupId) => state.groups.includes(groupId))
}

/**
 * Состояние после переключения главной галочки.
 *
 * Включение отмечает все группы, выключение — снимает все: приложение остаётся
 * включённым, но ни одна группа доступа не имеет.
 */
export function toggleMasterState(
	state: AccessState,
	allGroupIds: string[],
	checked: boolean,
): AccessState {
	return {
		unrestricted: checked,
		groups: checked ? [...allGroupIds] : [],
	}
}

/**
 * Состояние после переключения отдельной группы.
 */
export function toggleGroupState(
	state: AccessState,
	allGroupIds: string[],
	groupId: string,
	checked: boolean,
): AccessState {
	const groups = checked
		? [...new Set([...state.groups, groupId])]
		: state.groups.filter((id) => id !== groupId)

	return {
		unrestricted: groups.length === allGroupIds.length,
		groups,
	}
}

/**
 * Состояние после массовой операции по выбранным столбцам групп.
 */
export function applyColumnOperation(
	state: AccessState,
	allGroupIds: string[],
	selectedGroupIds: string[],
	grant: boolean,
): AccessState {
	const groups = grant
		? [...new Set([...state.groups, ...selectedGroupIds])]
		: state.groups.filter((groupId) => !selectedGroupIds.includes(groupId))

	return {
		unrestricted: groups.length === allGroupIds.length,
		groups,
	}
}

/**
 * Совпадают ли два состояния: одинаковый набор групп и одинаковый признак
 * «без ограничений».
 */
export function sameState(a: AccessState, b: AccessState): boolean {
	if (a.unrestricted !== b.unrestricted) {
		return false
	}

	if (a.groups.length !== b.groups.length) {
		return false
	}

	return a.groups.every((groupId) => b.groups.includes(groupId))
}
