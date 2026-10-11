<?php
declare(strict_types=1);

return [
    'api_key' => getenv('OPENAI_API_KEY') ?: '',
    'model' => getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini',
    'endpoint' => 'https://api.openai.com/v1/responses',
    'timeout' => 45,
];
