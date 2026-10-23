<?php

namespace Chattrn\Connector\Console\Commands;

use Illuminate\Console\Command;
use Chattrn\Connector\Support\ChattrnRegistrar;

// Non-interactive version of the registration step chattrn:map does at the end. For an
// environment where config/chattrn.php was already written elsewhere (e.g. committed from a
// local chattrn:map run) and all that's left is the register call itself - useful anywhere an
// interactive TTY isn't available, such as a hosting provider's one-off job runner or a
// deploy hook, instead of needing shell/SSH access just to answer prompts you already answered.
class RegisterCommand extends Command
{
    protected $signature = 'chattrn:register';
    protected $description = 'Register with Chattrn using the already-written config/chattrn.php (no prompts)';

    public function handle(): int
    {
        if (\Chattrn\Connector\Support\Credentials::token()) {
            $this->info('Already connected. Continue with Map your tables in Chattrn.');
            return self::SUCCESS;
        }

        $token = config('chattrn.connection_token');
        if (! $token) {
            $this->error('No CHATTRN_TOKEN found in .env. Paste it in first (from the Install package screen), then re-run chattrn:register.');
            return self::FAILURE;
        }

        $this->line('Registering with Chattrn...');
        $result = ChattrnRegistrar::register($token);
        if (! $result['ok']) {
            $this->error('Register failed: ' . $result['message']);
            return self::FAILURE;
        }

        $this->info('Connected. Access token saved encrypted in your database. Continue with Map your tables in Chattrn.');

        return self::SUCCESS;
    }
}
