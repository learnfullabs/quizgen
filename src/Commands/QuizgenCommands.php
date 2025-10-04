<?php

declare(strict_types=1);

namespace Drupal\quizgen\Commands;

use Drupal\quizgen\QuizgenAiService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Quiz Generator module.
 */
class QuizgenCommands extends DrushCommands {

  /**
   * The Quiz Generator AI service.
   *
   * @var \Drupal\quizgen\QuizgenAiService
   */
  protected $aiService;

  /**
   * Constructs a QuizgenCommands object.
   *
   * @param \Drupal\quizgen\QuizgenAiService $ai_service
   *   The Quiz Generator AI service.
   */
  public function __construct(QuizgenAiService $ai_service) {
    $this->aiService = $ai_service;
  }

  /**
   * Test the AI integration.
   *
   * @param string $message
   *   The message to send to the AI (default: 'hello').
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @option temperature
   *   The temperature setting for the AI request (default: 1.0).
   * @option model
   *   The AI model to use (default: 'gpt-4o').
   * @option provider
   *   The AI provider to use (default: 'openai').
   *
   * @command quizgen:test-ai
   * @aliases qg-test
   * @usage quizgen:test-ai
   *   Test AI integration with default 'hello' message.
   * @usage quizgen:test-ai "How are you?" --temperature=0.5
   *   Test AI integration with custom message and temperature.
   * @usage quizgen:test-ai "Generate a quiz question" --model=gpt-3.5-turbo
   *   Test AI integration with custom message and model.
   */
  public function testAi(string $message = 'hello', array $options = ['temperature' => null, 'model' => null, 'provider' => null]): void {
    $temperature = (float) ($options['temperature'] ?? 1.0);
    $model = $options['model'] ?? 'gpt-4o';
    $provider = $options['provider'] ?? 'openai';
    
    $this->output()->writeln('Testing AI integration...');
    $this->output()->writeln('Message: ' . $message);
    $this->output()->writeln('Provider: ' . $provider);
    $this->output()->writeln('Model: ' . $model);
    $this->output()->writeln('Temperature: ' . $temperature);
    $this->output()->writeln('');
    
    $response = $this->aiService->makeAiRequest($message, $provider, $model, $temperature);
    
    if ($response) {
      $this->output()->writeln('AI Response: ' . $response);
      $this->output()->writeln('');
      $this->output()->writeln('✅ AI integration test successful!');
      $this->output()->writeln('📄 Response logged to completions.json file.');
    } else {
      $this->output()->writeln('❌ AI integration test failed. Check the logs for details.');
    }
  }

  /**
   * View the completions JSON file.
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   *
   * @option limit
   *   Number of recent completions to show (default: 10).
   *
   * @command quizgen:view-completions
   * @aliases qg-view
   * @usage quizgen:view-completions
   *   View the last 10 completions.
   * @usage quizgen:view-completions --limit=5
   *   View the last 5 completions.
   */
  public function viewCompletions(array $options = ['limit' => null]): void {
    $limit = (int) ($options['limit'] ?? 10);
    
    $json_file_path = \Drupal::service('extension.list.module')->getPath('quizgen') . '/data/completions.json';
    
    if (!file_exists($json_file_path)) {
      $this->output()->writeln('❌ No completions file found. Make some AI requests first.');
      return;
    }
    
    $json_content = file_get_contents($json_file_path);
    if ($json_content === false) {
      $this->output()->writeln('❌ Could not read completions file.');
      return;
    }
    
    $data = json_decode($json_content, true);
    if ($data === null) {
      $this->output()->writeln('❌ Invalid JSON in completions file.');
      return;
    }
    
    if (empty($data)) {
      $this->output()->writeln('📄 No completions found in file.');
      return;
    }
    
    // Get the most recent completions.
    $recent_completions = array_slice($data, -$limit);
    
    $this->output()->writeln('📄 Recent AI Completions:');
    $this->output()->writeln('');
    
    foreach ($recent_completions as $completion) {
      $this->output()->writeln('ID: ' . $completion['id']);
      $this->output()->writeln('Request: ' . $completion['request']);
      $this->output()->writeln('Response: ' . substr($completion['response'], 0, 100) . (strlen($completion['response']) > 100 ? '...' : ''));
      $this->output()->writeln('Model: ' . $completion['model']);
      $this->output()->writeln('Tokens: ' . $completion['input_tokens'] . ' in / ' . $completion['output_tokens'] . ' out / ' . $completion['total_tokens'] . ' total');
      $this->output()->writeln('Temperature: ' . $completion['temperature']);
      $this->output()->writeln('Response Time: ' . $completion['response_time'] . 'ms');
      $this->output()->writeln('Created: ' . $completion['created']);
      $this->output()->writeln('---');
    }
    
    $this->output()->writeln('Total completions in file: ' . count($data));
  }

}
