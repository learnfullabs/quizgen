<?php

declare(strict_types=1);

namespace Drupal\quizgen\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for QuizGen AI settings.
 */
class QuizgenSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['quizgen.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quizgen_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('quizgen.settings');

    $form['ai_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AI Provider Settings'),
      '#description' => $this->t('Configure the AI provider settings for quiz generation.'),
    ];

    $form['ai_settings']['provider_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AI Provider ID'),
      '#description' => $this->t('The AI provider ID to use (e.g., openai, anthropic).'),
      '#default_value' => $config->get('provider_id') ?? 'openai',
      '#required' => TRUE,
    ];

    $form['ai_settings']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AI Model'),
      '#description' => $this->t('The AI model to use (e.g., gpt-4o, gpt-3.5-turbo).'),
      '#default_value' => $config->get('model') ?? 'gpt-4o',
      '#required' => TRUE,
    ];

    $form['ai_settings']['temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#description' => $this->t('Controls randomness in AI responses. Lower values (0.1-0.3) for more focused responses, higher values (0.7-1.0) for more creative responses.'),
      '#default_value' => $config->get('temperature') ?? 0.7,
      '#min' => 0,
      '#max' => 2,
      '#step' => 0.1,
      '#required' => FALSE,
    ];

    $form['ai_settings']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens'),
      '#description' => $this->t('Maximum number of tokens in the AI response.'),
      '#default_value' => $config->get('max_tokens') ?? 4096,
      '#min' => 0,
      '#max' => 32000,
      '#required' => FALSE,
    ];

    $form['ai_settings']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request Timeout'),
      '#description' => $this->t('Timeout in seconds for AI requests. Increase this if you experience timeout errors with longer requests.'),
      '#default_value' => $config->get('timeout') ?? 120,
      '#min' => 30,
      '#max' => 300,
      '#required' => FALSE,
    ];

    $form['quiz_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Quiz Generation Settings'),
      '#description' => $this->t('Configure default settings for quiz generation.'),
    ];

    $form['quiz_settings']['default_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Quiz Generation Prompt'),
      '#description' => $this->t('The default prompt template used for generating quizzes. Use placeholders like {subject}, {difficulty}, {num_questions}, etc.'),
      '#default_value' => $config->get('default_prompt') ?? $this->getDefaultPrompt(),
      '#rows' => 10,
      '#required' => TRUE,
    ];

    $form['quiz_settings']['default_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Subject'),
      '#description' => $this->t('Default subject for quiz generation.'),
      '#default_value' => $config->get('default_subject') ?? 'General Knowledge',
    ];

    $form['quiz_settings']['default_difficulty'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Difficulty Level'),
      '#description' => $this->t('Default difficulty level for quiz generation.'),
      '#options' => [
        'beginner' => $this->t('Beginner'),
        'intermediate' => $this->t('Intermediate'),
        'advanced' => $this->t('Advanced'),
      ],
      '#default_value' => $config->get('default_difficulty') ?? 'intermediate',
    ];

    $form['quiz_settings']['default_num_questions'] = [
      '#type' => 'number',
      '#title' => $this->t('Default Number of Questions'),
      '#description' => $this->t('Default number of questions to generate per quiz.'),
      '#default_value' => $config->get('default_num_questions') ?? 10,
      '#min' => 1,
      '#max' => 50,
    ];

    $form['quiz_settings']['default_num_options'] = [
      '#type' => 'number',
      '#title' => $this->t('Default Number of Options'),
      '#description' => $this->t('Default number of answer options per question.'),
      '#default_value' => $config->get('default_num_options') ?? 4,
      '#min' => 2,
      '#max' => 6,
    ];

    $form['cron_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cron Generation Settings'),
      '#description' => $this->t('Configure automatic quiz generation via cron.'),
    ];

    $form['cron_settings']['cron_generation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Cron Quiz Generation'),
      '#description' => $this->t('When enabled, the system will automatically generate quiz nodes during cron runs.'),
      '#default_value' => $config->get('cron_generation_enabled') ?? TRUE,
    ];

    $form['cron_settings']['cron_generation_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Generation Interval'),
      '#description' => $this->t('How often to generate new quiz nodes during cron runs.'),
      '#options' => [
        300 => $this->t('Every 5 minutes'),
        600 => $this->t('Every 10 minutes'),
        900 => $this->t('Every 15 minutes'),
        1800 => $this->t('Every 30 minutes'),
        3600 => $this->t('Every hour'),
        7200 => $this->t('Every 2 hours'),
        21600 => $this->t('Every 6 hours'),
        43200 => $this->t('Every 12 hours'),
        86400 => $this->t('Every 24 hours'),
      ],
      '#default_value' => $config->get('cron_generation_interval') ?? 600,
      '#states' => [
        'visible' => [
          ':input[name="cron_generation_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['cron_settings']['cron_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Last Cron Generation'),
      '#markup' => $this->getCronStatusMarkup(),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('quizgen.settings')
      ->set('provider_id', $form_state->getValue('provider_id'))
      ->set('model', $form_state->getValue('model'))
      ->set('temperature', (float) $form_state->getValue('temperature'))
      ->set('max_tokens', (int) $form_state->getValue('max_tokens'))
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->set('default_prompt', $form_state->getValue('default_prompt'))
      ->set('default_subject', $form_state->getValue('default_subject'))
      ->set('default_difficulty', $form_state->getValue('default_difficulty'))
      ->set('default_num_questions', (int) $form_state->getValue('default_num_questions'))
      ->set('default_num_options', (int) $form_state->getValue('default_num_options'))
      ->set('cron_generation_enabled', (bool) $form_state->getValue('cron_generation_enabled'))
      ->set('cron_generation_interval', (int) $form_state->getValue('cron_generation_interval'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the default quiz generation prompt.
   *
   * @return string
   *   The default prompt template.
   */
  protected function getDefaultPrompt(): string {
    return 'Generate a quiz with {num_questions} multiple-choice questions about {subject} at {difficulty} level. Each question should have {num_options} answer options with only one correct answer.

Please format the response as valid JSON with the following structure:
{
  "title": "Quiz Title",
  "subject": "{subject}",
  "difficulty": "{difficulty}",
  "questions": [
    {
      "question": "Question text here?",
      "options": [
        "Option A",
        "Option B",
        "Option C",
        "Option D"
      ],
      "correct_answer": 0,
      "explanation": "Brief explanation of why this is correct"
    }
  ]
}

Make sure the questions are educational, accurate, and appropriate for the specified difficulty level. The correct_answer should be the index (0-based) of the correct option in the options array.';
  }

  /**
   * Returns markup for cron status display.
   *
   * @return string
   *   HTML markup showing cron status information.
   */
  protected function getCronStatusMarkup(): string {
    $last_run = \Drupal::state()->get('quizgen.last_cron_run', 0);
    $config = $this->config('quizgen.settings');
    $interval = $config->get('cron_generation_interval') ?? 600;
    $enabled = $config->get('cron_generation_enabled') ?? TRUE;
    
    if (!$enabled) {
      return '<em>' . $this->t('Cron generation is currently disabled.') . '</em>';
    }
    
    if ($last_run == 0) {
      return '<em>' . $this->t('No quiz has been generated via cron yet.') . '</em>';
    }
    
    $last_run_formatted = \Drupal::service('date.formatter')->format($last_run, 'medium');
    $next_run = $last_run + $interval;
    $next_run_formatted = \Drupal::service('date.formatter')->format($next_run, 'medium');
    $current_time = \Drupal::time()->getRequestTime();
    
    $status_items = [
      $this->t('Last generation: @time', ['@time' => $last_run_formatted]),
      $this->t('Next generation: @time', ['@time' => $next_run_formatted]),
    ];
    
    if ($current_time >= $next_run) {
      $status_items[] = '<strong>' . $this->t('Ready for next generation (will run on next cron)') . '</strong>';
    } else {
      $time_remaining = $next_run - $current_time;
      $minutes_remaining = ceil($time_remaining / 60);
      $status_items[] = $this->t('Next generation in approximately @minutes minutes', ['@minutes' => $minutes_remaining]);
    }
    
    return '<div>' . implode('<br>', $status_items) . '</div>';
  }

}
