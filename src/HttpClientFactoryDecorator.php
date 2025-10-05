<?php

declare(strict_types=1);

namespace Drupal\quizgen;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Decorates the HTTP client factory to provide custom timeout for AI requests.
 */
class HttpClientFactoryDecorator extends ClientFactory {

  /**
   * The decorated client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $clientFactory;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a HttpClientFactoryDecorator object.
   *
   * @param \Drupal\Core\Http\ClientFactory $client_factory
   *   The decorated client factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(ClientFactory $client_factory, ConfigFactoryInterface $config_factory) {
    $this->clientFactory = $client_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function fromOptions(array $config = []) {
    // Get QuizGen timeout setting
    $quizgen_config = $this->configFactory->get('quizgen.settings');
    $custom_timeout = $quizgen_config->get('timeout') ?? 120;

    // Override timeout if not explicitly set and this looks like an AI request
    if (!isset($config['timeout']) || $config['timeout'] <= 60) {
      // Check if this might be an AI-related request by examining the stack trace
      $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
      $is_ai_request = FALSE;
      
      foreach ($backtrace as $frame) {
        if (isset($frame['class']) && 
            (strpos($frame['class'], 'Drupal\\ai\\') === 0 || 
             strpos($frame['class'], 'Drupal\\quizgen\\') === 0)) {
          $is_ai_request = TRUE;
          break;
        }
      }
      
      if ($is_ai_request) {
        $config['timeout'] = $custom_timeout;
      }
    }

    return $this->clientFactory->fromOptions($config);
  }

}
