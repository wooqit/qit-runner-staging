<?php

namespace CI_CLI;

class PluginDownloadException extends \RuntimeException {
	private string $failure_code;
	private string $failure_stage;

	public function __construct( string $message, string $failure_code, string $failure_stage = 'plugin_download' ) {
		parent::__construct( $message );
		$this->failure_code  = $failure_code;
		$this->failure_stage = $failure_stage;
	}

	/** @return array{stage:string,code:string,message:string} */
	public function get_failure(): array {
		return [
			'stage'   => $this->failure_stage,
			'code'    => $this->failure_code,
			'message' => $this->getMessage(),
		];
	}
}
