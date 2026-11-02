<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AccessMatrix\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\Config\IUserConfig;
use OCP\IUserSession;

/**
 * Доступ к приложениям по группам.
 *
 * Ограничение хранится штатным механизмом Nextcloud: appconfig-ключ `enabled`
 * приложения. Значение 'yes' — приложение доступно всем, JSON-список GID —
 * доступ только перечисленным группам, '[]' — приложение включено, но ни одна
 * группа доступа не имеет.
 */
class AppGroupAccessService {
	/** Идентификатор приложения — используется в пользовательских настройках */
	public const APP_ID = 'access_matrix';

	/** Ключ appconfig, в котором Nextcloud хранит состояние приложения и список разрешённых групп */
	public const ENABLED_KEY = 'enabled';

	/** Значение appconfig приложения, доступного всем */
	private const ENABLED_ALL = 'yes';

	/** Ключ пользовательской настройки с порядком групп */
	private const GROUP_ORDER_KEY = 'group_order';

	/** Ключ appconfig с кэшем проверки приложений на несовместимость с ограничением по группам */
	private const UNSAFE_CACHE_KEY = 'restriction_unsafe_apps';

	/** Сколько живёт кэш проверки, секунды */
	private const UNSAFE_CACHE_TTL = 86400;

	public function __construct(
		private IAppManager $appManager,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private IUserConfig $userConfig,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Матрица «приложение × группа».
	 *
	 * Приложения, к которым доступ по группам настроить нельзя (защищённые
	 * типы в info.xml), в матрицу не попадают.
	 *
	 * @return array{apps: list<array{id: string, name: string, unrestricted: bool, groups: list<string>}>, groups: list<array{id: string, name: string}>}
	 */
	public function getMatrix(): array {
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = [
				'id' => $group->getGID(),
				'name' => $group->getDisplayName(),
			];
		}
		usort($groups, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		$groups = $this->applyGroupOrder($groups);

		$apps = [];
		$unsafeApps = $this->appsWithUnsafeRestrictionHandling();
		foreach ($this->appManager->getEnabledApps() as $appId) {
			$info = $this->appManager->getAppInfo($appId);
			if ($info === null) {
				continue;
			}
			if ($this->appManager->hasProtectedAppType($info['types'] ?? [])) {
				continue;
			}

			$enabled = $this->appConfig->getValueString($appId, self::ENABLED_KEY);
			$unrestricted = $enabled === self::ENABLED_ALL;
			$allowedGroups = [];
			if (!$unrestricted && $enabled !== '' && $enabled !== 'no') {
				$decoded = json_decode($enabled, true);
				if (is_array($decoded)) {
					$allowedGroups = array_values(array_filter($decoded, 'is_string'));
				}
			}

			$name = $this->textValue($info['name'] ?? null);

			$apps[] = [
				'id' => $appId,
				'name' => $name !== '' ? $name : $appId,
				'version' => (string)($info['version'] ?? ''),
				'icon' => $this->appManager->getAppIcon($appId) ?? '',
				'summary' => $this->textValue($info['summary'] ?? null),
				'description' => $this->textValue($info['description'] ?? null),
				'licence' => $this->elementText($info['licence'] ?? null),
				'author' => $this->elementText($info['author'] ?? null),
				'categories' => $this->categoryList($info['category'] ?? null),
				'types' => array_values(array_map('strval', (array)($info['types'] ?? []))),
				'website' => $this->elementText($info['website'] ?? null),
				'repository' => $this->elementText($info['repository'] ?? null),
				'unrestricted' => $unrestricted,
				'groups' => $allowedGroups,
				'restrictionWarning' => in_array($appId, $unsafeApps, true),
			];
		}
		usort($apps, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		return [
			'apps' => $apps,
			'groups' => $groups,
			'currentUserGroups' => $this->currentUserGroups(),
		];
	}

	/**
	 * Группы текущего пользователя — нужны, чтобы предупредить администратора
	 * о том, что изменение оставит его без доступа к приложению.
	 *
	 * @return list<string>
	 */
	private function currentUserGroups(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$gids = [];
		foreach ($this->groupManager->getUserGroups($user) ?? [] as $group) {
			$gids[] = $group->getGID();
		}

		return $gids;
	}

	/**
	 * Изменить список групп, которым доступно приложение.
	 *
	 * @param string[] $groupIds
	 *
	 * @throws \InvalidArgumentException если приложение или группа не найдены либо приложение нельзя ограничить по группам
	 */
	public function setAccess(string $appId, bool $unrestricted, array $groupIds): void {
		$info = $this->appManager->getAppInfo($appId);
		if ($info === null) {
			throw new \InvalidArgumentException($this->l10n->t('Application "%s" not found.', [$appId]));
		}
		if ($this->appManager->hasProtectedAppType($info['types'] ?? [])) {
			throw new \InvalidArgumentException($this->l10n->t('Application "%s" cannot be restricted to groups.', [$appId]));
		}

		if ($unrestricted) {
			$this->appManager->enableApp($appId);

			return;
		}

		$gids = [];
		foreach ($groupIds as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				throw new \InvalidArgumentException($this->l10n->t('Group "%s" not found.', [$gid]));
			}
			$gids[] = $group->getGID();
		}

		// Пустой список даёт appconfig '[]': приложение включено, но ни одна группа доступа не имеет.
		$this->appManager->enableAppForGroups($appId, $gids);
	}

	/**
	 * Текстовое значение поля info.xml.
	 *
	 * Поля с переводами парсятся массивом: ['значение по умолчанию',
	 * ['@attributes' => ['lang' => 'fr'], '@value' => '...'], ...]. В такой
	 * список попадает и само название приложения — без разбора оно
	 * превращается в строку "Array".
	 */
	private function textValue(mixed $value): string {
		if (is_string($value)) {
			return $value;
		}
		if (!is_array($value)) {
			return '';
		}

		// Вариант без атрибута lang — значение по умолчанию.
		foreach ($value as $item) {
			if (is_string($item)) {
				return $item;
			}
		}

		// Иначе — первый вариант с текстом (обычно english).
		foreach ($value as $item) {
			if (is_array($item) && isset($item['@value'])) {
				return (string)$item['@value'];
			}
		}

		return '';
	}

	/**
	 * Текст элемента info.xml (author, repository, licence и подобные).
	 *
	 * Ядро разбирает info.xml в массив: элемент с атрибутами даёт
	 * ['@attributes' => [...], '@value' => '...'], повторяющиеся элементы —
	 * список таких массивов. Строковый каст массива превращал значение
	 * в «Array», поэтому собираем текст явно.
	 */
	private function elementText(mixed $value): string {
		if (is_string($value)) {
			return $value;
		}
		if (!is_array($value)) {
			return '';
		}
		if (isset($value['@value']) && is_string($value['@value'])) {
			return $value['@value'];
		}

		$items = [];
		foreach ($value as $item) {
			if (is_string($item)) {
				$items[] = $item;
			}
			elseif (is_array($item) && isset($item['@value']) && is_string($item['@value'])) {
				$items[] = $item['@value'];
			}
		}

		return implode(', ', $items);
	}

	/**
	 * Список категорий приложения из info.xml.
	 *
	 * Ключ в распарсенном info.xml — `category` (не `categories`), значение —
	 * список строк, у переводимых вариантов — массив с `@value`.
	 *
	 * @return list<string>
	 */
	private function categoryList(mixed $value): array {
		if (is_string($value)) {
			return [$value];
		}
		if (!is_array($value)) {
			return [];
		}

		$categories = [];
		foreach ($value as $item) {
			if (is_string($item)) {
				$categories[] = $item;
			}
		}
		if ($categories !== []) {
			return $categories;
		}

		foreach ($value as $item) {
			if (is_array($item) && isset($item['@value'])) {
				$categories[] = (string)$item['@value'];
				break;
			}
		}

		return $categories;
	}

	/**
	 * Сохранить порядок колонок групп для текущего пользователя.
	 *
	 * @param string[] $groupIds идентификаторы групп в нужном порядке
	 *
	 * @throws \InvalidArgumentException если указана несуществующая группа
	 */
	/**
	 * Приложения, которые некорректно работают с ограничением по группам.
	 *
	 * Nextcloud хранит ограничение в appconfig-ключе `enabled`: 'yes' — доступ
	 * всем, JSON-список GID — доступ перечисленным группам. Приложение, которое
	 * читает это значение и сравнивает его именно с 'yes', считает себя
	 * выключенным при любом ограничении по группам и прячет свой интерфейс от
	 * всех, включая администратора. Приложение, которое само пишет в этот ключ
	 * (например, переключатель «вкл/выкл» в своих настройках), затирает
	 * ограничение при сохранении своих настроек.
	 *
	 * Результат проверки кэшируется на сутки: сканирование кода всех
	 * установленных приложений занимает заметное время.
	 *
	 * @return list<string>
	 */
	private function appsWithUnsafeRestrictionHandling(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::UNSAFE_CACHE_KEY, '');
		$cached = $raw === '' ? null : json_decode($raw, true);
		if (is_array($cached)
			&& isset($cached['checked'], $cached['apps'])
			&& is_array($cached['apps'])
			&& (time() - (int)$cached['checked']) < self::UNSAFE_CACHE_TTL) {
			return array_values(array_filter($cached['apps'], 'is_string'));
		}

		$unsafe = [];
		foreach ($this->appManager->getEnabledApps() as $appId) {
			if ($this->appHasUnsafeRestrictionHandling($appId)) {
				$unsafe[] = $appId;
			}
		}

		$this->appConfig->setValueString(self::APP_ID, self::UNSAFE_CACHE_KEY, json_encode([
			'checked' => time(),
			'apps' => $unsafe,
		]));

		return $unsafe;
	}

