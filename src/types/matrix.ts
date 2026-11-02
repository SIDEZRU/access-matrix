export interface MatrixApp {
	id: string
	name: string
	version: string
	/** Путь к иконке приложения */
	icon: string
	summary: string
	description: string
	licence: string
	author: string
	categories: string[]
	types: string[]
	website: string
	repository: string
	/** Приложение некорректно работает с ограничением по группам */
	restrictionWarning: boolean
	/** Приложение доступно всем, ограничений по группам нет */
	unrestricted: boolean
	/** Идентификаторы групп, которым доступно приложение */
	groups: string[]
}

export interface MatrixGroup {
	id: string
	name: string
}

export interface Matrix {
	apps: MatrixApp[]
	groups: MatrixGroup[]
	/** Группы текущего пользователя — для предупреждения о потере доступа */
	currentUserGroups: string[]
}

/**
 * Состояние доступа к приложению: список групп, которым приложение доступно.
 */
export interface AccessState {
	/** Доступно всем группам без исключений */
	unrestricted: boolean
	groups: string[]
}
