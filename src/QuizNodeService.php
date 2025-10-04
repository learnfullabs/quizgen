<?php

declare(strict_types=1);

namespace Drupal\quizgen;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;

/**
 * Service for creating and managing Quiz nodes.
 */
class QuizNodeService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The quiz metadata service.
   *
   * @var \Drupal\quizgen\QuizMetadataService
   */
  protected $metadataService;

  /**
   * Constructs a QuizNodeService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\quizgen\QuizMetadataService $metadata_service
   *   The quiz metadata service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    AccountProxyInterface $current_user,
    QuizMetadataService $metadata_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
    $this->metadataService = $metadata_service;
  }

  /**
   * Creates a new Quiz node.
   *
   * @param string $title
   *   The title of the quiz node.
   * @param string $quiz_prompt
   *   The quiz prompt content.
   * @param int|null $author_uid
   *   The user ID of the author. If NULL, uses current user.
   * @param array $taxonomy_fields
   *   Optional taxonomy field values with term IDs.
   *   Expected structure: ['field_subject' => term_id, 'field_education_level' => term_id, etc.]
   *
   * @return \Drupal\node\Entity\Node|null
   *   The created node or NULL on failure.
   */
  public function createQuizNode(string $title, string $quiz_prompt, int $author_uid = NULL, array $taxonomy_fields = []): ?Node {
    try {
      // Use provided author UID or current user
      $uid = $author_uid ?? $this->currentUser->id();

      // Build base node data
      $node_data = [
        'type' => 'quiz',
        'title' => $title,
        'field_quiz_prompt' => [
          'value' => $quiz_prompt,
          'format' => 'plain_text',
        ],
        'uid' => $uid,
        'status' => 1, // Published
        'created' => \Drupal::time()->getRequestTime(),
        'changed' => \Drupal::time()->getRequestTime(),
      ];

      // Add taxonomy fields if provided
      foreach ($taxonomy_fields as $field_name => $term_id) {
        if (!empty($term_id)) {
          $node_data[$field_name] = ['target_id' => $term_id];
        }
      }

      // Create the node
      $node = Node::create($node_data);

      // Save the node
      $node->save();

      // Log the creation
      $this->loggerFactory->get('quizgen')->info(
        'Created Quiz node with ID @nid, title "@title", author UID @uid',
        [
          '@nid' => $node->id(),
          '@title' => $title,
          '@uid' => $uid,
        ]
      );

      return $node;

    } catch (\Exception $e) {
      // Log the error
      $this->loggerFactory->get('quizgen')->error(
        'Failed to create Quiz node: @error',
        ['@error' => $e->getMessage()]
      );

      return NULL;
    }
  }

  /**
   * Creates a Quiz node using AI-generated metadata.
   *
   * @param int|null $author_uid
   *   The user ID of the author. If NULL, uses current user.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The created node or NULL on failure.
   */
  public function createAiGeneratedQuizNode(int $author_uid = NULL): ?Node {
    try {
      // Generate metadata using AI
      $metadata = $this->metadataService->generateQuizMetadata();
      
      if (!$metadata) {
        $this->loggerFactory->get('quizgen')->error('Failed to generate AI metadata for quiz node creation.');
        return NULL;
      }

      // Extract taxonomy field values
      $taxonomy_fields = [
        'field_subject' => $metadata['subject']['id'],
        'field_education_level' => $metadata['education_level']['id'],
        'field_difficulty' => $metadata['difficulty']['id'],
        'field_cognitive_goal' => $metadata['cognitive_goal']['id'],
      ];

      // Create the node using the generated metadata
      $node = $this->createQuizNode(
        $metadata['title'],
        $metadata['prompt'],
        $author_uid,
        $taxonomy_fields
      );

      if ($node) {
        $this->loggerFactory->get('quizgen')->info(
          'Created AI-generated Quiz node with ID @nid, subject "@subject", level "@level", difficulty "@difficulty"',
          [
            '@nid' => $node->id(),
            '@subject' => $metadata['subject']['label'],
            '@level' => $metadata['education_level']['label'],
            '@difficulty' => $metadata['difficulty']['label'],
          ]
        );
      }

      return $node;

    } catch (\Exception $e) {
      $this->loggerFactory->get('quizgen')->error(
        'Failed to create AI-generated Quiz node: @error',
        ['@error' => $e->getMessage()]
      );

      return NULL;
    }
  }

  /**
   * Creates a test Quiz node with predefined values.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The created test node or NULL on failure.
   */
  public function createTestQuizNode(): ?Node {
    return $this->createQuizNode(
      'test',
      'calculus',
      1 // Admin user ID
    );
  }

  /**
   * Gets a Quiz node by ID.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The Quiz node or NULL if not found or not a quiz.
   */
  public function getQuizNode(int $nid): ?Node {
    try {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      
      if ($node && $node->bundle() === 'quiz') {
        return $node;
      }
      
      return NULL;
    } catch (\Exception $e) {
      $this->loggerFactory->get('quizgen')->error(
        'Failed to load Quiz node @nid: @error',
        ['@nid' => $nid, '@error' => $e->getMessage()]
      );
      
      return NULL;
    }
  }

  /**
   * Updates a Quiz node's prompt.
   *
   * @param int $nid
   *   The node ID.
   * @param string $new_prompt
   *   The new quiz prompt.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function updateQuizPrompt(int $nid, string $new_prompt): bool {
    try {
      $node = $this->getQuizNode($nid);
      
      if (!$node) {
        return FALSE;
      }

      $node->set('field_quiz_prompt', [
        'value' => $new_prompt,
        'format' => 'plain_text',
      ]);
      
      $node->save();

      $this->loggerFactory->get('quizgen')->info(
        'Updated Quiz node @nid prompt',
        ['@nid' => $nid]
      );

      return TRUE;
    } catch (\Exception $e) {
      $this->loggerFactory->get('quizgen')->error(
        'Failed to update Quiz node @nid: @error',
        ['@nid' => $nid, '@error' => $e->getMessage()]
      );
      
      return FALSE;
    }
  }

}