	/**
	 * Проверить код приложения на несовместимость с ограничением по группам.
	 */
	private function appHasUnsafeRestrictionHandling(string $appId): bool {
		try {
			$path = $this->appManager->getAppPath($appId) . '/lib';
		} catch (\Throwable) {
			return false;
		}

		if (!is_dir($path)) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$content = file_get_contents($file->getPathname());
			if ($content === false) {
				continue;
			}

			// Приложение читает значение 'enabled' и считает себя включённым
			// только при точном значении 'yes'.
			if (preg_match("/get(App)?Value(String)?\(\s*[^,]+,\s*'enabled'\s*,[^)]*\)\s*===?\s*'yes'/", $content) === 1) {
				return true;
			}

			// Приложение само пишет в ключ 'enabled' и затирает ограничение.
			if (preg_match("/set(App)?Value(String)?\(\s*[^,]+,\s*'enabled'\s*,/", $content) === 1) {
				return true;
			}
		}

		return false;
	}

	public function setGroupOrder(array $groupIds): void {		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new \InvalidArgumentException($this->l10n->t('No user session.'));
		}

		$gids = [];
		foreach ($groupIds as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				throw new \InvalidArgumentException($this->l10n->t('Group "%s" not found.', [$gid]));
			}
			$gids[] = $group->getGID();
		}

		$this->userConfig->setValueArray($uid, self::APP_ID, self::GROUP_ORDER_KEY, $gids);
	}

	/**
	 * Разложить группы в сохранённом пользователем порядке.
	 *
	 * Группы, появившиеся после сохранения порядка, ставятся в конец.
	 *
	 * @param list<array{id: string, name: string}> $groups
	 *
	 * @return list<array{id: string, name: string}>
	 */
	private function applyGroupOrder(array $groups): array {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return $groups;
		}

		$order = $this->userConfig->getValueArray($uid, self::APP_ID, self::GROUP_ORDER_KEY, []);
		if ($order === []) {
			return $groups;
		}

		$byId = [];
		foreach ($groups as $group) {
			$byId[$group['id']] = $group;
		}

		$sorted = [];
		foreach ($order as $gid) {
			if (isset($byId[$gid])) {
				$sorted[] = $byId[$gid];
				unset($byId[$gid]);
			}
		}
		foreach ($byId as $group) {
			$sorted[] = $group;
		}

		return $sorted;
	}
}
