<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AccessMatrix\Settings;

use OCA\AccessMatrix\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Форма матрицы в разделе «Параметры сервера».
 */
class Admin implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');

		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	/**
	 * Идентификатор раздела из Settings\Section.
	 */
	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Порядок формы в разделе: чем больше, тем ниже.
	 */
	public function getPriority(): int {
		return 80;
	}
}
