<?php

declare(strict_types=1);

namespace MaxBot\Feedback\Bot;

use Bot;
use PHPMaxBot;
use PHPMaxBot\Helpers\Keyboard;
use MaxBot\Feedback\Database\Database;
use MaxBot\Feedback\Survey\SurveyConfig;
use MaxBot\Feedback\Survey\Validator;

class FeedbackBot
{
    private PHPMaxBot    $bot;
    private Database     $db;
    private SurveyConfig $config;

    public function __construct()
    {
        $token = $_ENV['BOT_TOKEN'] ?? getenv('BOT_TOKEN');
        if (empty($token)) {
            throw new \RuntimeException('BOT_TOKEN is not set.');
        }

        $dbPath     = $_ENV['DB_PATH']       ?? getenv('DB_PATH')       ?: __DIR__ . '/../../data/feedback.sqlite';
        $configPath = $_ENV['SURVEY_CONFIG'] ?? getenv('SURVEY_CONFIG') ?: __DIR__ . '/../../config/survey.yaml';

        $this->bot    = new PHPMaxBot($token);
        $this->db     = new Database($dbPath);
        $this->config = new SurveyConfig($configPath);
    }

    // ------------------------------------------------------------------ //
    //  Entry point                                                         //
    // ------------------------------------------------------------------ //

    public function run(): void
    {
        $this->registerHandlers();
        $this->bot->start();
    }

    // ------------------------------------------------------------------ //
    //  Handler registration                                                //
    // ------------------------------------------------------------------ //

    private function registerHandlers(): void
    {
        // --- Commands ---
        $this->bot->command('start', function () {
            return $this->handleStart();
        });

        $this->bot->command('help', function () {
            return $this->handleHelp();
        });

        $this->bot->command('cancel', function () {
            return $this->handleCancel();
        });

        $this->bot->command('surveys', function () {
            return $this->handleSurveyList();
        });

        // --- Callback button: choice or rating answer ---
        $this->bot->action('answer:(.+)', function (array $matches) {
            return $this->handleButtonAnswer($matches[1]);
        });

        // --- Callback button: skip optional question ---
        $this->bot->action('skip_question', function () {
            return $this->handleSkip();
        });

        // --- Callback button: confirm restart ---
        $this->bot->action('restart:(.+)', function (array $matches) {
            return $this->startSurveyForUser($matches[1]);
        });

        // --- Contact attachment (phone sharing via requestContact button) ---
        $this->bot->onAttachment('contact', function (array $attachment) {
            return $this->handleContactAttachment($attachment);
        });

        // --- General text messages (survey answers typed by user) ---
        $this->bot->on('message_created', function () {
            return $this->handleMessage();
        });
    }

    // ------------------------------------------------------------------ //
    //  Command handlers                                                    //
    // ------------------------------------------------------------------ //

    private function handleHelp(): void
    {
        $author  = $this->config->getAuthor();
        $name    = $author['name']   ?? 'Автор';
        $email   = $author['email']  ?? '';
        $github  = $author['github'] ?? '';

        $lines = [
            "📋 *MaxBot Feedback* — бот для сбора обратной связи после мероприятий\n",
            "Доступные команды:",
            "/start — начать опрос (первый в конфигурации)",
            "/start <id> — начать конкретный опрос",
            "/surveys — список доступных опросов",
            "/cancel — прервать текущий опрос",
            "/help — эта справка\n",
            "💬 По вопросам использования и коммерческому лицензированию:",
        ];

        if ($name)   $lines[] = "👤 {$name}";
        if ($email)  $lines[] = "✉️ {$email}";
        if ($github) $lines[] = "🔗 {$github}";

        Bot::sendMessage(implode("\n", $lines));
    }

    private function handleStart(): void
    {
        $update  = PHPMaxBot::$currentUpdate;
        $userId  = (string) ($update['user']['user_id'] ?? '');
        $rawText = trim($update['message']['text'] ?? '');

        // Extract optional survey ID argument: "/start event_feedback"
        $parts    = preg_split('/\s+/', $rawText, 2);
        $surveyId = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;

        if ($surveyId === null) {
            $surveyId = $this->config->getDefaultSurveyId();
        } elseif (!$this->config->surveyExists($surveyId)) {
            Bot::sendMessage(
                "❌ Опрос «{$surveyId}» не найден.\n" .
                "Используйте /surveys для просмотра доступных опросов."
            );
            return;
        }

        // If user already has an active session — ask confirmation
        $active = $this->db->getActiveSurvey($userId);
        if ($active) {
            $activeSurveyName = $this->config->getSurvey($active['survey_id'])['name'] ?? $active['survey_id'];
            Bot::sendMessage(
                "У вас уже есть незавершённый опрос «{$activeSurveyName}».\n" .
                "Начать новый? Прогресс предыдущего будет потерян.",
                [
                    'attachments' => [
                        Keyboard::inlineKeyboard([
                            [Keyboard::callback('✅ Да, начать заново', "restart:{$surveyId}")],
                            [Keyboard::callback('❌ Продолжить текущий', 'answer:__resume__')],
                        ]),
                    ],
                ]
            );
            return;
        }

        $this->startSurveyForUser($surveyId, $userId);
    }

