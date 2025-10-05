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
   * @param array $tags
   *   Optional array of tag strings for the field_tags field.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The created node or NULL on failure.
   */
  public function createQuizNode(string $title, string $quiz_prompt, int $author_uid = NULL, array $taxonomy_fields = [], array $tags = []): ?Node {
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

      // Create the node first
      $node = Node::create($node_data);

      // Add tags after node creation using the field API
      if (!empty($tags)) {
        $this->loggerFactory->get('quizgen')->info('Attempting to set taxonomy tags: @tags', [
          '@tags' => implode(', ', $tags),
        ]);
        
        // For taxonomy reference fields with auto_create, we can use target_id format
        // The auto_create feature will create new terms if they don't exist
        $tag_references = [];
        
        foreach ($tags as $tag_name) {
          // Try to find existing term first
          $existing_terms = $this->entityTypeManager
            ->getStorage('taxonomy_term')
            ->loadByProperties([
              'name' => $tag_name,
              'vid' => 'tags', // vocabulary machine name
            ]);
          
          if (!empty($existing_terms)) {
            // Use existing term
            $term = reset($existing_terms);
            $tag_references[] = ['target_id' => $term->id()];
            $this->loggerFactory->get('quizgen')->info('Using existing tag term: @tag (ID: @id)', [
              '@tag' => $tag_name,
              '@id' => $term->id(),
            ]);
          } else {
            // Create new taxonomy term
            $new_term = $this->entityTypeManager
              ->getStorage('taxonomy_term')
              ->create([
                'name' => $tag_name,
                'vid' => 'tags',
              ]);
            $new_term->save();
            
            $tag_references[] = ['target_id' => $new_term->id()];
            $this->loggerFactory->get('quizgen')->info('Created new tag term: @tag (ID: @id)', [
              '@tag' => $tag_name,
              '@id' => $new_term->id(),
            ]);
          }
        }
        
        $node->set('field_tags', $tag_references);
        $this->loggerFactory->get('quizgen')->info('Set @count tag references on node', [
          '@count' => count($tag_references),
        ]);
      }

      // Save the node
      $node->save();

      // Verify tags were saved (for debugging)
      if (!empty($tags)) {
        $saved_tags = $node->get('field_tags')->getValue();
        $this->loggerFactory->get('quizgen')->info('Tags verification - Saved field_tags value: @saved_tags', [
          '@saved_tags' => json_encode($saved_tags),
        ]);
      }

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
        $taxonomy_fields,
        $metadata['tags']
      );

      if ($node) {
        $this->loggerFactory->get('quizgen')->info(
          'Created AI-generated Quiz node with ID @nid, subject "@subject", level "@level", difficulty "@difficulty", tags: @tags',
          [
            '@nid' => $node->id(),
            '@subject' => $metadata['subject']['label'],
            '@level' => $metadata['education_level']['label'],
            '@difficulty' => $metadata['difficulty']['label'],
            '@tags' => implode(', ', $metadata['tags']),
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
