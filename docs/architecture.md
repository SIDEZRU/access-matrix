# Архитектура плагина «Матрица доступа»

## Назначение

Плагин закрывает боль штатного интерфейса Nextcloud: ограничение приложения
по группам задаётся вручную через «Настройки → Приложения → ⋯ → Разрешить
использование только участникам этих групп», где каждую группу нужно вписать
руками. Плагин показывает матрицу «приложение × группа» и переключает доступ
одним нажатием.

Целевая платформа — Nextcloud 35, PHP 8.3.

## Штатный механизм Nextcloud (первоисточник)

Ограничение приложения по группам хранится в appconfig самого приложения, в
ключе `enabled`. Плагин работает поверх этого механизма, а не рядом с ним, —
иначе ограничения из матрицы расходились бы с ограничениями из штатного
интерфейса.

Nextcloud 35 (`stable35`), `lib/private/App/AppManager.php`:

| Место | Поведение |
|---|---|
| `enableApp()` (стр. 638) | `setValue($appId, 'enabled', 'yes')` (стр. 651) — приложение доступно всем |
| `enableAppForGroups()` (стр. 682) | `setValue($appId, 'enabled', json_encode($groupIds))` (стр. 707) — доступ только перечисленным группам |
| `enableAppForGroups()` (стр. 688) | для приложений с защищённым типом (`prevent_group_restriction`) бросается `InvalidArgumentException` |
| `enableAppForGroups()` (стр. 693) | для неустановленных приложений — `InvalidArgumentException` |
| `checkAppForUser()` (стр. 372) | `'yes'` → доступ всем; пустое значение → доступа нет; JSON-массив → доступ, если пользователь состоит в одной из групп (стр. 392-396) |

Штатный веб-интерфейс вызывает ровно этот API: `apps/appstore/lib/Controller/ApiController.php`,
метод `enableApp()` (стр. 166) — `$this->appManager->enableAppForGroups($appId, $this->getGroupList($groups))`
(стр. 181), иначе `$this->appManager->enableApp($appId)` (стр. 183).

Чтение текущего ограничения — `OCP\IAppConfig::getValueString($appId, 'enabled')`
(`lib/public/IAppConfig.php`, стр. 181). Изменение состояния —
`OCP\App\IAppManager::enableApp()` и `IAppManager::enableAppForGroups()`.

### Состояния строки матрицы

| appconfig `enabled` | Смысл | Как получить |
|---|---|---|
| `yes` | доступ всем, ограничений нет | `enableApp($appId)` |
| `["g1","g2"]` | доступ только группам `g1`, `g2` | `enableAppForGroups($appId, [$g1, $g2])` |
| `[]` | включено, но ни одна группа не имеет доступа | прямая запись `IAppConfig::setValueString($appId, 'enabled', '[]')` |
| `no` / ключа нет | приложение выключено | `disableApp($appId)` |

Решение владельца: снятие всех отметок в строке оставляет приложение
включённым, но без доступных групп (состояние `[]`). Штатный путь для этого
не подходит: ядро пустой список трактует как сброс ограничения —
`apps/appstore/lib/Controller/ApiController.php`, метод `enableApp()`
(стр. 166): `if ($groups !== []) { enableAppForGroups(...) } else { enableApp($appId) }`
(стр. 180-184), то есть штатный UI при пустом списке возвращает доступ всем.
Поэтому состояние `[]` плагин пишет сам через `IAppConfig`.

Про администратора: обхода групповых ограничений в коде нет —
`checkAppForUser()` (стр. 372) смотрит только членство пользователя в
группах, а `lib/private/NavigationManager.php` (стр. 128) показывает
приложение по `isEnabledForUser()`. Администратор, не состоящий в
перечисленных группах, приложение в навигации не увидит, поэтому группу
`admin` при необходимости нужно включать в список явно.

## Слои плагина
| Слой | Файл | Назначение |
|---|---|---|
| Раздел настроек | `lib/Settings/Section.php` | пункт «Матрица доступа» в «Параметрах сервера» (`OCP\Settings\IIconSection`) |
| Форма настроек | `lib/Settings/Admin.php` | форма раздела (`OCP\Settings\ISettings`), подключает скрипт и стили и отдаёт шаблон |
| Страница | `lib/Controller/PageController.php` | маршрут `access_matrix.page.index` (`#[FrontpageRoute]`); действие без атрибута `#[NoAdminRequired]` доступно только администраторам |
| API матрицы | `lib/Controller/MatrixController.php` | `GET /api/matrix` — данные матрицы; `POST /api/matrix/{appId}` — изменение доступа к приложению; `POST /api/group-order` — порядок колонок групп |
| Доменный сервис | `lib/Service/AppGroupAccessService.php` | чтение/запись ограничений через `IAppConfig` и `IAppManager`, порядок групп — через `OCP\Config\IUserConfig` |
| Фронтенд | `src/main.ts`, `src/components/AccessMatrix.vue` | Vue 3 + `@nextcloud/vue` 9, сборка Vite (`@nextcloud/vite-config`), результат — `js/access_matrix-main.mjs` |
| Панель приложения | `src/components/AppDetailsPanel.vue` | выезжающая справа панель со сведениями о приложении (описание, свойства, доступ) |
| API-клиент | `src/services/matrixService.ts` | обращения к OCS-роутам плагина через `generateOcsUrl` |

