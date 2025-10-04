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
   *
   * @return string|null
   *   The AI response text, or NULL on error.
   */
  public function makeAiRequest(string $message = 'hello', string $provider_id = '', string $model = '', float $temperature = 0): ?string {
    $start_time = microtime(true);
    
    // Get configuration settings.
    $config_settings = $this->configFactory->get('quizgen.settings');
    
    // Use provided parameters or fall back to configuration defaults.
    $provider_id = $provider_id ?: ($config_settings->get('provider_id') ?? 'openai');
    $model = $model ?: ($config_settings->get('model') ?? 'gpt-4o');
    $temperature = $temperature ?: ($config_settings->get('temperature') ?? 0.7);
    $max_tokens = $config_settings->get('max_tokens') ?? 4096;
    
    try {
      // Configuration for the AI provider.
      $config = [
        "max_tokens" => $max_tokens,
        "temperature" => $temperature,
        "frequency_penalty" => 0,
        "presence_penalty" => 0,
        "top_p" => 1,
      ];
      // Create a chat input with the provided message.
      $input = new ChatInput([
        new ChatMessage("user", $message),
      ]);

      // Get the AI provider service and create an instance.
      $ai_provider = $this->aiProviderManager->createInstance($provider_id);
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

    // Step 3: Generate the quiz prompt from the topic
    $quiz_prompt = $this->generatePromptFromTopic($quiz_topic, $base_metadata);
    if ($quiz_prompt === null) {
      $this->logger->error('Failed to generate quiz prompt for topic: @topic', [
        '@topic' => $quiz_topic,
      ]);
      return null;
    }

    // Step 4: Generate title from the prompt
    $title = $this->generateTitleFromPrompt($quiz_prompt, $base_metadata);
    if ($title === null) {
      $this->logger->error('Failed to generate title from prompt.');
      return null;
    }

    // Combine all generated content
    $metadata = $base_metadata;
    $metadata['title'] = $title;
    $metadata['prompt'] = $quiz_prompt;

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
      ['id' => 2, 'label' => 'Pre-K to Grade 3'],
      ['id' => 3, 'label' => 'Grade 3-6'],
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
   * Step 3: Generate a quiz prompt from a topic using AI.
   *
   * @param string $quiz_topic
   *   The quiz topic.
   * @param array $base_metadata
   *   The base metadata for context.
   *
   * @return string|null
   *   The generated quiz prompt, or NULL on error.
   */
  protected function generatePromptFromTopic(string $quiz_topic, array $base_metadata): ?string {
    $ai_prompt = $this->buildPromptFromTopicPrompt($quiz_topic, $base_metadata);
    
    $response = $this->makeAiRequest($ai_prompt);
    
    if ($response === null) {
      $this->logger->error('Failed to get AI response for quiz prompt generation.');
      return null;
    }

    // Clean the response and extract just the prompt text
    $cleaned_response = trim($response);
    
    // Remove any markdown formatting or quotes if present
    $cleaned_response = preg_replace('/^["\'`]+|["\'`]+$/m', '', $cleaned_response);
    $cleaned_response = trim($cleaned_response);

    if (empty($cleaned_response)) {
      $this->logger->error('Empty quiz prompt generated from AI response.');
      return null;
    }

    return $cleaned_response;
  }

  /**
   * Step 4: Generate a title from a quiz prompt using AI.
   *
   * @param string $quiz_prompt
   *   The quiz prompt.
   * @param array $base_metadata
   *   The base metadata for context.
   *
   * @return string|null
   *   The generated title, or NULL on error.
   */
  protected function generateTitleFromPrompt(string $quiz_prompt, array $base_metadata): ?string {
    $ai_prompt = $this->buildTitleFromPromptPrompt($quiz_prompt, $base_metadata);
    
    $response = $this->makeAiRequest($ai_prompt);
    
    if ($response === null) {
      $this->logger->error('Failed to get AI response for title generation.');
      return null;
    }

    // Clean the response and extract just the title text
    $cleaned_response = trim($response);
    
    // Remove any markdown formatting or quotes if present
    $cleaned_response = preg_replace('/^["\'`]+|["\'`]+$/m', '', $cleaned_response);
    $cleaned_response = trim($cleaned_response);

    if (empty($cleaned_response)) {
      $this->logger->error('Empty title generated from AI response.');
      return null;
    }

    return $cleaned_response;
  }


  /**
   * Builds the AI prompt for generating random base metadata.
   *
   * @return string
   *   The formatted AI prompt for step 1.
   */
  protected function buildBaseMetadataPrompt(): string {
    // Add some randomness by including timestamp
    $timestamp = time();
    $random_seed = $timestamp % 1000;
    
    return "Generate ONE random educational quiz metadata combination. Use the timestamp seed {$random_seed} to make a varied selection. Pick ONE option from each category to create a single, educationally appropriate combination.

Select ONE term from EACH category below:

SUBJECTS (choose one - vary your selection):
- 9: Arts & Humanities (art history, philosophy, music theory, literature analysis)
- 10: Business & Economics (finance, marketing, economics, entrepreneurship)
- 11: Computer Science & Technology (programming, algorithms, cybersecurity, AI)
- 12: Education (pedagogy, learning theory, curriculum design)
- 13: Health & Medicine (anatomy, nutrition, medical procedures, public health)
- 14: Language & Literature (grammar, poetry, creative writing, linguistics)
- 15: Law & Political Science (constitutional law, government, political theory)
- 16: Mathematics & Statistics (algebra, geometry, calculus, probability)
- 17: Science (biology, chemistry, physics, earth science)
- 18: Social Sciences (psychology, sociology, anthropology, geography)
- 19: Professional Studies (career skills, workplace training, certification prep)
- 20: Other (interdisciplinary, specialized topics)

EDUCATION LEVELS (choose one - mix it up):
- 1: Any Level (general knowledge, broad appeal)
- 2: Pre-K to Grade 3 (early childhood, basic concepts)
- 3: Grade 3-6 (elementary, foundational skills)
- 4: Grade 6-8 (middle school, developing complexity)
- 5: Grade 9-12 (high school, advanced concepts)
- 6: Undergraduate (college-level, specialized knowledge)
- 7: Graduate (advanced study, research-level)
- 8: Adult Learning (professional development, continuing education)

DIFFICULTY LEVELS (choose one - distribute evenly):
- 21: Easy (basic recall, simple concepts, introductory level)
- 22: Medium (moderate complexity, some analysis required)
- 23: Hard (advanced concepts, complex problem-solving)

COGNITIVE GOALS (choose one - rotate through options):
- 24: Remember (recall facts, memorize information)
- 25: Understand (comprehend concepts, explain ideas)
- 26: Apply (use knowledge in new situations)
- 27: Analyze (break down information, examine relationships)
- 28: Evaluate (make judgments, assess quality)
- 29: Create (produce new content, synthesize ideas)

VALID COMBINATIONS EXAMPLES:
- Arts & Humanities + Grade 6-8 + Easy + Remember
- Computer Science + Undergraduate + Hard + Create
- Health & Medicine + Grade 3-6 + Medium + Understand
- Mathematics + Graduate + Hard + Analyze

Respond with EXACTLY this JSON format (replace the values but keep the structure):
{
  \"subject\": {\"id\": 17, \"label\": \"Science\"},
  \"education_level\": {\"id\": 5, \"label\": \"Grade 9-12\"},
  \"difficulty\": {\"id\": 22, \"label\": \"Medium\"},
  \"cognitive_goal\": {\"id\": 25, \"label\": \"Understand\"}
}

CRITICAL REQUIREMENTS:
- Respond with EXACTLY ONE JSON object, no additional text or multiple objects
- Use the exact field names: subject, education_level, difficulty, cognitive_goal
- Each field must have both \"id\" (integer) and \"label\" (string)
- Make educationally appropriate combinations
- DO NOT generate multiple JSON objects - only ONE!";
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
   * Builds the AI prompt for generating a quiz prompt from a topic.
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
    return "You are an experienced educator creating a quiz prompt. Generate a comprehensive, educational quiz prompt based on the following topic.

Topic: \"{$quiz_topic}\"

Your task is to create a detailed quiz prompt that:
1. Clearly defines what knowledge will be tested
2. Specifies the scope and focus of the quiz content
3. Is written in a professional, educational tone
4. Provides enough detail for quiz generation
5. Includes appropriate context and learning objectives

Examples of good quiz prompts:

For topic \"canadian provinces\":
\"Create a comprehensive quiz testing knowledge of Canada's provinces and territories. Include questions about provincial capitals, major geographical features, population centers, economic activities, and historical significance. Focus on factual recall and basic understanding suitable for middle to high school students.\"

For topic \"basic algebra\":
\"Generate an algebra quiz covering fundamental concepts including solving linear equations, working with variables, basic graphing, and simple word problems. Questions should test both computational skills and conceptual understanding of algebraic principles. Appropriate for students who have completed pre-algebra.\"

For topic \"world war ii\":
\"Develop a World War II history quiz examining major events, key figures, causes and consequences, and global impact. Include questions about timeline, battles, political decisions, and social changes. Focus on critical thinking and historical analysis skills appropriate for high school level.\"

Generate only the quiz prompt text. Do not include any additional formatting, quotes, or explanations.

Context for alignment:
- Subject: {$base_metadata['subject']['label']}
- Education Level: {$base_metadata['education_level']['label']}
- Difficulty: {$base_metadata['difficulty']['label']}
- Cognitive Goal: {$base_metadata['cognitive_goal']['label']}

Ensure the prompt matches these specifications.";
  }

  /**
   * Builds the AI prompt for generating a title from a quiz prompt.
   *
   * @param string $quiz_prompt
   *   The quiz prompt.
   * @param array $base_metadata
   *   The base metadata for context.
   *
   * @return string
   *   The formatted AI prompt for step 4.
   */
  protected function buildTitleFromPromptPrompt(string $quiz_prompt, array $base_metadata): string {
    return "Generate a concise, engaging title for a quiz based on the following prompt and metadata.

Quiz Prompt: \"{$quiz_prompt}\"

Metadata Context:
- Subject: {$base_metadata['subject']['label']}
- Education Level: {$base_metadata['education_level']['label']}
- Difficulty: {$base_metadata['difficulty']['label']}
- Cognitive Goal: {$base_metadata['cognitive_goal']['label']}

The title should:
1. Be concise (max 100 characters)
2. Be engaging and clear
3. Reflect the content and scope described in the prompt
4. Be appropriate for the education level
5. Incorporate the cognitive goal when possible (e.g., \"Understanding...\", \"Analyzing...\", \"Applying...\")

Examples:
- \"Understanding Plant Photosynthesis\" (Science, Grade 9-12, Medium, Understand)
- \"Applying Quadratic Equations\" (Mathematics, Grade 9-12, Medium, Apply)
- \"Remembering World Capitals\" (Social Sciences, Grade 6-8, Easy, Remember)

Generate only the title text. Do not include quotes, explanations, or additional formatting.";
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