    private function handleCancel(): void
    {
        $update = PHPMaxBot::$currentUpdate;
        $userId = (string) ($update['user']['user_id'] ?? '');

        $active = $this->db->getActiveSurvey($userId);
        if (!$active) {
            Bot::sendMessage('У вас нет активного опроса.');
            return;
        }

        $this->db->abandonSurvey($userId);
        Bot::sendMessage('Опрос прерван. Вы можете начать заново с помощью /start.');
    }

    private function handleSurveyList(): void
    {
        $list = $this->config->getSurveyList();
        if (empty($list)) {
            Bot::sendMessage('Нет доступных опросов.');
            return;
        }

        $lines = ["📋 *Доступные опросы:*\n"];
        foreach ($list as $id => $name) {
            $lines[] = "• /start {$id} — {$name}";
        }

        Bot::sendMessage(implode("\n", $lines));
    }

    // ------------------------------------------------------------------ //
    //  Survey flow                                                         //
    // ------------------------------------------------------------------ //

    private function startSurveyForUser(string $surveyId, string $userId = null): void
    {
        if ($userId === null) {
            $update = PHPMaxBot::$currentUpdate;
            $userId = (string) ($update['user']['user_id'] ?? '');
        }

        $survey     = $this->config->getSurvey($surveyId);
        $responseId = $this->db->startSurvey($userId, $surveyId);

        $startMsg = trim($survey['start_message'] ?? 'Начинаем опрос.');
        Bot::sendMessage($startMsg);

        $this->sendQuestion($surveyId, 0, $responseId);
    }

    private function sendQuestion(string $surveyId, int $idx, int $responseId): void
    {
        $question = $this->config->getQuestion($surveyId, $idx);
        if ($question === null) {
            $this->finishSurvey($surveyId, $responseId);
            return;
        }

        $total   = $this->config->getQuestionCount($surveyId);
        $num     = $idx + 1;
        $text    = "[{$num}/{$total}] " . ($question['text'] ?? '');
        $type    = $question['type'];
        $options = [];

        $skipButton = null;
        if (!($question['required'] ?? true)) {
            $skipLabel  = $question['skip_label'] ?? 'Пропустить';
            $skipButton = Keyboard::callback($skipLabel, 'skip_question');
        }

        switch ($type) {
            case 'choice':
                $rows = [];
                foreach ($question['choices'] as $choice) {
                    $rows[] = [Keyboard::callback($choice, 'answer:' . $choice)];
                }
                if ($skipButton !== null) {
                    $rows[] = [$skipButton];
                }
                $options['attachments'] = [Keyboard::inlineKeyboard($rows)];
                break;

            case 'boolean':
                $rows = [
                    [
                        Keyboard::callback('✅ Да',  'answer:Да'),
                        Keyboard::callback('❌ Нет', 'answer:Нет'),
                    ],
                ];
                if ($skipButton !== null) {
                    $rows[] = [$skipButton];
                }
                $options['attachments'] = [Keyboard::inlineKeyboard($rows)];
                break;

            case 'rating':
                $min  = (int) ($question['min'] ?? 1);
                $max  = (int) ($question['max'] ?? 10);
                $rows = $this->buildRatingRows($min, $max);
                if ($skipButton !== null) {
                    $rows[] = [$skipButton];
                }
                $options['attachments'] = [Keyboard::inlineKeyboard($rows)];
                break;

            case 'phone':
                $text .= "\n\n📱 Нажмите кнопку ниже, чтобы поделиться контактом, или введите номер вручную.";
                $rows = [[Keyboard::requestContact('📱 Поделиться контактом')]];
                if ($skipButton !== null) {
                    $rows[] = [$skipButton];
                }
                $options['attachments'] = [Keyboard::inlineKeyboard($rows)];
                break;

            case 'email':
            case 'text':
                if ($skipButton !== null) {
                    $options['attachments'] = [Keyboard::inlineKeyboard([[$skipButton]])];
                }
                break;
        }

        Bot::sendMessage($text, $options);
    }

    private function buildRatingRows(int $min, int $max): array
    {
        $buttons = [];
        for ($i = $min; $i <= $max; $i++) {
            $buttons[] = Keyboard::callback((string) $i, "answer:{$i}");
        }

        // Split into rows of 5
        return array_chunk($buttons, 5);
    }

    private function finishSurvey(string $surveyId, int $responseId): void
    {
        $this->db->completeSurvey($responseId);
        $survey = $this->config->getSurvey($surveyId);
        $endMsg = trim($survey['end_message'] ?? 'Спасибо за ответы!');
        Bot::sendMessage($endMsg);
    }

    // ------------------------------------------------------------------ //
    //  Answer handlers                                                     //
    // ------------------------------------------------------------------ //

