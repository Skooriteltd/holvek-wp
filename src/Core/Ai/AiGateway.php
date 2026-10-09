<?php

namespace Holvek\WordPress\Core\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Security\KeyVault;
use Holvek\WordPress\Core\Api\BackendApiClient;
use Holvek\WordPress\Modules\Ai\UsageMeteringManager;

class AiGateway {

	private BackendApiClient $apiClient;

	public function __construct( ?BackendApiClient $apiClient = null ) {
		$this->apiClient = $apiClient ?: new BackendApiClient();
	}

	/**
	 * Encrypt sensitive data using KeyVault.
	 *
	 * Callers must not store the result unless it is ciphertext.
	 *
	 * @return string|\WP_Error
	 */
	public function encrypt( string $data ): string|\WP_Error {
		return KeyVault::encrypt( $data );
	}

	/**
	 * Decrypt sensitive data using KeyVault.
	 */
	public function decrypt( string $data ): string {
		return KeyVault::decrypt( $data );
	}

	/**
	 * Route generative prompts dynamically to the Backend API (cloud) or selected BYOK provider.
	 */
	public function queryModel( string $prompt, string $systemPrompt = 'You are a helpful SEO writing assistant.' ): array {
		// 1. If Backend API key is configured, offload heavy computation to Backend API
		if ( $this->apiClient->isConfigured() ) {
			$remoteResult = $this->apiClient->generateAi(
				array(
					'prompt'        => $prompt,
					'system_prompt' => $systemPrompt,
				)
			);

			if ( is_wp_error( $remoteResult ) ) {
				if ( 'holvek_not_connected' !== $remoteResult->get_error_code() ) {
					return array(
						'success' => false,
						'message' => $remoteResult->get_error_message(),
					);
				}
			} elseif ( ! empty( $remoteResult['success'] ) ) {
				return array(
					'success' => true,
					'text'    => $remoteResult['data']['text'] ?? '',
				);
			} elseif ( empty( $remoteResult['fallback'] ) ) {
				return $remoteResult;
			}
		}

		// 2. BYOK (Bring Your Own Key) Fallback Mode
		$provider = get_option( 'holvek_ai_provider' );
		if ( ! $provider ) {
			$provider = get_option( 'holvek_ai_provider', 'openai' );
		}

		$userId   = get_current_user_id();
		$metering = class_exists( UsageMeteringManager::class ) ? UsageMeteringManager::getInstance() : null;
		if ( $userId && $metering && ! $metering->checkLimit( $userId, $provider ) ) {
			return array(
				'success' => false,
				'message' => __( 'AI token usage limit exceeded for this billing cycle.', 'holvek' ),
			);
		}

		$result = match ( $provider ) {
			'openai'    => $this->queryOpenAi( $prompt, $systemPrompt ),
			'anthropic' => $this->queryAnthropic( $prompt, $systemPrompt ),
			'ollama'    => $this->queryOllama( $prompt, $systemPrompt ),
			default     => array(
				'success' => false,
				'message' => __( 'Invalid AI provider selected.', 'holvek' ),
			),
		};

		if ( $userId && $metering && ! empty( $result['success'] ) ) {
			$tokensUsed = (int) ceil( ( strlen( $prompt . $systemPrompt ) + strlen( $result['text'] ?? '' ) ) / 4 );
			$metering->record( $userId, $provider, 'content_assistant', $tokensUsed );
		}

		return $result;
	}

	/**
	 * Query OpenAI Chat Completions API.
	 */
	private function queryOpenAi( string $prompt, string $systemPrompt ): array {
		$encryptedKey = get_option( 'holvek_openai_key', '' );
		if ( empty( $encryptedKey ) ) {
			$encryptedKey = get_option( 'holvek_openai_api_key', '' );
		}
		if ( empty( $encryptedKey ) ) {
			$encryptedKey = get_option( 'holvek_openai_key', '' );
		}
		if ( empty( $encryptedKey ) ) {
			$encryptedKey = get_option( 'holvek_openai_api_key', '' );
		}
		$apiKey = KeyVault::decrypt( $encryptedKey );

		if ( empty( $apiKey ) ) {
			return array(
				'success' => false,
				'message' => __( 'OpenAI API key is missing or not configured.', 'holvek' ),
			);
		}

		$model = get_option( 'holvek_openai_model' );
		if ( ! $model ) {
			$model = get_option( 'holvek_openai_model', 'gpt-4o-mini' );
		}

		$url  = 'https://api.openai.com/v1/chat/completions';
		$body = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $systemPrompt,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0.7,
			'max_tokens'  => 1000,
		);

