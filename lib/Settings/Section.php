<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AccessMatrix\Settings;

use OCA\AccessMatrix\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Settings\IIconSection;

/**
 * Раздел «Матрица доступа» в «Параметрах сервера».
 */
class Section implements IIconSection {
	public function __construct(
		private IL10NFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	/**
	 * Идентификатор раздела, должен совпадать с getSection() формы.
	 */
	public function getID(): string {
		return Application::APP_ID;
	}

	/**
	 * Раздел создаётся серверным контейнером, поэтому перевод приложения
	 * запрашивается явно — иначе строка не переведётся.
	 */
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Access Matrix');
	}

	/**
	 * Порядок раздела в списке «Параметров сервера»: чем больше, тем ниже.
	 */
	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'access-matrix.svg');
	}
}
