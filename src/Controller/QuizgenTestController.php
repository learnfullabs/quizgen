<?php

declare(strict_types=1);

namespace Drupal\quizgen\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\quizgen\QuizNodeService;
use Drupal\quizgen\QuizMetadataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for testing the Quizgen services.
 */
class QuizgenTestController extends ControllerBase {

  /**
   * The Quizgen Node service.
   *
   * @var \Drupal\quizgen\QuizNodeService
   */
  protected $nodeService;

  /**
   * The Quizgen Metadata service.
   *
   * @var \Drupal\quizgen\QuizMetadataService
   */
  protected $metadataService;

  /**
   * Constructs a QuizgenTestController object.
   *
   * @param \Drupal\quizgen\QuizNodeService $node_service
   *   The Quizgen Node service.
   * @param \Drupal\quizgen\QuizMetadataService $metadata_service
   *   The Quizgen Metadata service.
   */
  public function __construct(QuizNodeService $node_service, QuizMetadataService $metadata_service) {
    $this->nodeService = $node_service;
    $this->metadataService = $metadata_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quizgen.node_service'),
      $container->get('quizgen.metadata_service')
    );
  }


  /**
   * Test creating a Quiz node.
   *
   * @return array
   *   A render array.
   */
  public function testCreateQuizNode() {
    $create_node = \Drupal::request()->query->get('create', '');
    
    $items = [];
    $status = 'ready';
    $node = NULL;
    
    if ($create_node === 'yes') {
      try {
        // Create the test node
        $node = $this->nodeService->createTestQuizNode();
        
        if ($node) {
          $this->messenger()->addStatus($this->t('Quiz node created successfully!'));
          $status = 'success';
          $items[] = $this->t('Node ID: @nid', ['@nid' => $node->id()]);
          $items[] = $this->t('Title: @title', ['@title' => $node->getTitle()]);
          $items[] = $this->t('Quiz Prompt: @prompt', ['@prompt' => $node->get('field_quiz_prompt')->value]);
          $items[] = $this->t('Author: @author (UID: @uid)', [
            '@author' => $node->getOwner()->getDisplayName(),
            '@uid' => $node->getOwnerId()
          ]);
          $items[] = $this->t('Created: @created', ['@created' => \Drupal::service('date.formatter')->format($node->getCreatedTime())]);
          $items[] = $this->t('Status: @status', ['@status' => $node->isPublished() ? 'Published' : 'Unpublished']);
        } else {
          $this->messenger()->addError($this->t('Failed to create Quiz node. Check the logs.'));
          $status = 'error';
          $items[] = $this->t('Node creation failed');
        }
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('Error: @error', ['@error' => $e->getMessage()]));
        $status = 'error';
        $items[] = $this->t('Exception occurred: @error', ['@error' => $e->getMessage()]);
      }
    } else {
      $items[] = $this->t('Click the button below to create a test Quiz node');
      $items[] = $this->t('Test parameters:');
      $items[] = $this->t('- Title: test');
      $items[] = $this->t('- Quiz Prompt: calculus');
      $items[] = $this->t('- Author: admin (UID: 1)');
      $items[] = $this->t('- Status: Published');
    }

    $suffix = '<div style="margin-top: 20px;">';
    if ($status !== 'success') {
      $suffix .= '<p><a href="?create=yes" class="button button--primary">Create Test Quiz Node</a></p>';
    } else {
      $suffix .= '<p><a href="/node/' . $node->id() . '">View Created Node</a> | ';
      $suffix .= '<a href="/node/' . $node->id() . '/edit">Edit Node</a> | ';
      $suffix .= '<a href="?">Reset Test</a></p>';
    }
    $suffix .= '<p><a href="/admin/config/quizgen/test-metadata">Metadata Generation Test</a> | ';
    $suffix .= '<a href="/admin/config/quizgen/test-ai-node">AI Node Creation Test</a></p>';
    $suffix .= '</div>';

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Quiz Node Creation Test'),
      '#items' => $items,
      '#suffix' => $suffix,
    ];
  }

  /**
   * Test the metadata generation functionality.
   *
   * @return array
   *   A render array.
   */
  public function testMetadataGeneration() {
    $generate = \Drupal::request()->query->get('generate', '');
    
    $items = [];
    $status = 'ready';
    $metadata = NULL;
    
    if ($generate === 'yes') {
      try {
        $metadata = $this->metadataService->generateQuizMetadata();
        
        if ($metadata) {
          $this->messenger()->addStatus($this->t('Metadata generated successfully!'));
          $status = 'success';
          $items[] = $this->t('Title: @title', ['@title' => $metadata['title']]);
          $items[] = $this->t('Generated Prompt: @prompt', ['@prompt' => $metadata['prompt']]);
          $items[] = $this->t('Subject: @label (ID: @id)', [
            '@label' => $metadata['subject']['label'],
            '@id' => $metadata['subject']['id']
          ]);
          $items[] = $this->t('Education Level: @label (ID: @id)', [
            '@label' => $metadata['education_level']['label'],
            '@id' => $metadata['education_level']['id']
          ]);
          $items[] = $this->t('Difficulty: @label (ID: @id)', [
            '@label' => $metadata['difficulty']['label'],
            '@id' => $metadata['difficulty']['id']
          ]);
          $items[] = $this->t('Cognitive Goal: @label (ID: @id)', [
            '@label' => $metadata['cognitive_goal']['label'],
            '@id' => $metadata['cognitive_goal']['id']
          ]);
        } else {
          $this->messenger()->addError($this->t('Metadata generation failed. Check the logs.'));
          $status = 'error';
          $items[] = $this->t('Metadata generation failed');
        }
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('Error: @error', ['@error' => $e->getMessage()]));
        $status = 'error';
        $items[] = $this->t('Exception occurred: @error', ['@error' => $e->getMessage()]);
      }
    } else {
      $items[] = $this->t('Click the button below to generate a complete quiz with AI');
      $items[] = $this->t('The AI will generate:');
      $items[] = $this->t('- A random educational quiz topic');
      $items[] = $this->t('- A detailed quiz prompt/description');
      $items[] = $this->t('- An appropriate title');
      $items[] = $this->t('- Subject classification');
      $items[] = $this->t('- Education level');
      $items[] = $this->t('- Difficulty level');
      $items[] = $this->t('- Cognitive learning goal');
    }

    $suffix = '<div style="margin-top: 20px;">';
    if ($status !== 'success') {
      $suffix .= '<p><a href="?generate=yes" class="button button--primary">Generate Random Quiz Metadata</a></p>';
    } else {
      $suffix .= '<p><a href="?">Generate Another Quiz</a></p>';
    }
    $suffix .= '<p><a href="/admin/config/quizgen/test-create-node">Node Creation Test</a> | ';
    $suffix .= '<a href="/admin/config/quizgen/test-ai-node">AI Node Creation Test</a></p>';
    
    if ($metadata) {
      $suffix .= '<div style="margin-top: 20px;"><h3>Generated Metadata JSON:</h3><pre style="background: #f5f5f5; padding: 10px; overflow: auto; max-height: 300px;">' . htmlspecialchars(json_encode($metadata, JSON_PRETTY_PRINT)) . '</pre></div>';
    }
    
    $suffix .= '</div>';

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Quiz Metadata Generation Test'),
      '#items' => $items,
      '#suffix' => $suffix,
    ];
  }

  /**
   * Test creating an AI-generated Quiz node.
   *
   * @return array
   *   A render array.
   */
  public function testCreateAiQuizNode() {
    $create_node = \Drupal::request()->query->get('create', '');
    
    $items = [];
    $status = 'ready';
    $node = NULL;
    
    if ($create_node === 'yes') {
      try {
        // Create the AI-generated node
        $node = $this->nodeService->createAiGeneratedQuizNode();
        
        if ($node) {
          $this->messenger()->addStatus($this->t('AI-generated Quiz node created successfully!'));
          $status = 'success';
          $items[] = $this->t('Node ID: @nid', ['@nid' => $node->id()]);
          $items[] = $this->t('Title: @title', ['@title' => $node->getTitle()]);
          $items[] = $this->t('Quiz Prompt: @prompt', ['@prompt' => substr($node->get('field_quiz_prompt')->value, 0, 200) . '...']);
          
          // Display taxonomy field values
          if ($node->hasField('field_subject') && !$node->get('field_subject')->isEmpty()) {
            $subject_term = $node->get('field_subject')->entity;
            $items[] = $this->t('Subject: @subject', ['@subject' => $subject_term ? $subject_term->getName() : 'N/A']);
          }
          
          if ($node->hasField('field_education_level') && !$node->get('field_education_level')->isEmpty()) {
            $level_term = $node->get('field_education_level')->entity;
            $items[] = $this->t('Education Level: @level', ['@level' => $level_term ? $level_term->getName() : 'N/A']);
          }
          
          if ($node->hasField('field_difficulty') && !$node->get('field_difficulty')->isEmpty()) {
            $difficulty_term = $node->get('field_difficulty')->entity;
            $items[] = $this->t('Difficulty: @difficulty', ['@difficulty' => $difficulty_term ? $difficulty_term->getName() : 'N/A']);
          }
          
          if ($node->hasField('field_cognitive_goal') && !$node->get('field_cognitive_goal')->isEmpty()) {
            $cognitive_term = $node->get('field_cognitive_goal')->entity;
            $items[] = $this->t('Cognitive Goal: @goal', ['@goal' => $cognitive_term ? $cognitive_term->getName() : 'N/A']);
          }
          
          $items[] = $this->t('Author: @author (UID: @uid)', [
            '@author' => $node->getOwner()->getDisplayName(),
            '@uid' => $node->getOwnerId()
          ]);
          $items[] = $this->t('Created: @created', ['@created' => \Drupal::service('date.formatter')->format($node->getCreatedTime())]);
          $items[] = $this->t('Status: @status', ['@status' => $node->isPublished() ? 'Published' : 'Unpublished']);
        } else {
          $this->messenger()->addError($this->t('Failed to create AI-generated Quiz node. Check the logs.'));
          $status = 'error';
          $items[] = $this->t('Node creation failed');
        }
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('Error: @error', ['@error' => $e->getMessage()]));
        $status = 'error';
        $items[] = $this->t('Exception occurred: @error', ['@error' => $e->getMessage()]);
      }
    } else {
      $items[] = $this->t('Click the button below to create an AI-generated Quiz node');
      $items[] = $this->t('This will:');
      $items[] = $this->t('- Generate random educational metadata (subject, level, difficulty, cognitive goal)');
      $items[] = $this->t('- Create a topic that fits the metadata');
      $items[] = $this->t('- Generate a detailed quiz prompt');
      $items[] = $this->t('- Create an engaging title');
      $items[] = $this->t('- Create a complete Quiz node with all fields populated');
    }

    $suffix = '<div style="margin-top: 20px;">';
    if ($status !== 'success') {
      $suffix .= '<p><a href="?create=yes" class="button button--primary">Create AI-Generated Quiz Node</a></p>';
    } else {
      $suffix .= '<p><a href="/node/' . $node->id() . '">View Created Node</a> | ';
      $suffix .= '<a href="/node/' . $node->id() . '/edit">Edit Node</a> | ';
      $suffix .= '<a href="?">Create Another AI Node</a></p>';
    }
    $suffix .= '<p><a href="/admin/config/quizgen/test-create-node">Manual Node Creation Test</a> | ';
    $suffix .= '<a href="/admin/config/quizgen/test-metadata">Metadata Generation Test</a></p>';
    $suffix .= '</div>';

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('AI-Generated Quiz Node Creation Test'),
      '#items' => $items,
      '#suffix' => $suffix,
    ];
  }

  /**
   * Test cron quiz generation functionality.
   *
   * @return array
   *   A render array for the test page.
   */
  public function testCronGeneration() {
    $request = \Drupal::request();
    $trigger_cron = $request->query->get('trigger');
    
    $items = [];
    $status = 'info';
    
    // Get current cron settings
    $config = \Drupal::config('quizgen.settings');
    $cron_enabled = $config->get('cron_generation_enabled') ?? TRUE;
    $interval = $config->get('cron_generation_interval') ?? 600;
    $last_run = \Drupal::state()->get('quizgen.last_cron_run', 0);
    
    $items[] = $this->t('Cron Generation Status: @status', [
      '@status' => $cron_enabled ? 'Enabled' : 'Disabled'
    ]);
    $items[] = $this->t('Generation Interval: @interval seconds (@minutes minutes)', [
      '@interval' => $interval,
      '@minutes' => round($interval / 60, 1)
    ]);
    
    if ($last_run > 0) {
      $items[] = $this->t('Last Generation: @time', [
        '@time' => \Drupal::service('date.formatter')->format($last_run, 'medium')
      ]);
    } else {
      $items[] = $this->t('No cron generation has run yet');
    }
    
    if ($trigger_cron === 'yes') {
      try {
        $this->messenger()->addStatus($this->t('Triggering cron quiz generation...'));
        
        // Manually call the cron function
        quizgen_cron();
        
        $this->messenger()->addStatus($this->t('Cron function executed successfully. Check the results above.'));
        $status = 'success';
        
        // Refresh the last run time
        $new_last_run = \Drupal::state()->get('quizgen.last_cron_run', 0);
        if ($new_last_run > $last_run) {
          $items[] = $this->t('✓ New quiz generated at: @time', [
            '@time' => \Drupal::service('date.formatter')->format($new_last_run, 'medium')
          ]);
        } else {
          $items[] = $this->t('No new quiz was generated (may be due to interval restrictions)');
        }
        
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('Error during cron execution: @error', ['@error' => $e->getMessage()]));
        $status = 'error';
        $items[] = $this->t('Exception occurred: @error', ['@error' => $e->getMessage()]);
      }
    } else {
      $items[] = $this->t('Click the button below to manually trigger cron quiz generation');
      $items[] = $this->t('This will:');
      $items[] = $this->t('- Check if cron generation is enabled');
      $items[] = $this->t('- Verify the time interval has passed');
      $items[] = $this->t('- Generate a new quiz node using AI metadata');
      $items[] = $this->t('- Update the last run timestamp');
    }

    $suffix = '<div style="margin-top: 20px;">';
    if ($status !== 'success') {
      $suffix .= '<p><a href="?trigger=yes" class="button button--primary">Trigger Cron Generation</a></p>';
    } else {
      $suffix .= '<p><a href="?">Test Again</a></p>';
    }
    $suffix .= '<p><a href="/admin/config/quizgen/settings">Configure Cron Settings</a> | ';
    $suffix .= '<a href="/admin/config/quizgen/test-ai-node">AI Node Test</a> | ';
    $suffix .= '<a href="/admin/config/quizgen/test-metadata">Metadata Test</a></p>';
    $suffix .= '</div>';

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Cron Quiz Generation Test'),
      '#items' => $items,
      '#suffix' => $suffix,
    ];
  }

}
