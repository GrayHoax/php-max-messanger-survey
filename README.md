# MaxBot Feedback — Бот для сбора обратной связи в Max Messenger

> **PHP-бот для мессенджера Max** | Сбор обратной связи после мероприятий, обучений и встреч | SQLite | YAML-конфигурация | Docker

[![PHP](https://img.shields.io/badge/PHP-≥7.4-777BB4?logo=php)](https://php.net)
[![Max Messenger](https://img.shields.io/badge/Max%20Messenger-Bot%20API-0088CC)](https://max.ru)
[![License](https://img.shields.io/badge/license-Proprietary-red)](#лицензия)

---

## О проекте

**MaxBot Feedback** — готовый бот для [Max Messenger](https://max.ru), который собирает структурированную обратную связь от участников мероприятий, слушателей обучений и участников деловых встреч.

Вопросы задаются в удобном формате YAML. Результаты сохраняются в SQLite. Развёртывание — одной командой через Docker.

### Ключевые возможности

- 📋 **YAML-конфигурация опросов** — добавляйте, редактируйте и удаляйте вопросы без изменения кода
- 💬 **Нативные кнопки Max** — выбор вариантов, оценки и запрос контакта через интерфейс мессенджера
- 📱 **Запрос номера телефона** — через кнопку «Поделиться контактом» (нативная функция Max)
- ✉️ **Запрос e-mail** — с валидацией формата
- 🗄️ **SQLite** — нет зависимости от внешних СУБД; база хранится локально
- 🐳 **Docker** — Nginx + PHP-FPM, одна команда для запуска
- 🔒 **Webhook** — боты Max работают через защищённый вебхук
- 📊 **Несколько опросов** — разные анкеты для разных мероприятий в одном боте

### Типы вопросов

| Тип | Описание | Интерфейс |
|-----|----------|-----------|
| `text` | Произвольный текст | Текстовый ввод |
| `rating` | Числовая оценка с диапазоном | Кнопки (1–N) |
| `choice` | Выбор из вариантов | Кнопки со списком |
| `boolean` | Да / Нет | Кнопки |
| `email` | E-mail с валидацией | Текстовый ввод |
| `phone` | Номер телефона | Кнопка «Поделиться контактом» |

---

## Быстрый старт

### 1. Клонировать репозиторий

```bash
git clone https://github.com/GrayHoax/maxbot-feedback.git
cd maxbot-feedback
```

### 2. Настроить окружение

```bash
cp .env.example .env
```

Откройте `.env` и укажите токен бота:

```dotenv
BOT_TOKEN=your_max_bot_token_here
NGINX_PORT=8080
```

> Токен получите у **@MaxBotFather** в мессенджере Max.

### 3. Настроить опросы

Отредактируйте `config/survey.yaml`. Каждый опрос имеет уникальный идентификатор и список вопросов:

```yaml
surveys:
  event_feedback:
    name: "Обратная связь о мероприятии"
    start_message: "Спасибо за участие! Ответьте, пожалуйста, на несколько вопросов."
    end_message: "Спасибо! Ваш отзыв получен."
    questions:
      - id: overall_rating
        type: rating
        text: "Как вы оцениваете мероприятие в целом?"
        min: 1
        max: 10
        required: true
        error_message: "Введите число от 1 до 10"

      - id: email
        type: email
        text: "Укажите e-mail для получения материалов (необязательно)"
        required: false
        skip_label: "Пропустить"

      - id: phone
        type: phone
        text: "Поделитесь контактом для обратной связи (необязательно)"
        required: false
        skip_label: "Пропустить"
```

### 4. Запустить через Docker

```bash
docker compose up -d --build
```

Бот будет доступен на `http://localhost:8080/webhook`.

### 5. Зарегистрировать вебхук в Max

Укажите публичный URL вашего сервера (через HTTPS) в настройках бота у @MaxBotFather:

```
https://your-domain.com/webhook
```

> Для локальной разработки можно использовать [ngrok](https://ngrok.com):
> ```bash
> ngrok http 8080
> ```

---

## Команды бота

| Команда | Описание |
|---------|----------|
| `/start` | Начать опрос по умолчанию |
| `/start <id>` | Начать конкретный опрос (например, `/start training_feedback`) |
| `/surveys` | Список доступных опросов |
| `/cancel` | Прервать текущий опрос |
| `/help` | Справка и контакты автора |

---

## Структура проекта

```
maxbot-feedback/
├── config/
│   └── survey.yaml          # Конфигурация опросов
├── data/
│   └── feedback.sqlite      # База данных (создаётся автоматически)
├── docker/
│   ├── nginx/
│   │   └── default.conf     # Конфигурация Nginx
│   └── php/
│       ├── Dockerfile        # PHP 8.2-FPM Alpine
│       └── php.ini           # PHP-настройки
├── public/
│   └── index.php            # Точка входа для вебхука
├── src/
│   ├── Bot/
│   │   └── FeedbackBot.php  # Логика бота
│   ├── Database/
│   │   └── Database.php     # SQLite через PDO
│   └── Survey/
│       ├── SurveyConfig.php # Загрузка и валидация YAML
│       └── Validator.php    # Валидация ответов пользователей
├── .env.example
├── composer.json
├── docker-compose.yml
└── README.md
```

---

## Схема базы данных

### `survey_responses`

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | INTEGER | Первичный ключ |
| `user_id` | TEXT | ID пользователя в Max |
| `survey_id` | TEXT | ID опроса из YAML |
| `current_question_idx` | INTEGER | Индекс текущего вопроса |
| `status` | TEXT | `in_progress` / `completed` / `abandoned` |
| `started_at` | TEXT | Дата начала |
| `completed_at` | TEXT | Дата завершения |

### `answers`

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | INTEGER | Первичный ключ |
| `survey_response_id` | INTEGER | FK → survey_responses |
| `question_id` | TEXT | ID вопроса из YAML |
| `answer_value` | TEXT | Ответ пользователя |
| `answered_at` | TEXT | Время ответа |

---

## Требования

- Docker Engine 20+
- Docker Compose v2+

Для локальной разработки без Docker:
- PHP ≥ 7.4
- Расширения: `pdo`, `pdo_sqlite`, `curl`, `json`
- Composer 2+

---

## Разработка без Docker

```bash
# Установить зависимости
composer install

# Запустить встроенный сервер PHP (для тестирования)
php -S localhost:8080 -t public/

# Или настроить ngrok + зарегистрировать вебхук
```

---

## Связанные проекты

- [php-max-bot](https://github.com/GrayHoax/php-max-bot) — PHP-фреймворк для разработки ботов Max Messenger

---

## Лицензия

**Коммерческое использование данного программного обеспечения разрешено исключительно с письменного согласования автора.**

Некоммерческое использование, модификация и распространение разрешены с указанием первоисточника.

© 2026 [GrayHoax](https://github.com/GrayHoax)

---

## Контакты

По вопросам лицензирования, интеграции и доработки:

- 🔗 **GitHub:** [github.com/GrayHoax](https://github.com/GrayHoax)

Также используйте команду `/help` в самом боте.
