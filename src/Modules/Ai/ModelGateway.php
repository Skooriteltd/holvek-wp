<?php

namespace Holvek\WordPress\Modules\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Ai\AiGateway;

/**
 * ModelGateway: Unified abstraction layer for routing prompts across
 * multiple AI providers, delegating to Core\Ai\AiGateway.
 */
class ModelGateway {

	private AiGateway $gateway;

	public function __construct() {
		$this->gateway = new AiGateway();
	}

	/**
	 * Send a completion prompt to the configured AI gateway.
	 */
	public function complete( string $prompt, array $options = array() ): array {
		$systemPrompt = $options['system'] ?? 'You are a helpful SEO writing assistant.';
		$result       = $this->gateway->queryModel( $prompt, $systemPrompt );

		return array(
			'success'  => $result['success'] ?? false,
			'content'  => $result['text'] ?? '',
			'provider' => get_option( 'holvek_ai_provider', 'openai' ),
			'error'    => $result['message'] ?? null,
		);
	}

	/**
	 * Test connectivity to a provider via AiGateway.
	 */
	public function testConnection( string $provider ): array {
		$result = $this->gateway->queryModel( 'Say "OK" to confirm the connection works.', 'System test.' );
		return array(
			'success'  => $result['success'] ?? false,
			'content'  => $result['text'] ?? '',
			'provider' => $provider,
			'error'    => $result['message'] ?? null,
		);
	}
}
