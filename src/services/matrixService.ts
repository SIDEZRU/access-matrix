/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import type { Matrix } from '../types/matrix'

const BASE_URL = generateOcsUrl('apps/access_matrix/api')

/**
 * OCS-запросы обязаны содержать заголовок OCS-APIRequest, иначе сервер
 * отвечает 412 «CSRF check failed».
 */
const OCS_HEADERS = { 'OCS-APIRequest': true }

interface OcsResponse<T> {
	ocs: {
		data: T
	}
}

/**
 * Матрица «приложение × группа» с метаданными приложений.
 */
export async function fetchMatrix(): Promise<Matrix> {
	const { data } = await axios.get<OcsResponse<Matrix>>(`${BASE_URL}/matrix`, {
		headers: OCS_HEADERS,
	})

	return data.ocs.data
}

/**
 * Сохранить порядок колонок групп.
 *
 * @param order идентификаторы групп в нужном порядке
 */
export async function saveGroupOrder(order: string[]): Promise<Matrix> {
	const { data } = await axios.post<OcsResponse<Matrix>>(
		`${BASE_URL}/group-order`,
		{ order },
		{ headers: OCS_HEADERS },
	)

	return data.ocs.data
}

/**
 * Изменить доступ к приложению.
 *
 * @param appId идентификатор приложения
 * @param unrestricted доступен ли доступ всем без ограничения по группам
 * @param groups идентификаторы групп, которым доступно приложение
 */
export async function saveAppAccess(appId: string, unrestricted: boolean, groups: string[]): Promise<Matrix> {
	const { data } = await axios.post<OcsResponse<Matrix>>(
		`${BASE_URL}/matrix/${encodeURIComponent(appId)}`,
		{ unrestricted, groups },
		{ headers: OCS_HEADERS },
	)

	return data.ocs.data
}
