<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AccessMatrix\Controller;

use OCA\AccessMatrix\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Страница плагина.
 *
 * Без атрибута #[NoAdminRequired] действие доступно только администраторам,
 * что соответствует назначению страницы. Основная точка входа — раздел
 * «Параметры сервера» (см. Settings\Admin), этот маршрут оставлен для прямой
 * ссылки на страницу.
 */
class PageController extends Controller {
	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');

		return new TemplateResponse(Application::APP_ID, 'admin');
	}
}
