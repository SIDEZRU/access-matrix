<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AccessMatrix\Controller;

use OCA\AccessMatrix\AppInfo\Application;
use OCA\AccessMatrix\Service\AppGroupAccessService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * API матрицы «приложение × группа».
 *
 * Контроллер наследует OCSController: OCS-роут отдаёт данные в обёртке
 * {ocs: {meta, data}}, как того ждёт клиент.
 *
 * Действия доступны только администраторам: аннотация #[NoAdminRequired]
 * не задана.
 */
class MatrixController extends OCSController {
	public function __construct(
		IRequest $request,
		private AppGroupAccessService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[ApiRoute(verb: 'GET', url: '/api/matrix')]
	public function index(): DataResponse {
		return new DataResponse($this->service->getMatrix());
	}

	/**
	 * Сохранить порядок колонок групп.
	 *
	 * @param string[] $order идентификаторы групп в нужном порядке
	 */
	#[ApiRoute(verb: 'POST', url: '/api/group-order')]
	public function groupOrder(array $order = []): DataResponse {
		try {
			$this->service->setGroupOrder($order);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->service->getMatrix());
	}

	/**
	 * @param string[] $groups идентификаторы групп, которым доступно приложение
	 */
	#[ApiRoute(verb: 'POST', url: '/api/matrix/{appId}')]
	public function update(string $appId, bool $unrestricted = false, array $groups = []): DataResponse {
		try {
			$this->service->setAccess($appId, $unrestricted, $groups);
		} catch (\InvalidArgumentException $e) {
			$this->logger->warning('Не удалось изменить доступ к приложению: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'target' => $appId,
			]);

			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->service->getMatrix());
	}
}
