<?php

namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Msg91OtpService
{
    public function verifyAccessToken(string $accessToken): ?array
    {
        $res = $this->verifyWithDetails($accessToken);
        return $res['success'] ? $res['data'] : null;
    }

    public function verifyWithDetails(string $accessToken): array
    {
        $authKey = config('services.msg91.auth_key');

        if (empty($authKey)) {
            return [
                'success' => false,
                'message' => 'MSG91_AUTH_KEY is not configured in .env',
                'code' => 500,
            ];
        }

        if (trim($accessToken) === '') {
            return [
                'success' => false,
                'message' => 'Access token cannot be empty',
                'code' => 400,
            ];
        }

        try {
           $response = Http::acceptJson()
    ->asJson()
    ->timeout(15)
                ->post(
                    'https://control.msg91.com/api/v5/widget/verifyAccessToken',
                    [
                        'authkey' => $authKey,
                        'access-token' => $accessToken,
                    ]
                );

            $result = $response->json();

            if (!$response->successful()) {
           Log::warning('MSG91 token verification failed', [
    'http_status' => $response->status(),
]);

                return [
                    'success' => false,
                    'message' => 'MSG91 HTTP error: ' . $response->status(),
                    'code' => $response->status(),
                    'details' => $result,
                ];
            }

            if (
                !is_array($result) ||
                strtolower((string) ($result['type'] ?? '')) !== 'success'
            ) {
                $msg = is_array($result) ? ($result['message'] ?? 'Rejected by MSG91') : 'Invalid response';
                $code = is_array($result) ? ($result['code'] ?? null) : null;

                Log::warning('MSG91 rejected the access token', [
                    'response_type' => is_array($result)
                        ? ($result['type'] ?? null)
                        : null,
                    'response_code' => $code,
                    'response_message' => $msg,
                ]);

                return [
                    'success' => false,
                    'message' => "MSG91 rejected token: {$msg} (Code {$code})",
                    'code' => $code,
                    'details' => $result,
                ];
            }

            return [
                'success' => true,
                'message' => 'Verified successfully',
                'data' => $result,
            ];
        } catch (\Throwable $e) {
            Log::warning('MSG91 verification request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Request failed: ' . $e->getMessage(),
                'code' => 500,
            ];
        }
    }
}
