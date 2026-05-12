<?php

declare( strict_types=1 );

namespace DSN\Hawk\Sync;

final class HttpClient {

	public function __construct(
		private string $endpoint,
		private string $token
	) {}

	/**
	 * @return array{ok:bool,code:int,message:string,body:string}
	 */
	public function post( array $payload ): array {
		$body = (string) wp_json_encode( $payload );

		$response = wp_remote_post(
			$this->endpoint,
			[
				'timeout'     => 15,
				'blocking'    => true,
				'headers'     => [
					'Authorization' => 'Bearer ' . $this->token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'User-Agent'    => 'DSN-Hawk/' . DSN_HAWK_VERSION,
				],
				'body'        => $body,
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
				'body'    => '',
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$resp = (string) wp_remote_retrieve_body( $response );

		return [
			'ok'      => $code >= 200 && $code < 300,
			'code'    => $code,
			'message' => $code >= 200 && $code < 300 ? 'ok' : ( 'HTTP ' . $code ),
			'body'    => $resp,
		];
	}
}
