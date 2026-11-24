<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Saves an in-app notification and (if configured) sends an FCM push.
 *
 * FCM is optional: put your Firebase service-account JSON path in
 * FIREBASE_CREDENTIALS (.env). Without it, notifications are only stored
 * in the database and shown in the app's notification list.
 */
class Notifier
{
    public static function send(int|User|null $to, string $title, string $body, string $slug = '', ?int $senderId = null, ?int $refId = null): void
    {
        $user = $to instanceof User ? $to : ($to ? User::find($to) : null);
        if (! $user) {
            return;
        }

        AppNotification::create([
            'user_id' => $user->id,
            'sender_id' => $senderId,
            'title' => $title,
            'body' => $body,
            'slug' => $slug,
        ]);

        if ($user->firebase_token) {
            self::push($user->firebase_token, $title, $body, ['type' => $slug, 'id' => (string) ($refId ?? ''), 'url' => '']);
        }
    }

    /** Notify every HR/admin user (e.g. new leave request). */
    public static function hr(string $title, string $body, string $slug = '', ?int $senderId = null, ?int $refId = null): void
    {
        User::where('is_active', true)->where(fn ($q) => $q->where('is_hr', true)->orWhere('is_admin', true))
            ->where('id', '!=', $senderId)
            ->each(fn ($u) => self::send($u, $title, $body, $slug, $senderId, $refId));
    }

    public static function push(string $token, string $title, string $body, array $data = []): void
    {
        $path = env('FIREBASE_CREDENTIALS');
        if (! $path || ! is_file($path)) {
            return;
        }

        try {
            $sa = json_decode(file_get_contents($path), true);
            $accessToken = Cache::remember('fcm_access_token', 3000, function () use ($sa) {
                $now = time();
                $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
                $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                    'iss' => $sa['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]));
                openssl_sign($jwt, $sig, $sa['private_key'], 'sha256WithRSAEncryption');

                return Http::asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt.'.'.$b64($sig),
                ])->json('access_token');
            });

            Http::withToken($accessToken)->post("https://fcm.googleapis.com/v1/projects/{$sa['project_id']}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data + ['title' => $title, 'body' => $body, 'image' => '']),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('FCM push failed: '.$e->getMessage());
        }
    }
}
