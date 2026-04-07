<?php

declare(strict_types=1);

namespace MaxBot\Feedback\Database;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA journal_mode=WAL;');
            $this->pdo->exec('PRAGMA foreign_keys=ON;');
        } catch (PDOException $e) {
            throw new RuntimeException('Cannot open SQLite database: ' . $e->getMessage());
        }

        $this->migrate();
    }

    // ------------------------------------------------------------------ //
    //  Schema                                                              //
    // ------------------------------------------------------------------ //

    private function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS survey_responses (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id               TEXT    NOT NULL,
                survey_id             TEXT    NOT NULL,
                current_question_idx  INTEGER NOT NULL DEFAULT 0,
                status                TEXT    NOT NULL DEFAULT 'in_progress',
                started_at            TEXT    NOT NULL DEFAULT (datetime('now')),
                completed_at          TEXT
            );

            CREATE TABLE IF NOT EXISTS answers (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                survey_response_id   INTEGER NOT NULL,
                question_id          TEXT    NOT NULL,
                answer_value         TEXT,
                answered_at          TEXT    NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (survey_response_id) REFERENCES survey_responses(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_responses_user   ON survey_responses (user_id);
            CREATE INDEX IF NOT EXISTS idx_responses_status ON survey_responses (user_id, status);
        ");
    }

    // ------------------------------------------------------------------ //
    //  Survey responses                                                    //
    // ------------------------------------------------------------------ //

    /** Start a new survey response for a user (any previous in-progress survey is abandoned). */
    public function startSurvey(string $userId, string $surveyId): int
    {
        $this->pdo->prepare(
            "UPDATE survey_responses SET status = 'abandoned' WHERE user_id = ? AND status = 'in_progress'"
        )->execute([$userId]);

        $stmt = $this->pdo->prepare(
            "INSERT INTO survey_responses (user_id, survey_id) VALUES (?, ?)"
        );
        $stmt->execute([$userId, $surveyId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Return the active in-progress survey response for a user, or null. */
    public function getActiveSurvey(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM survey_responses WHERE user_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Advance the question pointer. */
    public function advanceQuestion(int $responseId, int $nextIdx): void
    {
        $this->pdo->prepare(
            "UPDATE survey_responses SET current_question_idx = ? WHERE id = ?"
        )->execute([$nextIdx, $responseId]);
    }

    /** Mark survey as completed. */
    public function completeSurvey(int $responseId): void
    {
        $this->pdo->prepare(
            "UPDATE survey_responses SET status = 'completed', completed_at = datetime('now') WHERE id = ?"
        )->execute([$responseId]);
    }

    /** Mark survey as abandoned. */
    public function abandonSurvey(string $userId): void
    {
        $this->pdo->prepare(
            "UPDATE survey_responses SET status = 'abandoned' WHERE user_id = ? AND status = 'in_progress'"
        )->execute([$userId]);
    }

    // ------------------------------------------------------------------ //
    //  Answers                                                             //
    // ------------------------------------------------------------------ //

    public function saveAnswer(int $responseId, string $questionId, string $value): void
    {
        $existing = $this->pdo->prepare(
            "SELECT id FROM answers WHERE survey_response_id = ? AND question_id = ?"
        );
        $existing->execute([$responseId, $questionId]);

        if ($existing->fetch()) {
            $this->pdo->prepare(
                "UPDATE answers SET answer_value = ?, answered_at = datetime('now') WHERE survey_response_id = ? AND question_id = ?"
            )->execute([$value, $responseId, $questionId]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO answers (survey_response_id, question_id, answer_value) VALUES (?, ?, ?)"
            )->execute([$responseId, $questionId, $value]);
        }
    }

    /** Return all answers for a given survey response. */
    public function getAnswers(int $responseId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT question_id, answer_value, answered_at FROM answers WHERE survey_response_id = ? ORDER BY id"
        );
        $stmt->execute([$responseId]);

        return $stmt->fetchAll();
    }

    /** Return all completed responses for a survey (for export / admin). */
    public function getCompletedResponses(string $surveyId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT sr.id, sr.user_id, sr.survey_id, sr.started_at, sr.completed_at,
                   a.question_id, a.answer_value, a.answered_at
            FROM survey_responses sr
            LEFT JOIN answers a ON a.survey_response_id = sr.id
            WHERE sr.survey_id = ? AND sr.status = 'completed'
            ORDER BY sr.id, a.id
        ");
        $stmt->execute([$surveyId]);

        return $stmt->fetchAll();
    }

    /** Return summary statistics for a survey. */
    public function getSurveySummary(string $surveyId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(DISTINCT CASE WHEN status = 'completed'  THEN id END) AS completed,
                COUNT(DISTINCT CASE WHEN status = 'in_progress' THEN id END) AS in_progress,
                COUNT(DISTINCT CASE WHEN status = 'abandoned'  THEN id END) AS abandoned
            FROM survey_responses
            WHERE survey_id = ?
        ");
        $stmt->execute([$surveyId]);

        return $stmt->fetch() ?: ['completed' => 0, 'in_progress' => 0, 'abandoned' => 0];
    }
}