    private function handleMessage(): void
    {
        $update  = PHPMaxBot::$currentUpdate;
        $userId  = (string) ($update['user']['user_id'] ?? '');
        $text    = trim($update['message']['text'] ?? '');

        // Skip commands — they are handled by command() handlers
        if (strncmp($text, '/', 1) === 0) {
            return;
        }

        $active = $this->db->getActiveSurvey($userId);
        if (!$active) {
            // No active survey — show hint
            Bot::sendMessage(
                "Введите /start, чтобы начать опрос, или /help для справки."
            );
            return;
        }

        $this->processTextAnswer($active, $userId, $text);
    }

    private function handleButtonAnswer(string $value): void
    {
        if ($value === '__resume__') {
            // User chose to continue existing survey
            $update = PHPMaxBot::$currentUpdate;
            $userId = (string) ($update['user']['user_id'] ?? '');
            $active = $this->db->getActiveSurvey($userId);
            if ($active) {
                $this->sendQuestion(
                    $active['survey_id'],
                    (int) $active['current_question_idx'],
                    (int) $active['id']
                );
            }
            return;
        }

        $update = PHPMaxBot::$currentUpdate;
        $userId = (string) ($update['user']['user_id'] ?? '');
        $active = $this->db->getActiveSurvey($userId);

        if (!$active) {
            Bot::sendMessage('Нет активного опроса. Введите /start.');
            return;
        }

        $this->processTextAnswer($active, $userId, $value);
    }

    private function handleSkip(): void
    {
        $update = PHPMaxBot::$currentUpdate;
        $userId = (string) ($update['user']['user_id'] ?? '');
        $active = $this->db->getActiveSurvey($userId);

        if (!$active) {
            return;
        }

        $surveyId   = $active['survey_id'];
        $idx        = (int) $active['current_question_idx'];
        $responseId = (int) $active['id'];
        $question   = $this->config->getQuestion($surveyId, $idx);

        if ($question === null || ($question['required'] ?? true)) {
            Bot::sendMessage('Этот вопрос обязателен для ответа.');
            return;
        }

        // Save empty answer to mark it as skipped
        $this->db->saveAnswer($responseId, $question['id'], '');
        $this->advanceAndSend($surveyId, $idx, $responseId);
    }

    private function handleContactAttachment(array $attachment): void
    {
        $update = PHPMaxBot::$currentUpdate;
        $userId = (string) ($update['user']['user_id'] ?? '');
        $active = $this->db->getActiveSurvey($userId);

        if (!$active) {
            return;
        }

        $surveyId = $active['survey_id'];
        $idx      = (int) $active['current_question_idx'];
        $question = $this->config->getQuestion($surveyId, $idx);

        if ($question === null || $question['type'] !== 'phone') {
            // Ignore contact shares that are not expected
            return;
        }

        // Extract phone from MAX contact attachment
        $phone = $attachment['payload']['phone']
            ?? $attachment['payload']['max_info']['phone']
            ?? $attachment['phone']
            ?? null;

        if ($phone === null) {
            // Try to get name as fallback identifier
            $firstName = $attachment['payload']['max_info']['first_name'] ?? '';
            $lastName  = $attachment['payload']['max_info']['last_name']  ?? '';
            $phone     = trim("{$firstName} {$lastName}") ?: 'Контакт получен';
        }

        $responseId = (int) $active['id'];
        $this->db->saveAnswer($responseId, $question['id'], $phone);
        Bot::sendMessage("📱 Контакт получен: {$phone}");

        $this->advanceAndSend($surveyId, $idx, $responseId);
    }

    // ------------------------------------------------------------------ //
    //  Core answer processing                                              //
    // ------------------------------------------------------------------ //

    private function processTextAnswer(array $active, string $userId, string $text): void
    {
        $surveyId   = $active['survey_id'];
        $idx        = (int) $active['current_question_idx'];
        $responseId = (int) $active['id'];
        $question   = $this->config->getQuestion($surveyId, $idx);

        if ($question === null) {
            $this->finishSurvey($surveyId, $responseId);
            return;
        }

        // Validate
        $error = Validator::validate($question, $text);
        if ($error !== null) {
            Bot::sendMessage("⚠️ {$error}");
            return;
        }

        // Normalise booleans
        $value = $question['type'] === 'boolean'
            ? Validator::normaliseBoolean($text)
            : trim($text);

        $this->db->saveAnswer($responseId, $question['id'], $value);
        $this->advanceAndSend($surveyId, $idx, $responseId);
    }

    private function advanceAndSend(string $surveyId, int $currentIdx, int $responseId): void
    {
        $nextIdx = $currentIdx + 1;
        $total   = $this->config->getQuestionCount($surveyId);

        if ($nextIdx >= $total) {
            $this->finishSurvey($surveyId, $responseId);
        } else {
            $this->db->advanceQuestion($responseId, $nextIdx);
            $this->sendQuestion($surveyId, $nextIdx, $responseId);
        }
    }
}