Точка входа — раздел «Параметры сервера» → «Матрица доступа». Раздел
регистрируется в `appinfo/info.xml`:

```xml
<settings>
    <admin>OCA\AccessMatrix\Settings\Admin</admin>
    <admin-section>OCA\AccessMatrix\Settings\Section</admin-section>
</settings>
```

Хранилище плагину не требуется: группы — это `IGroupManager`, приложения —
`IAppManager`, ограничения — appconfig приложений. Своя таблица в плагине
создала бы второй источник правды.

## API плагина

Роуты объявлены атрибутом `#[ApiRoute]` (OCS, `/ocs/v2.php/apps/access_matrix/…`).
Оба действия доступны только администраторам — атрибута `#[NoAdminRequired`
у них нет.

| Роут | Метод | Ответ |
|---|---|---|
| `/api/matrix` | GET | `{ apps: [{ id, name, version, icon, summary, description, licence, author, categories, types, website, repository, unrestricted, groups }], groups: [{ id, name }] }` |
| `/api/matrix/{appId}` | POST | тело `{ unrestricted: bool, groups: string[] }`; ответ — обновлённая матрица, при ошибке — 400 с текстом |
| `/api/group-order` | POST | тело `{ order: string[] }` — порядок колонок групп; ответ — обновлённая матрица |

Ограничения: в пути OCS-роутов нельзя использовать сегмент `apps` — роут
`/api/apps/{appId}` не находится маршрутизатором, хотя и регистрируется.

Порядок колонок групп хранится в пользовательских настройках
(`OCP\Config\IUserConfig`, ключ `group_order`) — то есть у каждого администратора
своя раскладка. Новые группы, появившиеся после сохранения порядка, ставятся в
конец.

## Приложения, несовместимые с ограничением по группам

Nextcloud хранит ограничение в appconfig-ключе `enabled`: `yes` — доступ всем,
JSON-список GID — доступ перечисленным группам. Приложение, которое читает
это значение и сравнивает его именно с `yes`, считает себя выключенным при
любом ограничении и прячет свой интерфейс от всех, включая администратора.
Приложение, которое само пишет в этот ключ (переключатель «вкл/выкл» в его
настройках), затирает ограничение при сохранении настроек.

Плагин проверяет код установленных приложений на оба паттерна и помечает
такие приложения знаком «(!)» с пояснением. Для них галочка любой группы
работает как переключатель «Все»: ограничение по группам для них не работает,
доступ либо есть у всех, либо нет ни у кого. Результат проверки кэшируется в
appconfig приложения на сутки — сканирование кода всех приложений занимает
заметное время.

OCS-запросы обязаны содержать заголовок `OCS-APIRequest: true` — без него
сервер отвечает `412 CSRF check failed`. Заголовок проставляет
`src/services/matrixService.ts`.

`unrestricted: true` → `enableApp()` (доступ всем, appconfig `enabled = yes`).
`unrestricted: false` → `enableAppForGroups()` со списком групп; пустой список
даёт appconfig `enabled = []` — приложение включено, но ни одна группа
доступа не имеет.

## Сборка

Фронтенд собирается Vite через `@nextcloud/vite-config` — тем же стеком, что
и официальный шаблон Nextcloud-приложения (`nextcloud/app-template`):
`vue ^3.5`, `@nextcloud/vue ^9`, `@nextcloud/vite-config ^2.5`, TypeScript.
Результат — `js/access_matrix-main.mjs`, который подключается в шаблоне
через `Util::addScript(Application::APP_ID, Application::APP_ID . '-main')`.

Ограничение: в корневом `package.json` нельзя добавлять `"private": true` —
плагин лицензий `@nextcloud/vite-config` уходит в бесконечную рекурсию при
поиске `package.json` (подробности в `README.md`).

## Структура репозитория

```
access-matrix/
├── appinfo/info.xml          манифест Nextcloud-приложения (id: access_matrix)
├── lib/                      PHP-бэкенд плагина
│   ├── AppInfo/Application.php
│   ├── Controller/PageController.php
│   ├── Controller/MatrixController.php
│   ├── Service/AppGroupAccessService.php
│   └── Settings/Admin.php, Section.php
├── templates/admin.php       шаблон с точкой монтирования Vue
├── src/                      фронтенд плагина (Vue 3)
│   ├── main.ts               точка входа
│   ├── components/AccessMatrix.vue
│   ├── components/AppDetailsPanel.vue
│   ├── services/matrixService.ts
│   └── types/matrix.ts
├── l10n/                     переводы (ru)
├── img/                      иконка приложения
├── js/, css/                 собранные ассеты фронтенда
├── docs/architecture.md      этот документ
├── composer.json             PHP-зависимости и PSR-4
├── package.json              JS-зависимости и скрипты сборки
├── vite.config.ts            сборка фронтенда (@nextcloud/vite-config)
└── tsconfig.json             настройки TypeScript/Vue
```

## Границы возможностей

Плагин управляет только ограничением доступа к приложениям по группам. Он не
берёт на себя:

- разграничение прав внутри приложения — это делает каждое приложение само
  (через свои группы и настройки);
- выдачу приложений пользователям вне групп;
- собственную модель ролей или прав: источник истины — appconfig-ключ
  `enabled` приложения, который использует и штатный интерфейс Nextcloud.