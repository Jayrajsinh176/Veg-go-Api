<?php

namespace App\Console\Commands;
use App\Services\Msg91OtpService;
use Illuminate\Console\Command;

class TestMsg91Verification extends Command
{
    protected $signature = 'msg91:test';

    protected $description = 'Test MSG91 access-token verification locally';

    public function handle(Msg91OtpService $msg91): int
    {
        $accessToken = $this->secret(
            'Enter the MSG91 widget access token (input is hidden)'
        );

        if (empty($accessToken)) {
            $this->error('Access token is required.');
            return self::FAILURE;
        }

        $result = $msg91->verifyAccessToken($accessToken);

        if ($result === null) {
            $this->error(
                'Verification failed. Check the MSG91 response and configuration.'
            );

            return self::FAILURE;
        }

        $this->info('MSG91 verification succeeded.');
        $this->line('Response structure:');

        // Show field names and value types only.
        // Do not print phone numbers, tokens, or personal information.
        $this->line(json_encode(
            $this->describeStructure($result),
            JSON_PRETTY_PRINT
        ));

        return self::SUCCESS;
    }

    private function describeStructure(array $data): array
    {
        $structure = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $structure[$key] = $this->describeStructure($value);
            } else {
                $structure[$key] = get_debug_type($value);
            }
        }

        return $structure;
    }
}
