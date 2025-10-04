# Quiz Generator Module

A Drupal 10 module that generates AI-powered quiz content with intelligent metadata classification and node creation capabilities.

## Features

- **AI-Powered Metadata Generation**: Automatically generates quiz topics, titles, and educational metadata using AI
- **Quiz Node Creation**: Creates Quiz content nodes with comprehensive field population
- **Taxonomy Integration**: Validates and assigns proper taxonomy terms for subjects, education levels, difficulty, and cognitive goals
- **Comprehensive Test Suite**: User-friendly admin interface for testing all functionality
- **JSON Logging**: Tracks all AI metadata generation requests with detailed metrics
- **Configurable AI Settings**: Flexible configuration for different AI providers and models

## Core Services

### QuizMetadataService
Generates complete quiz metadata including:
- Educational quiz topics and detailed prompts
- Engaging titles (max 100 characters)
- Subject classification (Arts & Humanities, Science, Mathematics, etc.)
- Education level assignment (Pre-K through Graduate)
- Difficulty levels (Easy, Medium, Hard)
- Cognitive goals based on Bloom's taxonomy (Remember, Understand, Apply, Analyze, Evaluate, Create)

### QuizNodeService
Creates and manages Quiz nodes with:
- Automatic metadata integration
- Taxonomy field population
- Author assignment and publishing control
- Both manual and AI-generated node creation methods

## Requirements

- Drupal 10.2+ or Drupal 11+
- AI module (ai:ai)
- An AI provider configured (e.g., OpenAI)
- Quiz content type with required fields:
  - `field_quiz_prompt` (Text, long plain)
  - `field_subject` (Entity reference to subjects vocabulary)
  - `field_education_level` (Entity reference to grade_levels vocabulary)
  - `field_difficulty` (Entity reference to difficulty vocabulary)
  - `field_cognitive_goal` (Entity reference to cognitive_goal vocabulary)

## Installation

1. Place this module in `/web/modules/custom/quizgen/`
2. Enable the module: `drush en quizgen`
3. Ensure you have the AI module installed and configured with a provider
4. Create the Quiz content type with required fields and taxonomy vocabularies

## Usage

### Admin Interface

Access the Quiz Generator admin interface at `/admin/config/development/quizgen`:

- **Settings**: Configure AI provider, model, and generation parameters
- **Test Node Creation**: Create test Quiz nodes with predefined values
- **Test Metadata Generation**: Generate random educational quiz metadata
- **Test AI Node Creation**: Create complete Quiz nodes with AI-generated metadata

### Programmatic Usage

#### Generate Quiz Metadata
```php
$metadata_service = \Drupal::service('quizgen.metadata_service');
$metadata = $metadata_service->generateQuizMetadata();

// Returns structured metadata with taxonomy term IDs:
// {
//   "title": "Advanced Calculus Problem Set",
//   "prompt": "Create challenging calculus problems focusing on...",
//   "subject": {"id": 12, "label": "Mathematics"},
//   "education_level": {"id": 6, "label": "Grade 9-12"},
//   "difficulty": {"id": 23, "label": "Hard"},
//   "cognitive_goal": {"id": 27, "label": "Apply"}
// }
```

#### Create Quiz Nodes
```php
$node_service = \Drupal::service('quizgen.node_service');

// Create test node
$node = $node_service->createTestQuizNode();

// Create AI-generated node
$node = $node_service->createAiGeneratedQuizNode();
```

## Configuration

### AI Settings
Configure at `/admin/config/development/quizgen/settings`:
- AI Provider ID (e.g., openai, anthropic)
- Model selection (e.g., gpt-4o, claude-3)
- Temperature and token limits
- Default generation parameters

### Taxonomy Requirements

The module requires these taxonomy vocabularies with specific term IDs:

**Subjects (IDs 9-20)**: Arts & Humanities, Business & Economics, Computer Science & Technology, Health & Medicine, Language & Literature, Mathematics, Natural Sciences, Physical Sciences, Social Sciences, Engineering, History & Geography, Philosophy & Ethics

**Grade Levels (IDs 1-8)**: Pre-K to Grade 3, Grade 3-6, Grade 6-8, Grade 9-12, Undergraduate, Graduate, Professional Development, Adult Education

**Difficulty (IDs 21-23)**: Easy, Medium, Hard

**Cognitive Goals (IDs 24-29)**: Remember, Understand, Apply, Analyze, Evaluate, Create

## Logging

### Drupal Logs
All operations are logged to the 'quizgen' log channel:
- Admin UI: `/admin/reports/dblog`
- Drush: `drush watchdog:show --filter=quizgen`

### JSON Metadata Log
AI metadata generation requests are saved to `data/metadata_completions.json` with detailed metrics including token usage, response times, and generated content.

## Architecture

The module follows Drupal best practices with:
- Dependency injection for all services
- Comprehensive error handling and logging
- Strict taxonomy validation
- Configurable AI integration
- User-friendly test interfaces

## License

This module follows the same license as Drupal core.
