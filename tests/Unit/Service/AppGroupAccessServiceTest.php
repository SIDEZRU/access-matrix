<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 SIDEZ LLC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AccessMatrix\Tests\Unit\Service;

use OCA\AccessMatrix\Service\AppGroupAccessService;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AppGroupAccessServiceTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IGroupManager&MockObject $groupManager;
	private IAppConfig&MockObject $appConfig;
	private IL10N&MockObject $l10n;
	private IUserConfig&MockObject $userConfig;
	private IUserSession&MockObject $userSession;
	private AppGroupAccessService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$this->service = new AppGroupAccessService(			$this->appManager,
			$this->groupManager,
			$this->appConfig,
			$this->l10n,
			$this->userConfig,
			$this->userSession,
		);
	}

	private function group(string $gid, string $displayName): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($displayName);
		return $group;
	}

	public function testGetMatrixReadsUnrestrictedApp(): void {
		$this->appManager->method('getEnabledApps')->willReturn(['notes']);
		$this->appManager->method('getAppInfo')->willReturn([
			'name' => 'Notes',
			'version' => '1.0.0',
			'category' => ['office'],
			'summary' => 'Note taking',
		]);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->appManager->method('getAppIcon')->willReturn('/apps/notes/img/app.svg');
		$this->appConfig->method('getValueString')->willReturn('yes');
		$this->groupManager->method('search')->willReturn([
			$this->group('admin', 'admin'),
			$this->group('sales', 'Sales'),
		]);

		$matrix = $this->service->getMatrix();

		self::assertCount(2, $matrix['groups']);
		self::assertSame([['id' => 'admin', 'name' => 'admin'], ['id' => 'sales', 'name' => 'Sales']], $matrix['groups']);
		self::assertCount(1, $matrix['apps']);
		self::assertSame('notes', $matrix['apps'][0]['id']);
		self::assertSame('Notes', $matrix['apps'][0]['name']);
		self::assertSame('1.0.0', $matrix['apps'][0]['version']);
		self::assertSame(['office'], $matrix['apps'][0]['categories']);
		self::assertSame('Note taking', $matrix['apps'][0]['summary']);
		self::assertTrue($matrix['apps'][0]['unrestricted']);
		self::assertSame([], $matrix['apps'][0]['groups']);
		self::assertFalse($matrix['apps'][0]['restrictionWarning']);
	}

	public function testGetMatrixParsesGroupRestriction(): void {
		$this->appManager->method('getEnabledApps')->willReturn(['notes']);
		$this->appManager->method('getAppInfo')->willReturn(['name' => 'Notes']);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->appConfig->method('getValueString')->willReturn('["admin","sales"]');
		$this->groupManager->method('search')->willReturn([$this->group('admin', 'admin')]);

		$matrix = $this->service->getMatrix();

		self::assertFalse($matrix['apps'][0]['unrestricted']);
		self::assertSame(['admin', 'sales'], $matrix['apps'][0]['groups']);
	}

	public function testGetMatrixSkipsAppsWithProtectedTypes(): void {
		$this->appManager->method('getEnabledApps')->willReturn(['files', 'notes']);
		$this->appManager->method('getAppInfo')->willReturnCallback(
			static fn (string $appId): array => $appId === 'files'
				? ['name' => 'Files', 'types' => ['filesystem']]
				: ['name' => 'Notes']
		);
		$this->appManager->method('hasProtectedAppType')->willReturnCallback(
			static fn (array $types): bool => in_array('filesystem', $types, true)
		);
		$this->appConfig->method('getValueString')->willReturn('yes');
		$this->groupManager->method('search')->willReturn([]);

		$matrix = $this->service->getMatrix();

		self::assertSame(['notes'], array_column($matrix['apps'], 'id'));
	}

	public function testGetMatrixReadsNameWithTranslationsAsArray(): void {
		// В info.xml с переводами <name> разбирается в массив: без него название
		// превращалось в строку "Array".
		$this->appManager->method('getEnabledApps')->willReturn(['dashboardlauncher']);
		$this->appManager->method('getAppInfo')->willReturn([
			'name' => ['Dashboard Launcher', ['@attributes' => ['lang' => 'fr'], '@value' => 'Lanceur']],
		]);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->appConfig->method('getValueString')->willReturn('yes');
		$this->groupManager->method('search')->willReturn([]);

		$matrix = $this->service->getMatrix();

		self::assertSame('Dashboard Launcher', $matrix['apps'][0]['name']);
	}

	public function testGetMatrixReadsAuthorAndRepositoryWithAttributes(): void {
		// Ядро разбирает <author mail="..." homepage="..."> и <repository type="git">
		// в массив с ключом '@value': строковый каст массива давал "Array",
		// а у ссылки Repository получался href="Array".
		$this->appManager->method('getEnabledApps')->willReturn(['access_matrix']);
		$this->appManager->method('getAppInfo')->willReturn([
			'name' => 'Access Matrix',
			'version' => '0.5.6',
			'author' => [
				'@attributes' => ['mail' => 'info@sidez.ru', 'homepage' => 'https://sidez.ru'],
				'@value' => 'SIDEZ LLC',
			],
			'repository' => [
				'@attributes' => ['type' => 'git'],
				'@value' => 'https://github.com/SIDEZRU/access-matrix',
			],
			'website' => 'https://github.com/SIDEZRU/access-matrix',
		]);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->appConfig->method('getValueString')->willReturn('yes');
		$this->groupManager->method('search')->willReturn([]);

		$matrix = $this->service->getMatrix();

		self::assertSame('SIDEZ LLC', $matrix['apps'][0]['author']);
		self::assertSame('https://github.com/SIDEZRU/access-matrix', $matrix['apps'][0]['repository']);
		self::assertSame('https://github.com/SIDEZRU/access-matrix', $matrix['apps'][0]['website']);
	}

	public function testGetMatrixJoinsMultipleAuthors(): void {
		$this->appManager->method('getEnabledApps')->willReturn(['notes']);
		$this->appManager->method('getAppInfo')->willReturn([
			'name' => 'Notes',
			'author' => [
				['@value' => 'First Author'],
				['@value' => 'Second Author'],
			],
		]);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->appConfig->method('getValueString')->willReturn('yes');
		$this->groupManager->method('search')->willReturn([]);

		$matrix = $this->service->getMatrix();

		self::assertSame('First Author, Second Author', $matrix['apps'][0]['author']);
	}

	public function testGetMatrixFallsBackToAppIdWhenNameIsEmpty(): void {
		$this->appManager->method('getEnabledApps')->willReturn(['mystery']);
		$this->appManager->method('getAppInfo')->willReturn(['name' => '']);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->appConfig->method('getValueString')->willReturn('yes');
		$this->groupManager->method('search')->willReturn([]);

		$matrix = $this->service->getMatrix();

		self::assertSame('mystery', $matrix['apps'][0]['name']);
	}

	public function testSetAccessEnablesAppForAll(): void {
		$this->appManager->method('getAppInfo')->willReturn(['name' => 'Notes']);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->appManager->expects(self::once())->method('enableApp')->with('notes');
		$this->appManager->expects(self::never())->method('enableAppForGroups');

		$this->service->setAccess('notes', true, []);
	}

	public function testSetAccessEnablesAppForGroups(): void {
		$this->appManager->method('getAppInfo')->willReturn(['name' => 'Notes']);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->groupManager->method('get')->willReturn($this->group('admin', 'admin'));
		$this->appManager->expects(self::once())
			->method('enableAppForGroups')
			->with('notes', ['admin']);
		$this->appManager->expects(self::never())->method('enableApp');

		$this->service->setAccess('notes', false, ['admin']);
	}

	public function testSetAccessWithEmptyGroupListKeepsAppEnabled(): void {
		// Пустой список даёт appconfig enabled = [] — приложение включено,
		// но ни одна группа доступа не имеет.
		$this->appManager->method('getAppInfo')->willReturn(['name' => 'Notes']);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->appManager->expects(self::once())
			->method('enableAppForGroups')
			->with('notes', []);

		$this->service->setAccess('notes', false, []);
	}

	public function testSetAccessRejectsUnknownApp(): void {
		$this->appManager->method('getAppInfo')->willReturn(null);
		$this->appManager->expects(self::never())->method('enableAppForGroups');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setAccess('missing', false, []);
	}

	public function testSetAccessRejectsProtectedApp(): void {
		$this->appManager->method('getAppInfo')->willReturn(['name' => 'Files', 'types' => ['filesystem']]);
		$this->appManager->method('hasProtectedAppType')->willReturn(true);
		$this->appManager->expects(self::never())->method('enableAppForGroups');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setAccess('files', false, ['admin']);
	}

	public function testSetAccessRejectsUnknownGroup(): void {
		$this->appManager->method('getAppInfo')->willReturn(['name' => 'Notes']);
		$this->appManager->method('hasProtectedAppType')->willReturn(false);
		$this->groupManager->method('get')->willReturn(null);
		$this->appManager->expects(self::never())->method('enableAppForGroups');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setAccess('notes', false, ['nope']);
	}

	public function testGroupOrderIsApplied(): void {
		$this->appManager->method('getEnabledApps')->willReturn([]);
		$this->groupManager->method('search')->willReturn([
			$this->group('admin', 'admin'),
			$this->group('sales', 'Sales'),
			$this->group('support', 'Support'),
		]);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userConfig->method('getValueArray')->willReturn(['support', 'admin']);
		$this->appConfig->method('getValueString')->willReturn('');

		$matrix = $this->service->getMatrix();

		self::assertSame(
			['support', 'admin', 'sales'],
			array_column($matrix['groups'], 'id'),
		);
	}
}
