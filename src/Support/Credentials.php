<?php

namespace Chattrn\Connector\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class Credentials
{
    private const KEY = '_encrypted_access_token';

    public static function token(): ?string
    {
        if (Settings::tableExists()) {
            $ciphertext = DB::table('chattrn_settings')->where('key', self::KEY)->value('value');
            if ($ciphertext) {
                return Crypt::decryptString($ciphertext);
            }
        }

        return config('chattrn.access_token');
    }

    public static function save(string $token): void
    {
        DB::table('chattrn_settings')->updateOrInsert(['key' => self::KEY], [
            'value' => Crypt::encryptString($token),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config(['chattrn.access_token' => $token]);
    }
}
