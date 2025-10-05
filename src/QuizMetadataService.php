<?php

declare(strict_types=1);

namespace Drupal\quizgen;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Service for generating quiz metadata using AI.
 */
class QuizMetadataService {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected $aiProviderManager;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The path to the JSON completions file.
   *
   * @var string
   */
  protected $jsonFilePath;

  /**
   * Constructs a QuizMetadataService object.
   *
   * @param \Drupal\ai\AiProviderPluginManager $ai_provider_manager
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(AiProviderPluginManager $ai_provider_manager, LoggerChannelFactoryInterface $logger_factory, FileSystemInterface $file_system, ConfigFactoryInterface $config_factory) {
    $this->aiProviderManager = $ai_provider_manager;
    $this->logger = $logger_factory->get('quizgen');
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
    
    // Set the path to the JSON file in the module's data directory.
    $this->jsonFilePath = \Drupal::service('extension.list.module')->getPath('quizgen') . '/data/metadata_completions.json';
  }

  /**
   * Makes a simple AI chat request.
   *
   * @param string $message
   *   The message to send to the AI.
   * @param string $provider_id
   *   The AI provider ID (uses config default if not specified).
   * @param string $model
   *   The AI model to use (uses config default if not specified).
   * @param float $temperature
   *   The temperature setting for the AI request (uses config default if not specified).
   * @param int $timeout
   *   The timeout in seconds for the AI request (uses config default if not specified).
   *
   * @return string|null
   *   The AI response text, or NULL on error.
   */
  public function makeAiRequest(string $message = 'hello', string $provider_id = '', string $model = '', float $temperature = 0, int $timeout = 0): ?string {
    $start_time = microtime(true);
    
    // Get configuration settings.
    $config_settings = $this->configFactory->get('quizgen.settings');
    
    // Use provided parameters or fall back to configuration defaults.
    $provider_id = $provider_id ?: ($config_settings->get('provider_id') ?? 'openai');
    $model = $model ?: ($config_settings->get('model') ?? 'gpt-4o');
    $temperature = $temperature ?: ($config_settings->get('temperature') ?? 0.7);
    $max_tokens = $config_settings->get('max_tokens') ?? 4096;
    $timeout = $timeout ?: ($config_settings->get('timeout') ?? 90);
    
    // Ensure temperature, max_tokens, and timeout have valid values even if config is empty.
    $temperature = $temperature ?: 0.7;
    $max_tokens = $max_tokens ?: 4096;
    $timeout = $timeout ?: 90;
    
    try {
      // Check if we're using GPT-5 model (which doesn't support temperature and max_tokens).
      $is_gpt5 = strpos(strtolower($model), 'gpt-5') === 0;
      
      // Configuration for the AI provider.
      $config = [
        "frequency_penalty" => 0,
        "presence_penalty" => 0,
        "top_p" => 1,
      ];
      
      // Only add temperature and max_tokens for non-GPT-5 models.
      if (!$is_gpt5) {
        $config["temperature"] = $temperature;
        $config["max_tokens"] = $max_tokens;
      }
      
      // Create a chat input with the provided message.
      $input = new ChatInput([
        new ChatMessage("user", $message),
      ]);

      // Get the AI provider service and create an instance with custom timeout.
      $provider_config = [
        'http_client_options' => [
          'timeout' => $timeout,
        ],
      ];
      $ai_provider = $this->aiProviderManager->createInstance($provider_id, $provider_config);
      $ai_provider->setConfiguration($config);
      
      // Make the chat request.
      $response = $ai_provider->chat($input, $model, ["quizgen"]);
      $normalized_response = $response->getNormalized();
      $response_text = $normalized_response->getText();
      
      // Calculate response time in milliseconds.
      $end_time = microtime(true);
      $response_time = (int) (($end_time - $start_time) * 1000);
      
      // Get token usage from the raw response if available.
      $raw_response = $response->getRawOutput();
      $input_tokens = $raw_response['usage']['prompt_tokens'] ?? $this->estimateTokens($message);
      $output_tokens = $raw_response['usage']['completion_tokens'] ?? $this->estimateTokens($response_text);
      $total_tokens = $raw_response['usage']['total_tokens'] ?? ($input_tokens + $output_tokens);
      
      // Log to JSON file.
      $this->logToJsonFile($message, $response_text, $model, $input_tokens, $output_tokens, $total_tokens, $temperature, $response_time);
      
      // Log the AI response to Drupal logs.
      $this->logger->info('AI Metadata Request - Message: @message, Response: @response', [
        '@message' => $message,
        '@response' => $response_text,
      ]);
      
      return $response_text;
      
    } catch (\Exception $e) {
      // Log any errors that occur.
      $this->logger->error('Error making AI metadata request: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return null;
    }
  }

  /**
   * Generates quiz metadata using AI in four steps.
   *
   * @return array|null
   *   Array containing metadata fields, or NULL on error.
   *   Expected structure:
   *   - title: string
   *   - prompt: string (generated quiz prompt)
   *   - subject: array (taxonomy term with id and label)
   *   - education_level: array (taxonomy term with id and label)
   *   - difficulty: array (taxonomy term with id and label)
   *   - cognitive_goal: array (taxonomy term with id and label)
   */
  public function generateQuizMetadata(): ?array {
    // Step 1: Generate random metadata (subject, education level, difficulty, cognitive goal)
    $base_metadata = $this->generateBaseMetadata();
    if ($base_metadata === null) {
      $this->logger->error('Failed to generate base metadata.');
      return null;
    }

    // Step 2: Generate a quiz topic that fits the metadata
    $quiz_topic = $this->generateTopicFromMetadata($base_metadata);
    if ($quiz_topic === null) {
      $this->logger->error('Failed to generate quiz topic from metadata.');
      return null;
    }

    // Step 3: Generate the quiz prompt and title from the topic
    $prompt_and_title = $this->generatePromptFromTopic($quiz_topic, $base_metadata);
    if ($prompt_and_title === null) {
      $this->logger->error('Failed to generate quiz prompt and title for topic: @topic', [
        '@topic' => $quiz_topic,
      ]);
      return null;
    }

    // Combine all generated content
    $metadata = $base_metadata;
    $metadata['title'] = $prompt_and_title['title'];
    $metadata['prompt'] = $prompt_and_title['prompt'];

    $this->logger->info('Successfully generated complete quiz metadata for topic: @topic', [
      '@topic' => substr($quiz_topic, 0, 100) . (strlen($quiz_topic) > 100 ? '...' : ''),
    ]);

    return $metadata;
  }

  /**
   * Step 1: Generate random base metadata using AI.
   *
   * @return array|null
   *   Array containing base metadata fields, or NULL on error.
   */
  protected function generateBaseMetadata(): ?array {
    $ai_prompt = $this->buildBaseMetadataPrompt();
    
    $response = $this->makeAiRequest($ai_prompt);
    
    if ($response === null) {
      $this->logger->error('Failed to get AI response for base metadata generation.');
      return null;
    }

    // Clean the response by removing markdown code blocks if present.
    $cleaned_response = $this->cleanJsonResponse($response);
    
    // Parse the JSON response.
    $metadata = json_decode($cleaned_response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger->error('Failed to parse AI response as JSON for base metadata generation. JSON Error: @json_error, Original response: @original, Cleaned response: @cleaned', [
        '@json_error' => json_last_error_msg(),
        '@original' => $response,
        '@cleaned' => $cleaned_response,
      ]);
      return null;
    }

    // Log the parsed metadata for debugging
    $this->logger->info('Parsed base metadata from AI: @metadata', [
      '@metadata' => json_encode($metadata, JSON_PRETTY_PRINT),
    ]);

    // Validate required fields.
    $required_fields = ['subject', 'education_level', 'difficulty', 'cognitive_goal'];
    foreach ($required_fields as $field) {
      if (!isset($metadata[$field]) || empty($metadata[$field])) {
        $this->logger->error('Missing required field @field in AI base metadata response. Available fields: @available', [
          '@field' => $field,
          '@available' => implode(', ', array_keys($metadata)),
        ]);
        
        // Try fallback with default values
        return $this->getFallbackBaseMetadata();
      }
    }

    // Validate taxonomy fields have both id and label.
    foreach ($required_fields as $field) {
      if (!is_array($metadata[$field]) || 
          !isset($metadata[$field]['id']) || 
          !isset($metadata[$field]['label']) ||
          !is_numeric($metadata[$field]['id'])) {
        $this->logger->error('Invalid taxonomy field @field structure in AI base metadata response. Expected array with id and label.', [
          '@field' => $field,
        ]);
        return null;
      }
    }

    // Validate term IDs are within expected ranges.
    $valid_term_ids = [
      'subject' => [9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20],
      'education_level' => [1, 2, 3, 4, 5, 6, 7, 8],
      'difficulty' => [21, 22, 23],
      'cognitive_goal' => [24, 25, 26, 27, 28, 29],
    ];

    foreach ($required_fields as $field) {
      $term_id = (int) $metadata[$field]['id'];
      if (!in_array($term_id, $valid_term_ids[$field])) {
        $this->logger->error('Invalid term ID @id for field @field in AI base metadata response.', [
          '@id' => $term_id,
          '@field' => $field,
        ]);
        return null;
      }
    }

    return $metadata;
  }

  /**
   * Provides fallback base metadata when AI generation fails.
   *
   * @return array
   *   Fallback metadata with random but valid combinations.
   */
  protected function getFallbackBaseMetadata(): array {
    // Define valid options
    $subjects = [
      ['id' => 9, 'label' => 'Arts & Humanities'],
      ['id' => 10, 'label' => 'Business & Economics'],
      ['id' => 11, 'label' => 'Computer Science & Technology'],
      ['id' => 12, 'label' => 'Education'],
      ['id' => 13, 'label' => 'Health & Medicine'],
      ['id' => 14, 'label' => 'Language & Literature'],
      ['id' => 15, 'label' => 'Law & Political Science'],
      ['id' => 16, 'label' => 'Mathematics & Statistics'],
      ['id' => 17, 'label' => 'Science'],
      ['id' => 18, 'label' => 'Social Sciences'],
      ['id' => 19, 'label' => 'Professional Studies'],
      ['id' => 20, 'label' => 'Other'],
    ];

    $education_levels = [
      ['id' => 1, 'label' => 'Any Level'],
      ['id' => 2, 'label' => 'Pre-K to Grade 2'],
      ['id' => 3, 'label' => 'Grade 3-5'],
      ['id' => 4, 'label' => 'Grade 6-8'],
      ['id' => 5, 'label' => 'Grade 9-12'],
      ['id' => 6, 'label' => 'Undergraduate'],
      ['id' => 7, 'label' => 'Graduate'],
      ['id' => 8, 'label' => 'Adult Learning'],
    ];

    $difficulties = [
      ['id' => 21, 'label' => 'Easy'],
      ['id' => 22, 'label' => 'Medium'],
      ['id' => 23, 'label' => 'Hard'],
    ];

    $cognitive_goals = [
      ['id' => 24, 'label' => 'Remember'],
      ['id' => 25, 'label' => 'Understand'],
      ['id' => 26, 'label' => 'Apply'],
      ['id' => 27, 'label' => 'Analyze'],
      ['id' => 28, 'label' => 'Evaluate'],
      ['id' => 29, 'label' => 'Create'],
    ];

    // Use timestamp for some randomness
    $seed = time() % 100;
    
    $fallback_metadata = [
      'subject' => $subjects[$seed % count($subjects)],
      'education_level' => $education_levels[$seed % count($education_levels)],
      'difficulty' => $difficulties[$seed % count($difficulties)],
      'cognitive_goal' => $cognitive_goals[$seed % count($cognitive_goals)],
    ];

    $this->logger->warning('Using fallback base metadata due to AI generation failure: @metadata', [
      '@metadata' => json_encode($fallback_metadata, JSON_PRETTY_PRINT),
    ]);

    return $fallback_metadata;
  }

  /**
   * Step 2: Generate a quiz topic that fits the metadata using AI.
   *
   * @param array $base_metadata
   *   The base metadata containing subject, education level, difficulty, cognitive goal.
   *
   * @return string|null
   *   The generated quiz topic, or NULL on error.
   */
  protected function generateTopicFromMetadata(array $base_metadata): ?string {
    $ai_prompt = $this->buildTopicFromMetadataPrompt($base_metadata);
    
    $response = $this->makeAiRequest($ai_prompt);
    
    if ($response === null) {
      $this->logger->error('Failed to get AI response for topic generation from metadata.');
      return null;
    }

    // Clean the response and extract just the topic text
    $cleaned_response = trim($response);
    
    // Remove any markdown formatting or quotes if present
    $cleaned_response = preg_replace('/^["\'`]+|["\'`]+$/m', '', $cleaned_response);
    $cleaned_response = trim($cleaned_response);

    if (empty($cleaned_response)) {
      $this->logger->error('Empty quiz topic generated from AI response.');
      return null;
    }

    return $cleaned_response;
  }

  /**
   * Step 3: Generate a quiz prompt and title from a topic using AI.
   *
   * @param string $quiz_topic
   *   The quiz topic.
   * @param array $base_metadata
   *   The base metadata for context.
   *
   * @return array|null
   *   Array with 'prompt' and 'title' keys, or NULL on error.
   */
  protected function generatePromptFromTopic(string $quiz_topic, array $base_metadata): ?array {
    $ai_prompt = $this->buildPromptFromTopicPrompt($quiz_topic, $base_metadata);
    
    $response = $this->makeAiRequest($ai_prompt);
    
    if ($response === null) {
      $this->logger->error('Failed to get AI response for quiz prompt and title generation.');
      return null;
    }

    // Parse the response to extract prompt and title
    $lines = explode("\n", trim($response));
    $prompt = null;
    $title = null;
    
    foreach ($lines as $line) {
      $line = trim($line);
      if (preg_match('/^PROMPT:\s*(.+)$/i', $line, $matches)) {
        $prompt = trim($matches[1]);
      } elseif (preg_match('/^TITLE:\s*(.+)$/i', $line, $matches)) {
        $title = trim($matches[1]);
      }
    }
    
    // Clean any markdown formatting or quotes
    if ($prompt) {
      $prompt = preg_replace('/^["\'`]+|["\'`]+$/m', '', $prompt);
      $prompt = trim($prompt);
    }
    
    if ($title) {
      $title = preg_replace('/^["\'`]+|["\'`]+$/m', '', $title);
      $title = trim($title);
    }

    if (empty($prompt) || empty($title)) {
      $this->logger->error('Failed to parse prompt and/or title from AI response: @response', [
        '@response' => substr($response, 0, 200) . (strlen($response) > 200 ? '...' : ''),
      ]);
      return null;
    }

    return [
      'prompt' => $prompt,
      'title' => $title,
    ];
  }



  /**
   * Generates random education level, subject, and difficulty combination using local PHP logic.
   *
   * @return array
   *   Array containing education_level, subject, and difficulty with id and label keys.
   */
  protected function generateEducationLevelSubjectAndDifficulty(): array {
    $education_levels = [
      1 => "Any Level (general knowledge, broad appeal)",
      2 => "Pre-K to Grade 2 (early childhood, basic concepts)",
      3 => "Grade 3-5 (elementary, foundational skills)",
      4 => "Grade 6-8 (middle school, developing complexity)",
      5 => "Grade 9-12 (high school, advanced concepts)",
      6 => "Undergraduate (college-level, specialized knowledge)",
      7 => "Graduate (advanced study, research-level)",
      8 => "Adult Learning (professional development, continuing education)"
    ];

    $subjects = [
      9  => "Arts & Humanities",
      10 => "Business & Economics",
      11 => "Technology & Computer Science",
      12 => "Education",
      13 => "Health & Physical Education",
      14 => "Language & Literacy",
      15 => "Law & Political Science",
      16 => "Mathematics",
      17 => "Science",
      18 => "Social Studies",
      19 => "Professional & Career Studies",
      20 => "Interdisciplinary / Other",
      30 => "Environmental & Earth Studies",
      31 => "Life Skills & Personal Development",
    ];

    $difficulties = [
      21 => "Easy (basic recall, simple concepts, introductory level)",
      22 => "Medium (moderate complexity, some analysis required)",
      23 => "Hard (advanced concepts, complex problem-solving)",
    ];

    // Map education levels to appropriate subjects
    $gradeToSubjects = [
      1 => [9,10,11,13,14,16,17,18,20,30,31], // Any Level - exclude Law/Education/Professional
      2 => [9,11,13,14,16,17,18,20,30,31],     // Pre-K to Grade 2 - basic subjects
      3 => [9,10,11,13,14,16,17,18,20,30,31],  // Grade 3-5 - add Business basics
      4 => [9,10,11,13,14,15,16,17,18,20,30,31], // Grade 6-8 - add Law/Political Science
      5 => [9,10,11,13,14,15,16,17,18,19,20,30,31], // Grade 9-12 - add Professional Studies
      6 => [9,10,11,12,13,14,15,16,17,18,19,20,30,31], // Undergraduate - add Education
      7 => [9,10,11,12,13,14,15,16,17,18,19,20,30,31], // Graduate - all subjects
      8 => [9,10,11,12,13,14,15,16,17,18,19,20,30,31], // Adult Learning - all subjects
    ];

    // Pick random education level
    $education_id = array_rand($education_levels);
    $education_label = $education_levels[$education_id];

    // Pick random subject appropriate for the education level
    $available_subjects = $gradeToSubjects[$education_id];
    $subject_id = $available_subjects[array_rand($available_subjects)];
    $subject_label = $subjects[$subject_id];

    // Pick random difficulty with weighted selection (favoring Medium)
    // Weight: Easy=1, Medium=2, Hard=1 (total=4)
    $difficulty_weights = [
      21 => 1,  // Easy
      22 => 4,  // Medium
      23 => 1   // Hard
    ];

    $expanded_difficulties = [];
    foreach ($difficulty_weights as $id => $weight) {
      for ($i = 0; $i < $weight; $i++) {
        $expanded_difficulties[] = $id;
      }
    }

    $difficulty_id = $expanded_difficulties[array_rand($expanded_difficulties)];
    $difficulty_label = $difficulties[$difficulty_id];

    return [
      'education_level' => [
        'id' => $education_id,
        'label' => $education_label
      ],
      'subject' => [
        'id' => $subject_id,
        'label' => $subject_label
      ],
      'difficulty' => [
        'id' => $difficulty_id,
        'label' => $difficulty_label
      ]
    ];
  }

  /**
   * Builds the AI prompt for generating random base metadata.
   *
   * @return string
   *   The formatted AI prompt for step 1.
   */
  protected function buildBaseMetadataPrompt(): string {
    // Pre-select education level, subject, and difficulty using local PHP logic
    $preselected = $this->generateEducationLevelSubjectAndDifficulty();
    $education_level = $preselected['education_level'];
    $subject = $preselected['subject'];
    $difficulty = $preselected['difficulty'];
    
    return "Generate ONE educational quiz metadata combination using COGNITIVE GOAL from the list below. Make sure the combination is educationally appropriate.

REQUIRED EDUCATION LEVEL (already selected):
- {$education_level['id']}: {$education_level['label']}

REQUIRED SUBJECT (already selected):
- {$subject['id']}: {$subject['label']}

REQUIRED DIFFICULTY LEVEL (already selected):
- {$difficulty['id']}: {$difficulty['label']}

Now choose COGNITIVE GOAL:

COGNITIVE GOALS (choose one):
- 24: Remember (recall facts, memorize information)
- 25: Understand (comprehend concepts, explain ideas)
- 26: Apply (use knowledge in new situations)
- 27: Analyze (break down information, examine relationships)
- 28: Evaluate (make judgments, assess quality)
- 29: Create (produce new content, synthesize ideas)

Respond with EXACTLY this JSON format (replace the values but keep the structure):
{
  \"subject\": {\"id\": {$subject['id']}, \"label\": \"{$subject['label']}\"},
  \"education_level\": {\"id\": {$education_level['id']}, \"label\": \"{$education_level['label']}\"},
  \"difficulty\": {\"id\": {$difficulty['id']}, \"label\": \"{$difficulty['label']}\"},
  \"cognitive_goal\": {\"id\": 25, \"label\": \"Understand\"}
}

CRITICAL REQUIREMENTS:
- Respond with EXACTLY ONE JSON object, no additional text or multiple objects
- Use the exact field names: subject, education_level, difficulty, cognitive_goal
- Each field must have both \"id\" (integer) and \"label\" (string)
- The subject MUST be exactly: {\"id\": {$subject['id']}, \"label\": \"{$subject['label']}\"}
- The education_level MUST be exactly: {\"id\": {$education_level['id']}, \"label\": \"{$education_level['label']}\"}
- The difficulty MUST be exactly: {\"id\": {$difficulty['id']}, \"label\": \"{$difficulty['label']}\"}
- Make educationally appropriate cognitive goal selection for the given requirements";
  }

  /**
   * Builds the AI prompt for generating a topic from metadata.
   *
   * @param array $base_metadata
   *   The base metadata containing subject, education level, difficulty, cognitive goal.
   *
   * @return string
   *   The formatted AI prompt for step 2.
   */
  protected function buildTopicFromMetadataPrompt(array $base_metadata): string {
    return "Generate an educational quiz topic that perfectly fits the following metadata requirements:

Subject: {$base_metadata['subject']['label']}
Education Level: {$base_metadata['education_level']['label']}
Difficulty: {$base_metadata['difficulty']['label']}
Cognitive Goal: {$base_metadata['cognitive_goal']['label']}

The topic should:
1. Be appropriate for the specified education level
2. Match the difficulty level (Easy = basic concepts, Medium = intermediate understanding, Hard = advanced/complex concepts)
3. Align with the cognitive goal (Remember = factual recall, Understand = comprehension, Apply = using knowledge, Analyze = breaking down concepts, Evaluate = making judgments, Create = producing new content)
4. Fit within the subject area
5. Be specific enough for focused quiz questions

Examples of appropriate topics:
- For Science + Grade 3-6 + Easy + Remember: \"parts of a plant\"
- For Mathematics & Statistics + Grade 9-12 + Medium + Apply: \"solving quadratic equations\"
- For Social Sciences + Undergraduate + Hard + Analyze: \"economic factors in the great depression\"

Generate only the topic text. Do not include quotes, explanations, or additional formatting.";
  }

  /**
   * Builds the AI prompt for generating both a quiz prompt and title from a topic.
   *
   * @param string $quiz_topic
   *   The quiz topic.
   * @param array $base_metadata
   *   The base metadata for context.
   *
   * @return string
   *   The formatted AI prompt for step 3.
   */
  protected function buildPromptFromTopicPrompt(string $quiz_topic, array $base_metadata): string {
    return "Create a quiz prompt and title for the topic: \"{$quiz_topic}\"

Generate TWO items in this exact format:

PROMPT: [Write a concise quiz prompt that defines what knowledge will be tested and the scope of content. Write professionally for educators. Keep under 256 words.]

TITLE: [Write a concise, engaging title (max 100 characters) that reflects the content and is appropriate for the education level. Incorporate the cognitive goal when possible (e.g., \"Understanding...\", \"Analyzing...\", \"Applying...\")]

Examples:

Topic \"canadian provinces\":
PROMPT: Test knowledge of Canada's provinces including capitals, geography, and key facts.
TITLE: Understanding Canadian Provinces

Topic \"basic algebra\":
PROMPT: Cover linear equations, variables, and fundamental algebraic concepts.
TITLE: Applying Basic Algebra

Topic \"world war ii\":
PROMPT: Examine major events, key figures, causes, and global impact of WWII.
TITLE: Analyzing World War II

Context:
- Subject: {$base_metadata['subject']['label']}
- Education Level: {$base_metadata['education_level']['label']}
- Difficulty: {$base_metadata['difficulty']['label']}
- Cognitive Goal: {$base_metadata['cognitive_goal']['label']}

Generate only the PROMPT and TITLE lines as shown above. No additional formatting, quotes, or explanations.";
  }



  /**
   * Logs completion data to JSON file.
   *
   * @param string $request
   *   The request message.
   * @param string $response
   *   The AI response.
   * @param string $model
   *   The model used.
   * @param int $input_tokens
   *   Number of input tokens.
   * @param int $output_tokens
   *   Number of output tokens.
   * @param int $total_tokens
   *   Total tokens used.
   * @param float $temperature
   *   Temperature setting used.
   * @param int $response_time
   *   Response time in milliseconds.
   */
  protected function logToJsonFile(string $request, string $response, string $model, int $input_tokens, int $output_tokens, int $total_tokens, float $temperature, int $response_time): void {
    try {
      // Read existing data or initialize empty array.
      $data = [];
      if (file_exists($this->jsonFilePath)) {
        $json_content = file_get_contents($this->jsonFilePath);
        if ($json_content !== false) {
          $data = json_decode($json_content, true) ?? [];
        }
      }
      
      // Get the next ID.
      $next_id = empty($data) ? 1 : max(array_column($data, 'id')) + 1;
      
      // Create new completion entry.
      $completion = [
        'id' => $next_id,
        'request' => $request,
        'response' => $response,
        'model' => $model,
        'input_tokens' => $input_tokens,
        'output_tokens' => $output_tokens,
        'total_tokens' => $total_tokens,
        'temperature' => $temperature,
        'response_time' => $response_time,
        'created' => date('c'), // ISO 8601 timestamp
        'type' => 'metadata_generation',
      ];
      
      // Add to data array.
      $data[] = $completion;
      
      // Write back to file.
      $json_string = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      if ($json_string !== false) {
        file_put_contents($this->jsonFilePath, $json_string);
      }
      
    } catch (\Exception $e) {
      $this->logger->error('Error writing metadata completion to JSON file: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Cleans AI response by removing markdown code blocks and extracting first valid JSON.
   *
   * @param string $response
   *   The raw AI response.
   *
   * @return string
   *   The cleaned response with only the first valid JSON object.
   */
  protected function cleanJsonResponse(string $response): string {
    // Remove markdown code blocks (```json ... ``` or ``` ... ```)
    $cleaned = preg_replace('/```(?:json)?\s*\n?/m', '', $response);
    $cleaned = preg_replace('/\n?```\s*/m', '', $cleaned);
    
    // Remove any leading/trailing whitespace
    $cleaned = trim($cleaned);
    
    // Remove any extra backticks that might be left
    $cleaned = trim($cleaned, '`');
    
    // If we have multiple JSON objects, extract just the first one
    if (substr_count($cleaned, '{') > 1) {
      // Find the first complete JSON object
      $brace_count = 0;
      $start_pos = strpos($cleaned, '{');
      
      if ($start_pos !== false) {
        for ($i = $start_pos; $i < strlen($cleaned); $i++) {
          if ($cleaned[$i] === '{') {
            $brace_count++;
          } elseif ($cleaned[$i] === '}') {
            $brace_count--;
            if ($brace_count === 0) {
              // Found the end of the first JSON object
              $cleaned = substr($cleaned, $start_pos, $i - $start_pos + 1);
              break;
            }
          }
        }
      }
    }
    
    return trim($cleaned);
  }

  /**
   * Estimates token count for a given text.
   *
   * This is a rough estimation based on the rule that 1 token ≈ 4 characters.
   * For more accurate token counting, you would need to use the specific
   * tokenizer for the model being used.
   *
   * @param string $text
   *   The text to estimate tokens for.
   *
   * @return int
   *   Estimated number of tokens.
   */
  protected function estimateTokens(string $text): int {
    // Rough estimation: 1 token ≈ 4 characters for English text.
    return (int) ceil(strlen($text) / 4);
  }

}
