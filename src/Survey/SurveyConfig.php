<?php

declare(strict_types=1);

namespace MaxBot\Feedback\Survey;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class SurveyConfig
{
    private array $config;

    /** @var array<string, array> Indexed by survey ID */
    private array $surveys;

    public function __construct(string $yamlPath)
    {
        if (!file_exists($yamlPath)) {
            throw new RuntimeException("Survey config file not found: {$yamlPath}");
        }

        $this->config  = Yaml::parseFile($yamlPath);
        $this->surveys = $this->config['surveys'] ?? [];

        $this->validate();
    }

    // ------------------------------------------------------------------ //
    //  Public API                                                          //
    // ------------------------------------------------------------------ //

    /** Return author contacts array (name, email, github, …). */
    public function getAuthor(): array
    {
        return $this->config['author'] ?? [];
    }

    /** Return all survey IDs. */
    public function getSurveyIds(): array
    {
        return array_keys($this->surveys);
    }

    /** Return the first survey ID (used as default for /start). */
    public function getDefaultSurveyId(): string
    {
        $ids = $this->getSurveyIds();
        if (empty($ids)) {
            throw new RuntimeException('No surveys defined in config.');
        }

        // Prefer a key literally named "default", otherwise use first
        return in_array('default', $ids, true) ? 'default' : $ids[0];
    }

    /** Check if a survey with the given ID exists. */
    public function surveyExists(string $surveyId): bool
    {
        return isset($this->surveys[$surveyId]);
    }

    /** Return full survey definition. */
    public function getSurvey(string $surveyId): array
    {
        if (!$this->surveyExists($surveyId)) {
            throw new RuntimeException("Survey not found: {$surveyId}");
        }

        return $this->surveys[$surveyId];
    }

    /** Return a specific question by index. */
    public function getQuestion(string $surveyId, int $index): ?array
    {
        $questions = $this->getQuestions($surveyId);

        return $questions[$index] ?? null;
    }

    /** Return all questions for a survey. */
    public function getQuestions(string $surveyId): array
    {
        return $this->getSurvey($surveyId)['questions'] ?? [];
    }

    /** Return total number of questions. */
    public function getQuestionCount(string $surveyId): int
    {
        return count($this->getQuestions($surveyId));
    }

    /** Return display list of surveys: [id => name]. */
    public function getSurveyList(): array
    {
        $list = [];
        foreach ($this->surveys as $id => $survey) {
            $list[$id] = $survey['name'] ?? $id;
        }

        return $list;
    }

    // ------------------------------------------------------------------ //
    //  Validation                                                          //
    // ------------------------------------------------------------------ //

    private function validate(): void
    {
        $allowedTypes = ['text', 'rating', 'choice', 'boolean', 'email', 'phone'];

        foreach ($this->surveys as $surveyId => $survey) {
            $questions = $survey['questions'] ?? [];

            foreach ($questions as $idx => $q) {
                $type = $q['type'] ?? '';

                if (!in_array($type, $allowedTypes, true)) {
                    throw new RuntimeException(
                        "Survey '{$surveyId}', question #{$idx}: unknown type '{$type}'. " .
                        "Allowed: " . implode(', ', $allowedTypes)
                    );
                }

                if ($type === 'choice' && empty($q['choices'])) {
                    throw new RuntimeException(
                        "Survey '{$surveyId}', question #{$idx}: 'choice' type requires 'choices' list."
                    );
                }

                if ($type === 'rating') {
                    $min = $q['min'] ?? null;
                    $max = $q['max'] ?? null;
                    if ($min === null || $max === null || $min >= $max) {
                        throw new RuntimeException(
                            "Survey '{$surveyId}', question #{$idx}: 'rating' requires valid 'min' and 'max'."
                        );
                    }
                }
            }
        }
    }
}
