# tg-max — Синхронизация Telegram ↔ MAX Messenger

Двунаправленная синхронизация сообщений между группой Telegram и группой MAX Messenger.  
Работает как один PHP-процесс в Docker под управлением Supervisor.

## Поддерживаемые типы сообщений

| Тип | TG → MAX | MAX → TG |
|-----|----------|----------|
| Текст | ✅ | ✅ |
| Фото | ✅ | ✅ |
| Видео | ✅ | ✅ |
| Видеозаметка (кружок) | ✅ (как видео) | — |

---

## Требования

- Docker + Docker Compose
- **Telegram-бот** с правами администратора в целевой группе  
  → создать через [@BotFather](https://t.me/BotFather), отключить режим Privacy Mode
- **Green API аккаунт** для доступа к MAX messenger  
  → зарегистрировать инстанс на [console.green-api.com](https://console.green-api.com), получить `idInstance` и `apiTokenInstance`

---

## Установка и запуск

### 1. Создать файл конфигурации

```bash
cp .env.example .env
```

Заполнить `.env`:

```dotenv
# Telegram
TELEGRAM_BOT_TOKEN=123456:ABC-ваш-токен
TELEGRAM_GROUP_ID=-1001234567890      # отрицательное число для супергрупп

# Green API для MAX messenger
GREEN_API_URL=https://api.green-api.com
GREEN_API_MEDIA_URL=https://media.green-api.com
GREEN_API_INSTANCE=3100000000         # idInstance из кабинета Green API
GREEN_API_TOKEN=ваш-apiTokenInstance  # apiTokenInstance из кабинета Green API

# Целевой чат MAX (числовой ID, отрицательный для групп)
MAX_GROUP_ID=-10000000000000

# Опционально
LOG_LEVEL=info          # debug | info | warning | error
SYNC_INTERVAL=2         # секунд между циклами опроса
```

### 2. Установить зависимости PHP (локально, для разработки)

Системный `composer` из Ubuntu несовместим с PHP 8.5. Используйте свежий `composer.phar`:

```bash
# Скачать актуальный Composer (один раз)
curl -sS https://getcomposer.org/installer | php8.5 -- --filename=composer.phar

# Установить зависимости
php8.5 composer.phar install
```

> В Docker зависимости устанавливаются автоматически при сборке образа.

### 3. Собрать и запустить в Docker

```bash
docker compose up -d --build
```

### 4. Просмотр логов

```bash
docker compose logs -f
```

### 5. Остановка

```bash
docker compose down
```

База данных SQLite сохраняется в `./data/sync.db` между перезапусками.

---

## Как найти ID группы Telegram

1. Добавить бота в группу.
2. Отправить любое сообщение в группу.
3. Открыть в браузере: `https://api.telegram.org/bot<ТОКЕН>/getUpdates`
4. Найти `"chat":{"id":...}` — это отрицательное число и есть ID группы.

## Как найти ID группы MAX

1. Зайти в [console.green-api.com](https://console.green-api.com) → выбрать инстанс → раздел **Журнал входящих**.
2. Отправить любое сообщение в целевую группу MAX.
3. В журнале найти уведомление `incomingMessageReceived` → поле `senderData.chatId` — это и есть ID группы (отрицательное число для групповых чатов).

---

## Структура проекта

```
tg-max/
├── src/
│   ├── Config.php          # Загрузка .env, синглтон
│   ├── Logger.php          # Логирование в файл и stdout
│   ├── Database.php        # SQLite — учёт синхронизированных сообщений
│   ├── TelegramBot.php     # Клиент Telegram Bot API
│   ├── MaxBot.php          # Клиент MAX Bot API
│   ├── MediaHandler.php    # Скачивание и передача медиафайлов
│   ├── SyncWorker.php      # Основная логика двунаправленной синхронизации
│   └── worker.php          # Точка входа (долгоживущий процесс)
├── data/                   # SQLite БД (Docker volume)
├── logs/                   # Логи (Docker volume)
├── storage/                # Временные медиафайлы (Docker volume)
├── Dockerfile
├── docker-compose.yml
├── supervisord.conf
├── composer.json
└── .env.example
```

---

## Принцип работы

1. **Telegram → MAX**: воркер вызывает `getUpdates` с long-polling (таймаут 25 с).  
   Каждое новое сообщение из настроенной группы, которого ещё нет в таблице `synced_messages`, пересылается в MAX через Green API (`sendMessage` / `sendFileByUpload`), после чего записывается в БД с обеих сторон — для предотвращения эхо-петли.

2. **MAX → Telegram**: после каждого TG-цикла воркер вызывает `receiveNotification` (Green API HTTP-API, FIFO-очередь, ожидание 5 с).  
   Уведомления вида `incomingMessageReceived` из целевого чата пересылаются в Telegram.  
   После обработки каждое уведомление подтверждается вызовом `deleteNotification`.

3. **Медиа**: файлы скачиваются в `/app/storage`, пересылаются, затем удаляются.

> **Важно**: в Green API инстанс должен работать в режиме **HTTP API** (без `webhookUrl`).  
> Если `webhookUrl` установлен, `receiveNotification` вернёт ошибку. Очистить можно в кабинете или методом `SetSettings`.

---

## Ротация логов

Лог-файл `/app/logs/app.log` автоматически ротируется при достижении заданного размера.  
Настраивается в `.env`:

```dotenv
LOG_MAX_SIZE=10M    # максимальный размер файла (K / M / G)
LOG_MAX_FILES=5     # сколько архивных файлов хранить (app.log.1 … app.log.5)
```

При ротации: текущий `app.log` → `app.log.1`, предыдущий `app.log.1` → `app.log.2` и т.д.  
Самый старый файл (`.N`) удаляется.

---

## Решение проблем

| Симптом | Вероятная причина |
|---------|-------------------|
| Сообщения из MAX не появляются в TG | Инстанс не авторизован в MAX / не включена настройка `incomingWebhook` |
| `receiveNotification` возвращает ошибку про webhookUrl | В кабинете Green API установлен `webhookUrl` — очистить и подождать ~1 мин |
| Сообщения из TG не появляются в MAX | Неверный `MAX_GROUP_ID` или `GREEN_API_TOKEN` |
| Ошибка `Required config key` | Не заполнено значение в `.env` |
| Ошибка 403 при отправке файла | Превышен тариф / инстанс деактивирован |
| Сообщения не появляются в TG | Бот не администратор / включён Privacy Mode в Telegram |
| Дублирование сообщений | Файл БД был удалён — уже виденные сообщения синхронизируются один раз заново |
| Лог не ротируется | Проверить права на запись в `./logs/` |