		$response = wp_safe_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = wp_remote_retrieve_body( $response );
		$data         = json_decode( $responseBody, true );

		if ( $responseCode !== 200 ) {
			$errMsg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'OpenAI API Error.', 'holvek' );
			return array(
				'success' => false,
				'message' => $errMsg,
			);
		}

		$content = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		return array(
			'success' => true,
			'text'    => trim( $content ),
		);
	}

	/**
	 * Query Anthropic Messages API.
	 */
	private function queryAnthropic( string $prompt, string $systemPrompt ): array {
		$encryptedKey = get_option( 'holvek_anthropic_key', '' );
		if ( empty( $encryptedKey ) ) {
			$encryptedKey = get_option( 'holvek_anthropic_api_key', '' );
		}
		if ( empty( $encryptedKey ) ) {
			$encryptedKey = get_option( 'holvek_anthropic_key', '' );
		}
		if ( empty( $encryptedKey ) ) {
			$encryptedKey = get_option( 'holvek_anthropic_api_key', '' );
		}
		$apiKey = KeyVault::decrypt( $encryptedKey );

		if ( empty( $apiKey ) ) {
			return array(
				'success' => false,
				'message' => __( 'Anthropic API key is missing or not configured.', 'holvek' ),
			);
		}

		$model = get_option( 'holvek_anthropic_model' );
		if ( ! $model ) {
			$model = get_option( 'holvek_anthropic_model', 'claude-3-5-sonnet-20241022' );
		}

		$url  = 'https://api.anthropic.com/v1/messages';
		$body = array(
			'model'       => $model,
			'max_tokens'  => 1000,
			'system'      => $systemPrompt,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0.7,
		);

		$response = wp_safe_remote_post(
			$url,
			array(
				'headers' => array(
					'x-api-key'         => $apiKey,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = wp_remote_retrieve_body( $response );
		$data         = json_decode( $responseBody, true );

		if ( $responseCode !== 200 ) {
			$errMsg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Anthropic API Error.', 'holvek' );
			return array(
				'success' => false,
				'message' => $errMsg,
			);
		}

		$content = isset( $data['content'][0]['text'] ) ? $data['content'][0]['text'] : '';
		return array(
			'success' => true,
			'text'    => trim( $content ),
		);
	}

	/**
	 * Query local Ollama API.
	 */
	private function queryOllama( string $prompt, string $systemPrompt ): array {
		$ollamaUrl = OllamaEndpoint::requestUrl();
		if ( null === $ollamaUrl ) {
			return array(
				'success' => false,
				'message' => __( 'The Ollama address must be an http or https host. A remote host has to be confirmed because it receives post content.', 'holvek' ),
			);
		}

		$url  = $ollamaUrl . '/api/chat';
		$body = array(
			'model'    => 'llama3',
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $systemPrompt,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'options'  => array(
				'temperature' => 0.7,
			),
			'stream'   => false,
		);

		$response = wp_safe_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf( /* translators: %s: Error message */ __( 'Ollama Connection Error: %s', 'holvek' ), $response->get_error_message() ),
			);
		}

		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = wp_remote_retrieve_body( $response );
		$data         = json_decode( $responseBody, true );

		if ( $responseCode !== 200 ) {
			return array(
				'success' => false,
				'message' => __( 'Ollama local instance returned an error.', 'holvek' ),
			);
		}

		$content = isset( $data['message']['content'] ) ? $data['message']['content'] : '';
		return array(
			'success' => true,
			'text'    => trim( $content ),
		);
	}
}
